<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dashboard\DashboardService;
use App\Entity\TransactionType;
use App\Finance\PeriodResolver;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DashboardServiceTest extends KernelTestCase
{
    use Accounts;
    use ClockSensitiveTrait;
    use ResetsDatabase;

    private DashboardService $dashboard;
    private PeriodResolver $periods;

    protected function setUp(): void
    {
        self::mockTime('2026-11-05 12:00:00');
        self::bootKernel();
        self::resetDatabase();
        $this->dashboard = self::getContainer()->get(DashboardService::class);
        $this->periods = self::getContainer()->get(PeriodResolver::class);
    }

    public function testTotalsAndComparisonWithSameSpanOfPreviousMonth(): void
    {
        $u = self::createAccount();
        self::addTransaction($u, 'Salary', 455000, '2026-11-01', type: TransactionType::Income);
        self::addTransaction($u, 'Groceries', 191624, '2026-11-04');
        // previous month: only Oct 1–5 counts for the comparison
        self::addTransaction($u, 'Salary', 420000, '2026-10-01', type: TransactionType::Income);
        self::addTransaction($u, 'Groceries', 165200, '2026-10-05');
        self::addTransaction($u, 'Groceries', 99999, '2026-10-06'); // outside "Oct 1–5"

        $t = $this->dashboard->totals($u, $this->periods->resolve('this-month'));

        self::assertSame(455000, $t['income']);
        self::assertSame(191624, $t['expense']);
        self::assertSame(263376, $t['balance']);
        self::assertSame('Oct 1–5', $t['comparisonLabel']);
        self::assertSame('8.3% more than Oct 1–5', $t['deltas']['income']->text);
        self::assertSame('trending-up', $t['deltas']['income']->icon);
        self::assertSame('16.0% more than Oct 1–5', $t['deltas']['expense']->text);
        self::assertSame('3.4% higher than Oct 1–5', $t['deltas']['balance']->text); // 2,633.76 vs 2,548.00
    }

    public function testLastMonthComparesWithTheWholeMonthBefore(): void
    {
        $u = self::createAccount();
        self::addTransaction($u, 'Groceries', 10000, '2026-10-10');
        self::addTransaction($u, 'Groceries', 20000, '2026-09-20');

        $t = $this->dashboard->totals($u, $this->periods->resolve('last-month'));

        self::assertSame('September', $t['comparisonLabel']);
        self::assertSame('50.0% less than September', $t['deltas']['expense']->text);
        self::assertSame('trending-down', $t['deltas']['expense']->icon);
        self::assertSame('No data for September', $t['deltas']['income']->text);
    }

    public function testNoComparisonForYearAndCustomPeriods(): void
    {
        $u = self::createAccount();
        self::addTransaction($u, 'Groceries', 1000, '2026-03-01');
        self::addTransaction($u, 'Salary', 5000, '2026-03-02', type: TransactionType::Income);

        $t = $this->dashboard->totals($u, $this->periods->resolve('this-year'));
        self::assertNull($t['deltas']);
        self::assertSame(1, $t['incomeCount']);
        self::assertSame(1, $t['expenseCount']);
        self::assertNull($this->dashboard->totals($u, $this->periods->resolve('custom', '2026-01-01', '2026-06-30'))['deltas']);
    }

    public function testSpendingByCategoryKeepsTopSixAndGroupsTheRest(): void
    {
        $u = self::createAccount();
        $amounts = ['Housing' => 145000, 'Dining out' => 18640, 'Groceries' => 14235, 'Transport' => 9800, 'Entertainment' => 2400, 'Subscriptions' => 1549, 'Health' => 1000, 'Travel' => 500];
        foreach ($amounts as $cat => $a) {
            self::addTransaction($u, $cat, $a, '2026-11-02');
        }
        self::addTransaction($u, 'Salary', 999999, '2026-11-02', type: TransactionType::Income); // income is not spending

        $d = $this->dashboard->spendingByCategory($u, $this->periods->resolve('this-month'));

        self::assertSame(array_sum($amounts), $d['total']);
        self::assertCount(7, $d['rows']);
        self::assertSame(['Housing', 'Dining out', 'Groceries', 'Transport', 'Entertainment', 'Subscriptions', '2 more categories'], array_column($d['rows'], 'name'));
        self::assertSame(1500, $d['rows'][6]['total']);
        self::assertSame('#3A5BA9', $d['rows'][0]['color']);
    }

    public function testMonthlySeriesShowsLastFourMonthsWithData(): void
    {
        $u = self::createAccount();
        foreach (['2026-06-10', '2026-08-10', '2026-09-10', '2026-10-10', '2026-11-03', '2026-12-01'] as $d) {
            self::addTransaction($u, 'Groceries', 1000, $d);
        }
        self::addTransaction($u, 'Salary', 7000, '2026-10-01', type: TransactionType::Income);

        $m = $this->dashboard->monthly($u, $this->periods->resolve('this-month'));

        self::assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], array_column($m['months'], 'month'));
        self::assertSame(['income' => 7000, 'expense' => 1000], ['income' => $m['months'][2]['income'], 'expense' => $m['months'][2]['expense']]);
        self::assertSame('Aug – November 2026 · last 4 months', $m['subtitle']);

        self::addTransaction($u, 'Groceries', 300, '2026-09-20');
        $custom = $this->dashboard->monthly($u, $this->periods->resolve('custom', '2026-09-15', '2026-10-31'));
        self::assertSame(['2026-09', '2026-10'], array_column($custom['months'], 'month'));
        self::assertSame(300, $custom['months'][0]['expense']); // Sep 10 is outside the range
        self::assertSame('Partial months only include days inside the selected range.', $custom['note']);

        $year = $this->dashboard->monthly($u, $this->periods->resolve('this-year'));
        self::assertSame('Jan – Dec 2026 · by month', $year['subtitle']);
        self::assertStringStartsWith('Data since Jun 2026', (string) $year['note']);
    }

    public function testDataOfOtherAccountsIsNeverCounted(): void
    {
        $me = self::createAccount('me@example.com');
        $other = self::createAccount('other@example.com');
        self::addTransaction($other, 'Groceries', 50000, '2026-11-02');
        self::addTransaction($me, 'Groceries', 1000, '2026-11-02');

        $p = $this->periods->resolve('this-month');
        self::assertSame(1000, $this->dashboard->totals($me, $p)['expense']);
        self::assertSame(1000, $this->dashboard->spendingByCategory($me, $p)['total']);
        self::assertCount(1, $this->dashboard->recent($me, $p));
        self::assertSame(1000, $this->dashboard->monthly($me, $p)['months'][0]['expense']);
    }
}
