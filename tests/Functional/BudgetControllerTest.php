<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Budget\BudgetService;
use App\Entity\User;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Budgets screen over HTTP: page states, set / edit / delete dialogs (HTMX and plain form
 * posts), validation and account isolation.
 */
final class BudgetControllerTest extends WebTestCase
{
    use Accounts;
    use ClockSensitiveTrait;
    use ResetsDatabase;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        self::mockTime('2026-11-15 12:00:00');
        $this->client = self::createClient();
        self::resetDatabase();
        $this->user = self::createAccount('owner@example.com');
        $this->client->loginUser($this->user);
    }

    private function budgets(): BudgetService
    {
        return self::getContainer()->get(BudgetService::class);
    }

    /** CSRF token as rendered in a dialog. */
    private function token(string $url): string
    {
        $crawler = $this->client->request('GET', $url, server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function htmxPost(string $url, array $data): void
    {
        $this->client->request('POST', $url, $data, server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_CURRENT_URL' => 'http://localhost/budgets?month=2026-11']);
    }

    public function testAnonymousIsRedirectedToSignIn(): void
    {
        $this->client->request('GET', '/logout'); // not signed in for the next request
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/budgets');
        self::assertResponseRedirects('/login');
    }

    public function testEmptyMonth(): void
    {
        $this->client->request('GET', '/budgets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty__title', 'No budgets for November 2026');
        self::assertSelectorTextContains('.month-picker__label', 'Current month');
        self::assertSelectorExists('button[data-month-step="-1"][disabled]'); // new account: nothing earlier
        self::assertSelectorExists('a[data-month-step="1"][href="/budgets?month=2026-12"]');
    }

    public function testShowsAllThreeStatesAndOverBanner(): void
    {
        $u = $this->user;
        self::addTransaction($u, 'Dining out', 18640, '2026-11-04');
        self::addTransaction($u, 'Transport', 9800, '2026-11-03');
        self::addTransaction($u, 'Groceries', 14235, '2026-11-02');
        $this->budgets()->setOverall($u, '2026-11', 320000);
        $this->budgets()->setCategoryLimit($u, '2026-11', self::category($u, 'Dining out'), 15000);
        $this->budgets()->setCategoryLimit($u, '2026-11', self::category($u, 'Transport'), 12000);
        $this->budgets()->setCategoryLimit($u, '2026-11', self::category($u, 'Groceries'), 45000);

        $crawler = $this->client->request('GET', '/budgets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--error .alert__title', 'Dining out is over budget by $36.40 this month.');
        self::assertSame(['Dining out', 'Transport', 'Groceries'], $crawler->filter('.budget-grid .budget__name')->each(static fn ($n) => $n->text()));
        self::assertSelectorTextContains('.budget--over .budget__note', 'Over by $36.40');
        self::assertSelectorTextContains('.budget--warning .budget__note', '$22.00 left');
        self::assertSelectorTextContains('.budget--warning .bud-status__pct', '82% used');
        self::assertSelectorTextContains('.budget-grid .budget--ok .budget__note', '$307.65 left');
        self::assertSelectorTextContains('.bud-summary', '1 over budget');
        self::assertSelectorTextContains('.budget-card--overall', 'Spent $426.75 of $3,200.00');
        self::assertSelectorTextContains('.budget-card--overall', '$2,773.25');
    }

    public function testPreviousMonthShowsCarriedOverLimitsAndClosedMonthCopy(): void
    {
        $u = $this->user;
        $this->budgets()->setCategoryLimit($u, '2026-09', self::category($u, 'Dining out'), 15000);
        self::addTransaction($u, 'Dining out', 21260, '2026-10-10');

        $this->client->request('GET', '/budgets?month=2026-10');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.month-picker__label', 'October 2026');
        self::assertSelectorTextContains('.alert__title', 'Dining out went over budget by $62.60 in October 2026.');
        self::assertSelectorTextContains('.bud-legend', 'Limits carried over from September 2026');
        // out-of-range months are clamped to the picker range
        $this->client->request('GET', '/budgets?month=2030-01');
        self::assertSelectorTextContains('.month-picker__label', 'December 2026');
    }

    public function testSetCategoryBudgetViaHtmx(): void
    {
        $dining = self::category($this->user, 'Dining out');
        $crawler = $this->client->request('GET', '/budgets/new?month=2026-11&scope=category', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#budget-form[data-open-on-load]');
        self::assertCount(12, $crawler->filter('#bf-category option[value!=""]'), 'all expense categories, no income ones');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->htmxPost('/budgets/new?month=2026-11', ['_token' => $token, 'scope' => 'category', 'category' => (string) $dining->getId(), 'limit' => '150']);

        self::assertResponseIsSuccessful();
        $trigger = json_decode((string) $this->client->getResponse()->headers->get('HX-Trigger'), true);
        self::assertSame('Dining out budget set', $trigger['em:toast']['title']);
        self::assertSame('$150.00 limit for November 2026.', $trigger['em:toast']['message']);
        self::assertSame(15000, $this->budgets()->forMonth($this->user, '2026-11')->forCategoryId((int) $dining->getId())?->limit);

        // the category is no longer offered
        $crawler = $this->client->request('GET', '/budgets/new?month=2026-11', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertCount(0, $crawler->filter('#bf-category option[value="'.$dining->getId().'"]'));
        $this->htmxPost('/budgets/new?month=2026-11', ['_token' => $token, 'scope' => 'category', 'category' => (string) $dining->getId(), 'limit' => '99']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#bf-category-error', 'Choose a category');
    }

    public function testSetOverallWithoutJavascriptRedirectsWithFlash(): void
    {
        $crawler = $this->client->request('GET', '/budgets/new?month=2026-11&scope=overall');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.page-title'); // full page with the dialog
        $form = $crawler->filter('#bf-form')->form(['limit' => '3,200']);
        $this->client->submit($form);

        self::assertResponseRedirects('/budgets?month=2026-11', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast__title', 'Overall budget set');
        self::assertSelectorTextContains('.budget-card--overall', 'Spent $0.00 of $3,200.00');
        // a second overall budget can't be added
        $token = $this->token('/budgets/new?month=2026-11');
        $this->htmxPost('/budgets/new?month=2026-11', ['_token' => $token, 'scope' => 'overall', 'limit' => '10']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testValidationErrorsKeepTheDialog(): void
    {
        $token = $this->token('/budgets/new?month=2026-11');
        $groceries = (string) self::category($this->user, 'Groceries')->getId();

        foreach (['' => 'Enter a monthly limit', '0' => 'Enter a limit greater than 0', 'abc' => 'Enter a limit greater than 0', '100000000' => 'Enter a limit below $100,000,000'] as $input => $message) {
            $this->htmxPost('/budgets/new?month=2026-11', ['_token' => $token, 'scope' => 'category', 'category' => $groceries, 'limit' => (string) $input]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#bf-limit-error', $message);
            self::assertSelectorExists('#bf-limit[aria-invalid="true"]');
        }
        $this->htmxPost('/budgets/new?month=2026-11', ['_token' => 'bad', 'scope' => 'category', 'category' => $groceries, 'limit' => '10']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->budgets()->forMonth($this->user, '2026-11')->isEmpty(), 'nothing saved');
    }

    public function testEditAndDeleteOnlyAffectTheSelectedMonth(): void
    {
        $u = $this->user;
        $transport = self::category($u, 'Transport');
        $this->budgets()->setCategoryLimit($u, '2026-10', $transport, 12000);
        $this->budgets()->setOverall($u, '2026-10', 300000);
        $id = (string) $transport->getId();

        $crawler = $this->client->request('GET', "/budgets/$id/edit?month=2026-11", server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertSelectorTextContains('#bf-title', 'Edit Transport budget');
        self::assertSame('120.00', $crawler->filter('#bf-limit')->attr('value'));
        $this->htmxPost("/budgets/$id/edit?month=2026-11", ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'limit' => '110']);
        self::assertResponseIsSuccessful();
        self::assertSame('New limit $110.00 for November 2026.', json_decode((string) $this->client->getResponse()->headers->get('HX-Trigger'), true)['em:toast']['message']);

        $token = $this->token('/budgets/overall/delete?month=2026-11');
        self::assertSelectorTextContains('#bd-title', 'Remove the overall monthly budget for November 2026?');
        $this->htmxPost('/budgets/overall/delete?month=2026-11', ['_token' => $token]);
        self::assertResponseIsSuccessful();

        $nov = $this->budgets()->forMonth($u, '2026-11');
        self::assertNull($nov->overall);
        self::assertSame(11000, $nov->forCategoryId((int) $id)?->limit);
        $oct = $this->budgets()->forMonth($u, '2026-10');
        self::assertSame(300000, $oct->overall?->limit, 'October keeps its overall budget');
        self::assertSame(12000, $oct->forCategoryId((int) $id)?->limit, 'October keeps its limit');

        // editing / deleting a budget that doesn't exist in the month → 404
        $this->client->request('GET', '/budgets/overall/edit?month=2026-11');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherAccountsBudgetsAndCategoriesAreOffLimits(): void
    {
        $other = self::createAccount('other@example.com');
        $otherDining = self::category($other, 'Dining out');
        $this->budgets()->setCategoryLimit($other, '2026-11', $otherDining, 15000);
        $this->budgets()->setOverall($other, '2026-11', 500000);
        $oid = (string) $otherDining->getId();

        $this->client->request('GET', '/budgets');
        self::assertSelectorTextContains('.empty__title', 'No budgets for November 2026', 'other account’s budgets are not visible');

        foreach (["/budgets/$oid/edit?month=2026-11", "/budgets/$oid/delete?month=2026-11", '/budgets/overall/edit?month=2026-11'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $token = $this->token('/budgets/new?month=2026-11');
        $this->htmxPost('/budgets/new?month=2026-11', ['_token' => $token, 'scope' => 'category', 'category' => $oid, 'limit' => '1']);
        self::assertResponseStatusCodeSame(422);
        $this->htmxPost("/budgets/$oid/delete?month=2026-11", ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(15000, $this->budgets()->forMonth($other, '2026-11')->forCategoryId((int) $oid)?->limit, 'untouched');
        self::assertTrue($this->budgets()->forMonth($this->user, '2026-11')->isEmpty());
    }
}
