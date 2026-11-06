<?php

declare(strict_types=1);

namespace App\Money;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies as IntlCurrencies;

/**
 * Active ISO 4217 currencies (legal tender somewhere today), from Symfony Intl / ICU data.
 */
final class Currencies
{
    /** Shown first in the picker (the design's example list order). */
    private const PREFERRED = ['USD', 'EUR', 'UAH', 'GBP', 'PLN', 'CHF', 'CAD', 'AUD', 'JPY', 'CZK', 'SEK', 'NOK', 'DKK', 'TRY', 'INR', 'BRL', 'MXN', 'CNY'];

    /** @var array<string, array{code: string, name: string, symbol: string}>|null */
    private ?array $all = null;

    /** @return array<string, array{code: string, name: string, symbol: string}> */
    public function all(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }
        $codes = [];
        foreach (Countries::getCountryCodes() as $country) {
            foreach (IntlCurrencies::forCountry($country) as $code) {
                $codes[$code] = true;
            }
        }
        $codes = array_keys($codes);
        $rank = array_flip(self::PREFERRED);
        usort($codes, static fn (string $a, string $b) => [$rank[$a] ?? 999, $a] <=> [$rank[$b] ?? 999, $b]);

        $out = [];
        foreach ($codes as $code) {
            if (!IntlCurrencies::exists($code)) {
                continue;
            }
            $out[$code] = ['code' => $code, 'name' => self::sentenceCase(IntlCurrencies::getName($code, 'en')), 'symbol' => self::narrowSymbol($code)];
        }

        return $this->all = $out;
    }

    /** @return list<string> active ISO 4217 codes (validator choice callback) */
    public static function codes(): array
    {
        return array_keys((new self())->all());
    }

    public function isValid(string $code): bool
    {
        return isset($this->all()[strtoupper($code)]);
    }

    public function label(string $code): string
    {
        $c = $this->all()[$code] ?? null;

        return $c ? $code.' — '.$c['name'] : $code;
    }

    public static function fractionDigits(string $code): int
    {
        return IntlCurrencies::getFractionDigits($code);
    }

    public static function narrowSymbol(string $code): string
    {
        $formatted = (new \MessageFormatter('en_US', '{0, number, ::currency/'.$code.' unit-width-narrow precision-integer}'))->format([0]);

        return trim(str_replace('0', '', (string) $formatted)) ?: $code;
    }

    /** ICU: "US Dollar" → design: "US dollar"; keeps proper nouns' first letter. */
    private static function sentenceCase(string $name): string
    {
        $words = explode(' ', $name);
        $last = array_key_last($words);
        if (null !== $last && \in_array(mb_strtolower($words[$last]), ['dollar', 'euro', 'pound', 'franc', 'krona', 'krone', 'koruna', 'lira', 'rupee', 'real', 'peso', 'yuan', 'yen', 'hryvnia', 'zloty', 'złoty'], true) && \count($words) > 1) {
            $words[$last] = mb_strtolower($words[$last]);
        }

        return implode(' ', $words);
    }
}
