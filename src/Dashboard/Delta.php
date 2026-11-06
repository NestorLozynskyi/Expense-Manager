<?php

declare(strict_types=1);

namespace App\Dashboard;

/** "8.3% more than Oct 1–5" — the stat card comparison line (design deltaHTML). */
final readonly class Delta
{
    private function __construct(public string $icon, public string $text)
    {
    }

    public static function compare(int $current, int $previous, string $label, string $upWord, string $downWord): self
    {
        if (0 === $previous) {
            return new self('info', 'No data for '.$label);
        }
        $pct = ($current - $previous) / abs($previous) * 100;
        if (abs($pct) < 0.05) {
            return new self('arrow-right', 'Same as '.$label);
        }
        $up = $pct > 0;

        return new self($up ? 'trending-up' : 'trending-down', number_format(abs($pct), 1).'% '.($up ? $upWord : $downWord).' than '.$label);
    }
}
