<?php

declare(strict_types=1);

namespace App\Money;

/**
 * Parses user input ("1,234.56", "1234.5", "12") into minor units without floats.
 */
final class MoneyParser
{
    /** 999,999,999,999 major units is far beyond personal finance and safely inside int64. */
    public const MAX_MAJOR_DIGITS = 12;

    /**
     * @throws \InvalidArgumentException with a user-facing message
     */
    public static function parse(?string $input, string $currency): int
    {
        $raw = preg_replace('/[\s,\x{00A0}\x{202F}]/u', '', (string) $input) ?? '';
        if ('' === $raw) {
            throw new \InvalidArgumentException('Enter an amount greater than 0');
        }
        $digits = Currencies::fractionDigits($currency);
        if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $raw, $m) || ('' === $m[1] && '' === ($m[2] ?? ''))) {
            throw new \InvalidArgumentException('Enter a number, like 12.50');
        }
        $int = ltrim($m[1], '0');
        $frac = rtrim($m[2] ?? '', '0');
        if (\strlen($frac) > $digits) {
            throw new \InvalidArgumentException(0 === $digits ? "Use whole amounts for $currency" : "Use no more than $digits decimal places");
        }
        if (\strlen($int) > self::MAX_MAJOR_DIGITS) {
            throw new \InvalidArgumentException('Enter a smaller amount');
        }
        $minor = (int) (('' === $int ? '0' : $int).str_pad($frac, $digits, '0'));
        if ($minor <= 0) {
            throw new \InvalidArgumentException('Enter an amount greater than 0');
        }

        return $minor;
    }
}
