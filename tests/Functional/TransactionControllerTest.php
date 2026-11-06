<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Transactions screen and dialogs, exercised over HTTP like a browser (with and without HTMX).
 */
final class TransactionControllerTest extends WebTestCase
{
    use Accounts;
    use ResetsDatabase;

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        $this->owner = self::createAccount('owner@example.com');
        $this->client->loginUser($this->owner);
    }

    public function testAnonymousIsRedirectedToSignIn(): void
    {
        $anon = $this->client;
        $anon->getCookieJar()->clear();
        $anon->request('GET', '/transactions');

        self::assertResponseRedirects('/login');
    }

    public function testCreateViaPlainFormThenListed(): void
    {
        $crawler = $this->client->request('GET', '/transactions/new?type=expense');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#tf-title', 'Add transaction');

        $groceries = self::category($this->owner, 'Groceries');
        $form = $crawler->filter('#tx-form')->form([
            'amount' => '1,234.50',
            'category' => (string) $groceries->getId(),
            'date' => date('Y').'-03-15',
            'note' => 'Weekly groceries — FreshMart',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/transactions', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#em-toasts .toast', 'Transaction added');
        self::assertSelectorTextContains('#em-toasts .toast', '−$1,234.50 · Groceries');
        self::assertSelectorTextContains('#tx-card tbody', 'Weekly groceries — FreshMart');

        $tx = $this->onlyTransaction();
        self::assertSame(123450, $tx->getAmountMinor());
        self::assertSame(TransactionType::Expense, $tx->getType());
        self::assertSame(date('Y').'-03-15', $tx->getDate()->format('Y-m-d'));
    }

    public function testCreateViaHtmxReturnsToastAndReloadInstructions(): void
    {
        $this->client->request('GET', '/transactions/new?type=income', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        // Fragment only: the dialog, no page shell.
        self::assertSelectorExists('#tx-modal[data-open-on-load][data-dispose]');
        self::assertSelectorNotExists('.sidebar');
        self::assertSelectorExists('#tx-form input[name="type"][value="income"][checked]');
        self::assertSelectorTextContains('#tf-category', 'Salary');
        self::assertSelectorTextNotContains('#tf-category', 'Groceries');

        $token = $this->client->getCrawler()->filter('#tx-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/transactions/new', [
            '_token' => $token, 'type' => 'income', 'amount' => '350',
            'category' => (string) self::category($this->owner, 'Freelance', TransactionType::Income)->getId(),
            'date' => date('Y-m-d'), 'note' => '',
        ], server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_CURRENT_URL' => 'http://localhost/transactions?type=income']);

        self::assertResponseIsSuccessful();
        $r = $this->client->getResponse();
        $trigger = json_decode((string) $r->headers->get('HX-Trigger'), true);
        self::assertSame('Transaction added', $trigger['em:toast']['title']);
        self::assertSame('+$350.00 · Freelance', $trigger['em:toast']['message']);
        self::assertSame('/transactions?type=income', json_decode((string) $r->headers->get('HX-Location'), true)['path']);
        self::assertNull($this->onlyTransaction()->getNote());
    }

    public function testValidationErrorsReRenderDialogWith422(): void
    {
        $this->client->request('GET', '/transactions/new', server: ['HTTP_HX_REQUEST' => 'true']);
        $token = $this->client->getCrawler()->filter('#tx-form input[name="_token"]')->attr('value');
        $income = self::category($this->owner, 'Salary', TransactionType::Income);

        // Expense with an income category, zero amount, bad date, too long note.
        $this->client->request('POST', '/transactions/new', [
            '_token' => $token, 'type' => 'expense', 'amount' => '0', 'category' => (string) $income->getId(),
            'date' => '2026-02-30', 'note' => str_repeat('x', 141),
        ], server: ['HTTP_HX_REQUEST' => 'true']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#tf-errors .error-summary__title', 'Fix 4 fields to continue');
        self::assertSelectorTextContains('#tf-amount-error', 'Enter an amount greater than 0');
        self::assertSelectorTextContains('#tf-category-error', 'Choose a category');
        self::assertSelectorTextContains('#tf-date-error', 'Choose a date');
        self::assertSelectorExists('#tf-note-error');
        self::assertSelectorExists('#tf-amount[aria-invalid="true"]');

        // Typed values are kept.
        $this->client->request('POST', '/transactions/new', [
            '_token' => $token, 'type' => 'expense', 'amount' => 'abc', 'category' => '', 'date' => date('Y-m-d'), 'note' => 'keep me',
        ], server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#tf-amount-error', 'Enter a number, like 12.50');
        self::assertSame('abc', $this->client->getCrawler()->filter('#tf-amount')->attr('value'));
        self::assertSame('keep me', $this->client->getCrawler()->filter('#tf-note')->text());

        self::assertSame(0, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    public function testCsrfIsRequired(): void
    {
        $this->client->request('POST', '/transactions/new', [
            '_token' => 'nope', 'type' => 'expense', 'amount' => '10',
            'category' => (string) self::category($this->owner, 'Groceries')->getId(), 'date' => date('Y-m-d'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    public function testEditTransactionIncludingTypeChange(): void
    {
        $tx = self::addTransaction($this->owner, 'Groceries', 4615, date('Y').'-11-05', 'Bakery & produce');

        $crawler = $this->client->request('GET', '/transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#tf-title', 'Edit transaction');
        self::assertSame('46.15', $crawler->filter('#tf-amount')->attr('value'));
        self::assertSelectorExists('#tf-delete');

        // Switching the type re-fills the category options on the client (Alpine); the crawler
        // has no JS, so allow the income category value directly.
        $form = $crawler->filter('#tx-form')->form()->disableValidation();
        $form->setValues([
            'type' => 'income', 'amount' => '47',
            'category' => (string) self::category($this->owner, 'Refunds', TransactionType::Income)->getId(),
            'date' => date('Y').'-11-04', 'note' => 'Returned bread',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/transactions', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#em-toasts .toast', 'Transaction updated');

        $tx = $this->onlyTransaction();
        self::assertSame(4700, $tx->getAmountMinor());
        self::assertSame(TransactionType::Income, $tx->getType());
        self::assertSame('Returned bread', $tx->getNote());
    }

    public function testDeleteWithConfirmation(): void
    {
        $tx = self::addTransaction($this->owner, 'Dining out', 7950, date('Y').'-11-04', 'Anniversary dinner — Saffron');

        // Confirmation dialog (HTMX fragment).
        $this->client->request('GET', '/transactions/'.$tx->getId().'/delete', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#cd-title', 'Delete this transaction?');
        self::assertSelectorTextContains('#cd-desc', '“Anniversary dinner — Saffron”, −$79.50 on Nov 4. This can’t be undone.');

        // Plain page fallback has the dialog open over the list.
        $crawler = $this->client->request('GET', '/transactions/'.$tx->getId().'/delete');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar');
        $form = $crawler->filter('#confirm-delete form')->form();

        // Wrong token: nothing deleted.
        $this->client->request('POST', '/transactions/'.$tx->getId().'/delete', ['_token' => 'nope']);
        self::assertSame(1, self::getContainer()->get(TransactionRepository::class)->count([]));

        $this->client->submit($form);
        self::assertResponseRedirects('/transactions', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#em-toasts .toast', 'Transaction deleted');
        self::assertSelectorTextContains('#em-toasts .toast', '−$79.50 · Dining out · Nov 4');
        self::assertSame(0, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    public function testFiltersAndTotals(): void
    {
        $y = date('Y');
        self::addTransaction($this->owner, 'Salary', 420000, "$y-01-01", 'Salary', TransactionType::Income);
        self::addTransaction($this->owner, 'Dining out', 7950, "$y-02-04", 'Dinner');
        self::addTransaction($this->owner, 'Dining out', 4210, "$y-03-03", 'Lunch');
        self::addTransaction($this->owner, 'Groceries', 9620, "$y-03-02", 'Groceries');
        self::addTransaction($this->owner, 'Groceries', 1000, ($y - 1).'-12-31', 'Last year');

        // Default period = this year.
        $this->client->request('GET', '/transactions');
        self::assertSelectorTextContains('#tx-count', '4 transactions');
        self::assertSelectorTextContains('#tx-totals .stat-card--income', '+$4,200.00');
        self::assertSelectorTextContains('#tx-totals .stat-card--expense', '−$217.80');
        self::assertSelectorTextContains('#tx-totals .stat-card--balance', '+$3,982.20');
        self::assertSelectorNotExists('#clear-filters');

        // Type + category.
        $dining = self::category($this->owner, 'Dining out');
        $this->client->request('GET', '/transactions?type=expense&category='.$dining->getId());
        self::assertSelectorTextContains('#tx-count', '2 transactions');
        self::assertSelectorTextContains('#tx-totals .stat-card--income', '$0.00');
        self::assertSelectorTextContains('#tx-totals .stat-card--expense', '−$121.60');
        self::assertSelectorTextContains('#tx-totals .stat-card--balance', '−$121.60');
        self::assertSelectorExists('#clear-filters');
        self::assertSelectorExists('#f-cat option[value="'.$dining->getId().'"][selected]');

        // Category of the other type is dropped.
        $this->client->request('GET', '/transactions?type=income&category='.$dining->getId());
        self::assertSelectorTextContains('#tx-count', '1 transaction');

        // Custom range (inclusive).
        $this->client->request('GET', "/transactions?period=custom&from=$y-03-02&to=$y-03-03");
        self::assertSelectorTextContains('#tx-count', '2 transactions');
        self::assertSelectorTextContains('.period-filter .select-trigger__label', 'Custom range');

        // Invalid custom range → error in the open range popover, default period used.
        $this->client->request('GET', "/transactions?period=custom&from=$y-03-03&to=$y-03-01");
        self::assertSelectorTextContains('#tx-period-to-error', '“From” must be on or before “To”.');
        self::assertSelectorTextContains('#tx-count', '4 transactions');

        // No results.
        $this->client->request('GET', "/transactions?period=custom&from=$y-06-01&to=$y-06-30");
        self::assertSelectorTextContains('#tx-card', 'No transactions match these filters');
    }

    public function testEmptyStateForNewAccount(): void
    {
        $this->client->request('GET', '/transactions');
        self::assertSelectorTextContains('#tx-card', 'No transactions yet');
        self::assertSelectorNotExists('.tx-filters');
        self::assertSelectorTextContains('#tx-totals .stat-card--income', '$0.00');
    }

    public function testPagingInPortionsOf25(): void
    {
        $y = date('Y');
        for ($i = 1; $i <= 30; ++$i) {
            self::addTransaction($this->owner, 'Groceries', 100 * $i, sprintf('%s-01-%02d', $y, $i), 'Row '.$i);
        }

        $crawler = $this->client->request('GET', '/transactions');
        self::assertCount(25, $crawler->filter('#tx-card tbody tr'));
        self::assertSelectorTextContains('#tx-card .card__footer', 'Showing 25 of 30');
        self::assertSelectorTextContains('#load-more', 'Load 5 more');
        self::assertSame('Row 30', $crawler->filter('#tx-card tbody tr td.cell-note')->first()->text());

        $crawler = $this->client->request('GET', $crawler->filter('#load-more')->attr('href'));
        self::assertCount(30, $crawler->filter('#tx-card tbody tr'));
        self::assertSelectorTextContains('#tx-card .card__footer', 'Showing 30 of 30');
        self::assertSelectorNotExists('#load-more');
        self::assertCount(1, $crawler->filter('#tx-card tbody tr[data-first-new]'));
    }

    public function testAnotherAccountsTransactionIsNotFound(): void
    {
        $tx = self::addTransaction($this->owner, 'Groceries', 999, date('Y-m-d'), 'Private');
        $intruder = self::createAccount('intruder@example.com');
        $this->client->loginUser($intruder);

        foreach (['GET /edit', 'POST /edit', 'GET /delete', 'POST /delete'] as $case) {
            [$method, $suffix] = explode(' ', $case);
            $this->client->request($method, '/transactions/'.$tx->getId().$suffix, ['_token' => 'x', 'amount' => '1']);
            self::assertResponseStatusCodeSame(404, $case);
        }

        $crawler = $this->client->request('GET', '/transactions');
        self::assertSelectorTextContains('#tx-card', 'No transactions yet');
        self::assertStringNotContainsString('Private', $crawler->html());

        // The owner's category id can't be used by the intruder (not in their account).
        $this->client->request('GET', '/transactions/new', server: ['HTTP_HX_REQUEST' => 'true']);
        $token = $this->client->getCrawler()->filter('#tx-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/transactions/new', [
            '_token' => $token, 'type' => 'expense', 'amount' => '5',
            'category' => (string) self::category($this->owner, 'Groceries')->getId(), 'date' => date('Y-m-d'),
        ], server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#tf-category-error', 'Choose a category');

        // Filtering by the owner's category id shows nothing of the owner.
        $this->client->request('GET', '/transactions?category='.self::category($this->owner, 'Groceries')->getId());
        self::assertStringNotContainsString('Private', (string) $this->client->getResponse()->getContent());

        self::assertSame(1, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    private function onlyTransaction(): Transaction
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $all = self::getContainer()->get(TransactionRepository::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }
}
