<?php

declare(strict_types=1);

namespace App\Money;

/**
 * Formats minor-unit integers like the design (Intl en-US, narrowSymbol, U+2212 minus):
 * "$2,450.00", "₴98,400.00", "+$120.00", "−$45.20".
 */
final class MoneyFormatter
{
    public const MINUS = "\u{2212}";

    /** @var array<string, \MessageFormatter> */
    private array $formatters = [];

    public function format(int $minor, string $currency): string
    {
        $digits = Currencies::fractionDigits($currency);
        $value = self::toDecimalString(abs($minor), $digits);
        $out = (string) $this->formatter($currency)->format([$value]);

        return ($minor < 0 ? self::MINUS : '').$out;
    }

    /** "+$120.00" for income, "−$45.20" for expense. */
    public function signed(int $minor, string $type, string $currency): string
    {
        return ('income' === $type ? '+' : self::MINUS).$this->format(abs($minor), $currency);
    }

    /** Signed by value sign (balance): "+$9,791.20" / "−$12.00". */
    public function signedValue(int $minor, string $currency): string
    {
        return $this->signed($minor, $minor < 0 ? 'expense' : 'income', $currency);
    }

    /** Plain decimal for inputs: 12345 → "123.45". */
    public static function toInput(int $minor, string $currency): string
    {
        return self::toDecimalString($minor, Currencies::fractionDigits($currency));
    }

    /** Float only for charts/percentages (never stored). */
    public static function toFloat(int $minor, string $currency): float
    {
        return $minor / (10 ** Currencies::fractionDigits($currency));
    }

    private static function toDecimalString(int $minor, int $digits): string
    {
        if (0 === $digits) {
            return (string) $minor;
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = str_pad((string) abs($minor), $digits + 1, '0', \STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$digits).'.'.substr($abs, -$digits);
    }

    private function formatter(string $currency): \MessageFormatter
    {
        return $this->formatters[$currency] ??= new \MessageFormatter('en_US', '{0, number, ::currency/'.$currency.' unit-width-narrow}');
    }
}
