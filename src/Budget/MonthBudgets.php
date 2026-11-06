<?php

declare(strict_types=1);

namespace App\Budget;

use App\Finance\DateFormat;

/** All budgets in effect for one month with their calculated status. */
final readonly class MonthBudgets
{
    /** @var array{ok: int, warning: int, over: int} */
    public array $counts;

    /**
     * @param list<BudgetStatus> $items category budgets sorted over → near limit → on track, then by % desc
     * @param string|null        $sourceMonth month whose plan is in effect (≠ $month when carried over)
     * @param array<int, int>    $spentByCategory
     */
    public function __construct(
        public string $month,
        public ?BudgetStatus $overall,
        public array $items,
        public ?string $sourceMonth,
        public int $spentTotal,
        public array $spentByCategory = [],
    ) {
        $counts = ['ok' => 0, 'warning' => 0, 'over' => 0];
        foreach ($items as $item) {
            ++$counts[$item->state];
        }
        $this->counts = $counts;
    }

    public function label(): string
    {
        return DateFormat::monthLabel($this->month);
    }

    public function isEmpty(): bool
    {
        return null === $this->overall && [] === $this->items;
    }

    public function isCarriedOver(): bool
    {
        return null !== $this->sourceMonth && $this->sourceMonth !== $this->month;
    }

    /** @return list<BudgetStatus> overall first (if over), then over category budgets */
    public function overBudgets(): array
    {
        $over = array_values(array_filter($this->items, static fn (BudgetStatus $s) => BudgetStatus::OVER === $s->state));
        if ($this->overall && BudgetStatus::OVER === $this->overall->state) {
            array_unshift($over, $this->overall);
        }

        return $over;
    }

    public function hasCategory(int $categoryId): bool
    {
        return null !== $this->forCategoryId($categoryId);
    }

    public function spentFor(?int $categoryId): int
    {
        return null === $categoryId ? $this->spentTotal : ($this->spentByCategory[$categoryId] ?? 0);
    }

    public function forCategoryId(int $categoryId): ?BudgetStatus
    {
        foreach ($this->items as $item) {
            if ($item->category?->getId() === $categoryId) {
                return $item;
            }
        }

        return null;
    }
}
