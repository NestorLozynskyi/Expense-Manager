<?php

declare(strict_types=1);

namespace App\Finance;

use Psr\Clock\ClockInterface;

/**
 * Builds periods relative to "today" (the injected clock) from request parameters.
 */
final readonly class PeriodResolver
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    public function currentMonth(): string
    {
        return $this->today()->format('Y-m');
    }

    /**
     * Unknown keys fall back to $default; an invalid custom range throws InvalidPeriodException.
     */
    public function resolve(?string $key, ?string $from = null, ?string $to = null, string $default = 'this-month'): Period
    {
        $key = \in_array($key, Period::KEYS, true) ? $key : $default;
        $today = $this->today();

        return match ($key) {
            'this-month' => new Period($key, $today->modify('first day of this month'), $today->modify('last day of this month')),
            'last-month' => new Period($key, $today->modify('first day of last month'), $today->modify('last day of last month')),
            'this-year' => new Period($key, $today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)),
            default => $this->custom($from, $to),
        };
    }

    public function custom(?string $from, ?string $to): Period
    {
        $f = self::parseDate($from);
        $t = self::parseDate($to);
        if (!$f || !$t) {
            throw new InvalidPeriodException('Choose both dates.');
        }
        if ($f > $t) {
            throw new InvalidPeriodException('“From” must be on or before “To”.');
        }

        return new Period('custom', $f, $t);
    }

    /**
     * Comparison window for dashboard deltas (as in the approved dashboard):
     *  - this month → previous month, day 1 … today's day number (e.g. "Oct 1–5");
     *  - last month → the month before it;
     *  - other periods → none.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, label: string}|null
     */
    public function comparison(Period $period): ?array
    {
        if ('this-month' === $period->key) {
            $today = $this->today();
            $from = $today->modify('first day of last month');
            $day = min((int) $today->format('j'), (int) $from->format('t'));
            $to = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), $day);

            return ['from' => $from, 'to' => $to, 'label' => $from->format('M').' 1–'.$day];
        }
        if ('last-month' === $period->key) {
            $from = $period->from->modify('first day of last month');

            return ['from' => $from, 'to' => $from->modify('last day of this month'), 'label' => $from->format('F')];
        }

        return null;
    }

    public static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d && $d->format('Y-m-d') === $value ? $d : null;
    }
}
