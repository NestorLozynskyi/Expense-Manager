<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Budget\BudgetService;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Monthly budgets: spent per month, carry-over of the latest earlier month, copy-on-write so
 * changing month M never changes earlier months.
 */
final class BudgetServiceTest extends KernelTestCase
{
    use Accounts;
    use ClockSensitiveTrait;
    use ResetsDatabase;

    private BudgetService $budgets;

    protected function setUp(): void
    {
        self::mockTime('2026-11-15 12:00:00');
        self::bootKernel();
        self::resetDatabase();
        $this->budgets = self::getContainer()->get(BudgetService::class);
    }

    public function testCalculatesSpentRemainingAndStatesPerMonth(): void
    {
        $u = self::createAccount();
        self::addTransaction($u, 'Dining out', 18640, '2026-11-04');
        self::addTransaction($u, 'Transport', 9800, '2026-11-03');
        self::addTransaction($u, 'Transport', 5000, '2026-10-31'); // other month
        self::addTransaction($u, 'Salary', 400000, '2026-11-01', type: \App\Entity\TransactionType::Income); // income never counts
        $this->budgets->setOverall($u, '2026-11', 320000);
        $this->budgets->setCategoryLimit($u, '2026-11', self::category($u, 'Dining out'), 15000);
        $this->budgets->setCategoryLimit($u, '2026-11', self::category($u, 'Transport'), 12000);
        $this->budgets->setCategoryLimit($u, '2026-11', self::category($u, 'Shopping'), 15000);

        $m = $this->budgets->forMonth($u, '2026-11');

        self::assertSame(28440, $m->overall?->spent);
        self::assertSame('ok', $m->overall?->state);
        self::assertSame(['Dining out', 'Transport', 'Shopping'], array_map(static fn ($s) => $s->category?->getName(), $m->items), 'over → near limit → on track');
        self::assertSame(3640, $m->items[0]->over);
        self::assertSame(2200, $m->items[1]->remaining);
        self::assertSame(['ok' => 1, 'warning' => 1, 'over' => 1], $m->counts);
        self::assertCount(1, $m->overBudgets());
        self::assertFalse($m->isCarriedOver());
    }

    public function testMonthWithoutOwnBudgetsUsesLatestEarlierMonth(): void
    {
        $u = self::createAccount();
        $groceries = self::category($u, 'Groceries');
        $this->budgets->setCategoryLimit($u, '2026-08', $groceries, 30000);
        $this->budgets->setCategoryLimit($u, '2026-10', $groceries, 45000);
        self::addTransaction($u, 'Groceries', 40000, '2026-12-02');

        self::assertTrue($this->budgets->forMonth($u, '2026-07')->isEmpty(), 'nothing before the first budget');
        self::assertSame(30000, $this->budgets->forMonth($u, '2026-09')->items[0]->limit);
        $dec = $this->budgets->forMonth($u, '2026-12');
        self::assertSame(45000, $dec->items[0]->limit);
        self::assertSame(40000, $dec->items[0]->spent, 'spent is always the month’s own');
        self::assertTrue($dec->isCarriedOver());
        self::assertSame('2026-10', $dec->sourceMonth);
    }

    public function testChangingAMonthKeepsEarlierMonthsAndAppliesToLaterMonthsWithoutOwnBudgets(): void
    {
        $u = self::createAccount();
        $groceries = self::category($u, 'Groceries');
        $dining = self::category($u, 'Dining out');
        $this->budgets->setOverall($u, '2026-10', 300000);
        $this->budgets->setCategoryLimit($u, '2026-10', $groceries, 45000);
        $this->budgets->setCategoryLimit($u, '2026-10', $dining, 15000);

        // Edit November (carried over from October) → November gets its own copy.
        $this->budgets->setCategoryLimit($u, '2026-11', $groceries, 50000);

        $oct = $this->budgets->forMonth($u, '2026-10');
        self::assertSame(45000, $oct->forCategoryId((int) $groceries->getId())?->limit, 'October unchanged');
        $nov = $this->budgets->forMonth($u, '2026-11');
        self::assertFalse($nov->isCarriedOver());
        self::assertSame(50000, $nov->forCategoryId((int) $groceries->getId())?->limit);
        self::assertSame(15000, $nov->forCategoryId((int) $dining->getId())?->limit, 'other limits copied');
        self::assertSame(300000, $nov->overall?->limit);
        self::assertSame(50000, $this->budgets->forMonth($u, '2026-12')->forCategoryId((int) $groceries->getId())?->limit, 'December (no own budgets) follows November');
    }

    public function testDeletingInACarriedOverMonthKeepsEarlierMonth(): void
    {
        $u = self::createAccount();
        $dining = self::category($u, 'Dining out');
        $this->budgets->setOverall($u, '2026-10', 300000);
        $this->budgets->setCategoryLimit($u, '2026-10', $dining, 15000);

        $this->budgets->removeCategoryLimit($u, '2026-11', $dining);
        $this->budgets->removeOverall($u, '2026-11');

        self::assertTrue($this->budgets->forMonth($u, '2026-11')->isEmpty());
        self::assertTrue($this->budgets->forMonth($u, '2027-01')->isEmpty(), 'later months follow November');
        $oct = $this->budgets->forMonth($u, '2026-10');
        self::assertSame(300000, $oct->overall?->limit);
        self::assertSame(15000, $oct->forCategoryId((int) $dining->getId())?->limit);
    }

    public function testAvailableCategoriesAndMonthRange(): void
    {
        $u = self::createAccount();
        $this->budgets->setCategoryLimit($u, '2026-11', self::category($u, 'Groceries'), 1000);
        $names = array_map(static fn ($c) => $c->getName(), $this->budgets->availableCategories($u, $this->budgets->forMonth($u, '2026-11')));
        self::assertNotContains('Groceries', $names);
        self::assertContains('Dining out', $names);
        self::assertNotContains('Salary', $names, 'expense categories only');

        self::assertSame(['min' => '2026-11', 'max' => '2026-12'], $this->budgets->monthRangeFor($u, '2026-11'));
        self::addTransaction($u, 'Groceries', 100, '2026-08-20');
        self::assertSame(['min' => '2026-08', 'max' => '2026-12'], $this->budgets->monthRangeFor($u, '2026-11'));
    }
}
