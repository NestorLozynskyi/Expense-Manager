<?php

declare(strict_types=1);

namespace App\Finance;

/**
 * A reporting period (inclusive dates). Keys: this-month | last-month | this-year | custom.
 */
final readonly class Period
{
    public const KEYS = ['this-month', 'last-month', 'this-year', 'custom'];

    public function __construct(
        public string $key,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
        if ($from > $to) {
            throw new \InvalidArgumentException('Period start must not be after its end.');
        }
    }

    public function label(): string
    {
        return match ($this->key) {
            'this-month' => 'This month',
            'last-month' => 'Last month',
            'this-year' => 'This year',
            default => 'Custom range',
        };
    }

    /** "Nov 1 – Nov 30, 2026" / "Dec 15, 2025 – Jan 10, 2026" (design EM.fmtRange). */
    public function range(): string
    {
        return DateFormat::range($this->from, $this->to);
    }

    public function isMonth(): bool
    {
        return 'this-month' === $this->key || 'last-month' === $this->key;
    }

    /** @return array<string, string> query parameters that reproduce this period */
    public function query(): array
    {
        return 'custom' === $this->key
            ? ['period' => 'custom', 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')]
            : ['period' => $this->key];
    }
}
