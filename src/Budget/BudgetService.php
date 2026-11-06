<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\BudgetPlan;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\BudgetPlanRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monthly budgets with carry-over: a month without its own plan uses the latest earlier
 * month's limits; any change first copies those limits into the month, so earlier months
 * never change (coordinator decision for TASK-003, rule 7).
 */
final readonly class BudgetService
{
    public function __construct(
        private BudgetPlanRepository $plans,
        private TransactionRepository $transactions,
        private EntityManagerInterface $em,
        private CategoryRepository $categories,
    ) {
    }

    public function forMonth(User $owner, string $month): MonthBudgets
    {
        $plan = $this->plans->findEffective($owner, $month);
        [$from, $to] = self::monthRange($month);
        $spentByCategory = $this->transactions->sumByCategory($owner, $from, $to, TransactionType::Expense);
        $total = array_sum($spentByCategory);

        if (!$plan) {
            return new MonthBudgets($month, null, [], null, $total, $spentByCategory);
        }

        $items = [];
        foreach ($plan->getCategoryBudgets() as $budget) {
            $category = $budget->getCategory();
            $items[] = new BudgetStatus($budget->getLimitMinor(), $spentByCategory[$category->getId()] ?? 0, $category);
        }
        usort($items, static fn (BudgetStatus $a, BudgetStatus $b) => [$a->stateRank(), $b->pct, $a->category?->getPosition()] <=> [$b->stateRank(), $a->pct, $b->category?->getPosition()]);
        $overall = null !== $plan->getOverallLimitMinor() ? new BudgetStatus($plan->getOverallLimitMinor(), $total) : null;

        return new MonthBudgets($month, $overall, $items, $plan->getMonth(), $total, $spentByCategory);
    }

    public function setOverall(User $owner, string $month, int $limitMinor): void
    {
        $this->planForEditing($owner, $month)->setOverallLimit($limitMinor);
        $this->em->flush();
    }

    public function removeOverall(User $owner, string $month): void
    {
        $this->planForEditing($owner, $month)->setOverallLimit(null);
        $this->em->flush();
    }

    public function setCategoryLimit(User $owner, string $month, Category $category, int $limitMinor): void
    {
        $this->planForEditing($owner, $month)->setCategoryLimit($category, $limitMinor);
        $this->em->flush();
    }

    public function removeCategoryLimit(User $owner, string $month, Category $category): void
    {
        $this->planForEditing($owner, $month)->removeCategoryLimit($category);
        $this->em->flush();
    }

    /**
     * Expense categories that can still get a budget in $month (no budget in effect yet).
     *
     * @return list<Category>
     */
    public function availableCategories(User $owner, MonthBudgets $budgets): array
    {
        return array_values(array_filter(
            $this->categories->findByOwnerAndType($owner, TransactionType::Expense),
            static fn (Category $c) => !$budgets->hasCategory((int) $c->getId()),
        ));
    }

    /**
     * Months the month picker can show: from the first month with data (transactions or
     * budgets; the current month for a new account) to the next month, so a budget can be
     * prepared in advance.
     *
     * @return array{min: string, max: string}
     */
    public function monthRangeFor(User $owner, string $currentMonth): array
    {
        $firstTx = $this->em->createQueryBuilder()
            ->select('MIN(t.date)')
            ->from(Transaction::class, 't')
            ->where('t.owner = :owner')->setParameter('owner', $owner)
            ->getQuery()->getSingleScalarResult();
        $candidates = [$currentMonth];
        if (\is_string($firstTx) && '' !== $firstTx) {
            $candidates[] = substr($firstTx, 0, 7);
        }
        if ($firstPlan = $this->plans->earliestMonth($owner)) {
            $candidates[] = $firstPlan;
        }

        return ['min' => min($candidates), 'max' => self::addMonths($currentMonth, 1)];
    }

    /** The month's own plan; created as a copy of the carried-over plan (or empty) when missing. */
    private function planForEditing(User $owner, string $month): BudgetPlan
    {
        $own = $this->plans->findForMonth($owner, $month);
        if ($own) {
            return $own;
        }
        $source = $this->plans->findEffective($owner, $month);
        $plan = $source ? $source->copyFor($month) : new BudgetPlan($owner, $month);
        $this->em->persist($plan);

        return $plan;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public static function monthRange(string $month): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
        if (!$from || $from->format('Y-m') !== $month) {
            throw new \InvalidArgumentException('Month must be YYYY-MM.');
        }

        return [$from, $from->modify('last day of this month')];
    }

    public static function isValidMonth(?string $month): bool
    {
        return null !== $month && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }

    public static function addMonths(string $month, int $n): string
    {
        return (new \DateTimeImmutable($month.'-01'))->modify(($n >= 0 ? '+' : '').$n.' month')->format('Y-m');
    }
}
