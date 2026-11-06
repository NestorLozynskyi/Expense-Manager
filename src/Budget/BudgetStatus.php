<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Category;

/**
 * Calculated state of one budget (integer minor units; percentages only for display).
 * States: ok (< 80%), warning "near limit" (≥ 80% and ≤ 100%), over (> 100%).
 */
final readonly class BudgetStatus
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const OVER = 'over';

    public int $remaining;
    public int $over;
    /** Percentage used, one decimal (124.3). */
    public float $pct;
    public string $state;

    public function __construct(
        public int $limit,
        public int $spent,
        public ?Category $category = null,
    ) {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be positive.');
        }
        $this->remaining = max($limit - $spent, 0);
        $this->over = max($spent - $limit, 0);
        $this->pct = round(intdiv($spent * 1000 + intdiv($limit, 2), $limit) / 10, 1);
        $this->state = match (true) {
            $spent > $limit => self::OVER,
            $spent * 100 >= $limit * 80 => self::WARNING,
            default => self::OK,
        };
    }

    public function isOverall(): bool
    {
        return null === $this->category;
    }

    /** Width of the solid bar (0–100). */
    public function fillPct(): float
    {
        return self::OVER === $this->state ? 100.0 : min($this->pct, 100.0);
    }

    /** Hatched overflow share for over-budget bars (over / spent, design EM.progressHTML). */
    public function overflowPct(): float
    {
        return self::OVER === $this->state && $this->spent > 0 ? round($this->over * 1000 / $this->spent) / 10 : 0.0;
    }

    public function stateRank(): int
    {
        return match ($this->state) {
            self::OVER => 0,
            self::WARNING => 1,
            default => 2,
        };
    }
}
