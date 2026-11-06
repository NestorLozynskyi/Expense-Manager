<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DashboardTest extends WebTestCase
{
    use Accounts;
    use ClockSensitiveTrait;
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime('2026-11-05 12:00:00');
        $this->client = self::createClient();
        self::resetDatabase();
    }

    public function testAnonymousIsRedirectedToSignIn(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testShowsTotalsChartsBudgetsAndRecentTransactionsOfTheAccount(): void
    {
        $me = self::createAccount('me@example.com');
        self::addTransaction($me, 'Salary', 455000, '2026-11-01', 'Salary — Northwind Labs', TransactionType::Income);
        self::addTransaction($me, 'Dining out', 7950, '2026-11-04', 'Anniversary dinner — Saffron');
        self::addTransaction($me, 'Groceries', 4000, '2026-10-03');
        $other = self::createAccount('other@example.com');
        self::addTransaction($other, 'Groceries', 123456, '2026-11-02', 'Secret purchase of another account');

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dash-subtitle', 'Nov 1 – Nov 30, 2026 · All amounts in USD');
        self::assertSelectorTextSame('#stat-income .stat-card__value', '+$4,550.00');
        self::assertSelectorTextSame('#stat-expense .stat-card__value', '−$79.50');
        self::assertSelectorTextSame('#stat-balance .stat-card__value', '$4,470.50');
        self::assertSelectorTextContains('#stat-income .stat-card__delta', 'No data for Oct 1–5');
        self::assertSelectorTextContains('#stat-expense .stat-card__delta', '98.8% more than Oct 1–5');
        self::assertSelectorTextContains('#recent-list', 'Anniversary dinner — Saffron');
        self::assertSelectorNotExists('#dash-welcome');
        self::assertCount(1, $crawler->filter('#donut-canvas'));
        self::assertCount(1, $crawler->filter('#bar-canvas'));
        $donut = json_decode((string) $crawler->filter('#donut-canvas')->attr('data-chart'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Dining out'], $donut['labels']);
        self::assertEqualsWithDelta(79.5, $donut['datasets'][0]['data'][0], 0.001);
        // isolation: nothing of the other account is rendered
        self::assertStringNotContainsString('Secret purchase', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('1,234.56', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyStateForANewAccount(): void
    {
        $this->client->loginUser(self::createAccount('new@example.com', 'UAH'));
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dash-welcome', 'No transactions yet');
        self::assertSelectorTextContains('#dash-subtitle', 'Account currency: UAH (₴)');
        self::assertSelectorTextSame('#stat-income .stat-card__value', '₴0.00');
        self::assertSelectorTextSame('#stat-income .stat-card__delta', 'No transactions yet');
        self::assertSelectorTextContains('#dash-donut', 'Spending by category appears here');
        self::assertSelectorTextContains('#dash-budgets', 'No budgets for November 2026');
        self::assertSelectorTextContains('#recent-body', 'Nothing here yet');
    }

    public function testPeriodFilterAndInvalidCustomRange(): void
    {
        $me = self::createAccount();
        self::addTransaction($me, 'Groceries', 1000, '2026-10-10');
        $this->client->loginUser($me);

        $this->client->request('GET', '/dashboard?period=last-month');
        self::assertSelectorTextContains('.period-filter .select-trigger__label', 'Last month');
        self::assertSelectorTextSame('#stat-expense .stat-card__value', '−$10.00');

        $this->client->request('GET', '/dashboard?period=custom&from=2026-10-31&to=2026-09-01');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#dash-period-range .field__error', 'End date must be on or after the start date');
    }

    public function testQuickAddCreatesATransactionDatedToday(): void
    {
        $me = self::createAccount();
        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/dashboard');
        $groceries = self::category($me, 'Groceries');

        $form = $crawler->filter('#qa-form')->form(['amount' => '12.50']);
        $form['category']->disableValidation()->setValue((string) $groceries->getId());
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Expense added — $12.50 · Groceries');
        $all = self::getContainer()->get(TransactionRepository::class)->findBy(['owner' => $me->getId()]);
        self::assertCount(1, $all);
        self::assertSame(1250, $all[0]->getAmountMinor());
        self::assertSame('2026-11-05', $all[0]->getDate()->format('Y-m-d'));
        self::assertNull($all[0]->getNote());
    }

    public function testQuickAddWithHtmxReturnsToastAndReload(): void
    {
        $me = self::createAccount();
        $this->client->loginUser($me);
        $token = $this->token();
        $salary = self::category($me, 'Salary', TransactionType::Income);

        $this->client->request('POST', '/dashboard/quick-add', ['_token' => $token, 'type' => 'income', 'amount' => '100', 'category' => (string) $salary->getId()], server: [
            'HTTP_HX_REQUEST' => 'true', 'HTTP_HX_CURRENT_URL' => 'http://localhost/dashboard?period=last-month',
        ]);

        self::assertResponseIsSuccessful();
        $trigger = json_decode((string) $this->client->getResponse()->headers->get('HX-Trigger'), true);
        self::assertSame('Income added — $100.00 · Salary', $trigger['em:toast']['title']);
        self::assertStringContainsString('outside the selected period', $trigger['em:toast']['message']);
        self::assertSame('/dashboard?period=last-month', json_decode((string) $this->client->getResponse()->headers->get('HX-Location'), true)['path']);
    }

    public function testQuickAddValidation(): void
    {
        $me = self::createAccount();
        $this->client->loginUser($me);
        $token = $this->token();
        $salary = self::category($me, 'Salary', TransactionType::Income);

        // expense with an income category, zero amount
        $this->client->request('POST', '/dashboard/quick-add', ['_token' => $token, 'type' => 'expense', 'amount' => '0', 'category' => (string) $salary->getId()], server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#dash-quick', 'Enter an amount greater than 0');
        self::assertSelectorTextContains('#dash-quick', 'Choose a category');

        // category of another account
        $other = self::createAccount('other@example.com');
        $this->client->request('POST', '/dashboard/quick-add', ['_token' => $token, 'type' => 'expense', 'amount' => '5', 'category' => (string) self::category($other, 'Groceries')->getId()], server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#dash-quick', 'Choose a category');

        // bad CSRF token
        $this->client->request('POST', '/dashboard/quick-add', ['_token' => 'nope', 'type' => 'expense', 'amount' => '5', 'category' => (string) self::category($me, 'Groceries')->getId()]);
        self::assertResponseRedirects('/dashboard');

        self::assertSame(0, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/dashboard');

        return (string) $crawler->filter('#qa-form input[name="_token"]')->attr('value');
    }
}
