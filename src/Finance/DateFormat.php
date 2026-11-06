<?php

declare(strict_types=1);

namespace App\Finance;

/** Date strings exactly as in the design (en-US, e.g. "Nov 5, 2026"). */
final class DateFormat
{
    public static function medium(\DateTimeInterface $d): string
    {
        return $d->format('M j, Y');
    }

    public static function short(\DateTimeInterface $d): string
    {
        return $d->format('M j');
    }

    public static function month(\DateTimeInterface $d): string
    {
        return $d->format('F Y');
    }

    public static function range(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $sameYear = $from->format('Y') === $to->format('Y');

        return ($sameYear ? self::short($from) : self::medium($from)).' – '.self::medium($to);
    }

    public static function monthLabel(string $month): string
    {
        return self::month(new \DateTimeImmutable($month.'-01'));
    }
}
