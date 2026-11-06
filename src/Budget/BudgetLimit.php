<?php

declare(strict_types=1);

namespace App\Budget;

use App\Money\Currencies;
use App\Money\MoneyFormatter;
use App\Money\MoneyParser;

/**
 * Parses the "Monthly limit" input into minor units (no floats), with the design's messages.
 */
final class BudgetLimit
{
    /** Upper bound shown in the design ("Enter a limit below $100,000,000"), in major units. */
    public const MAX_MAJOR = 100_000_000;

    /**
     * @return int|string limit in minor units, or a user-facing error message
     */
    public static function parse(?string $input, string $currency, MoneyFormatter $money): int|string
    {
        $raw = trim((string) $input);
        $symbol = Currencies::narrowSymbol($currency);
        $raw = trim(str_replace([$symbol, '$'], '', $raw));
        if ('' === $raw) {
            return 'Enter a monthly limit';
        }
        try {
            $minor = MoneyParser::parse($raw, $currency);
        } catch (\InvalidArgumentException $e) {
            return str_starts_with($e->getMessage(), 'Use no more than') || str_starts_with($e->getMessage(), 'Use whole')
                ? $e->getMessage()
                : ('Enter a smaller amount' === $e->getMessage() ? self::tooBig($currency, $money) : 'Enter a limit greater than 0');
        }
        $max = self::MAX_MAJOR * 10 ** Currencies::fractionDigits($currency);
        if ($minor >= $max) {
            return self::tooBig($currency, $money);
        }

        return $minor;
    }

    private static function tooBig(string $currency, MoneyFormatter $money): string
    {
        $formatted = $money->format(self::MAX_MAJOR * 10 ** Currencies::fractionDigits($currency), $currency);

        return 'Enter a limit below '.preg_replace('/[.,]0+$/', '', $formatted);
    }
}
