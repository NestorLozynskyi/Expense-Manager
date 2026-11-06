<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Finance\DateFormat;
use App\Finance\PeriodResolver;
use App\Money\Currencies;
use App\Money\MoneyFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template helpers mirroring the design runtime (EM.renderIcon, EM.fmt, EM.fmtSigned, EM.fmtDate).
 * Amounts are integers in minor units of the signed-in account's currency.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly Currencies $currencies,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly PeriodResolver $periods,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('currency', $this->currency(...)),
            new TwigFunction('currency_symbol', static fn (?string $code = null) => Currencies::narrowSymbol($code ?? 'USD')),
            new TwigFunction('currency_label', fn (string $code) => $this->currencies->label($code)),
            new TwigFunction('currencies', fn () => $this->currencies->all()),
            new TwigFunction('now_ts', fn () => $this->clock->now()->getTimestamp()),
            new TwigFunction('demo_left', self::demoLeft(...)),
            new TwigFunction('period_presets', $this->periodPresets(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', fn (int $minor, ?string $currency = null) => $this->money->format($minor, $currency ?? $this->currency())),
            new TwigFilter('money_signed', fn (int $minor, string $type, ?string $currency = null) => $this->money->signed($minor, $type, $currency ?? $this->currency())),
            new TwigFilter('money_signed_value', fn (int $minor, ?string $currency = null) => $this->money->signedValue($minor, $currency ?? $this->currency())),
            new TwigFilter('money_input', fn (?int $minor, ?string $currency = null) => null === $minor ? '' : MoneyFormatter::toInput($minor, $currency ?? $this->currency())),
            new TwigFilter('money_float', fn (int $minor, ?string $currency = null) => MoneyFormatter::toFloat($minor, $currency ?? $this->currency())),
            new TwigFilter('date_medium', DateFormat::medium(...)),
            new TwigFilter('date_short', DateFormat::short(...)),
            new TwigFilter('month_label', DateFormat::monthLabel(...)),
            new TwigFilter('pct', static fn (float $n, int $digits = 0) => number_format($n, $digits, '.', '').'%'),
        ];
    }

    /** <svg class="icon icon--20"><use href="#i-plus"></use></svg> */
    public function icon(string $name, int $size = 20, string $class = ''): string
    {
        $cls = trim('icon icon--'.$size.' '.$class);

        return \sprintf('<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#i-%s"></use></svg>',
            htmlspecialchars($cls, \ENT_QUOTES), $size, $size, htmlspecialchars($name, \ENT_QUOTES));
    }

    /**
     * Demo banner wording: "23 h 41 min" / short "23 h"; under an hour "10 min".
     *
     * @return array{long: string, short: string}
     */
    public static function demoLeft(int $seconds): array
    {
        $mins = max(1, (int) ceil($seconds / 60));
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        if (0 === $h) {
            return ['long' => $m.' min', 'short' => $m.' min'];
        }

        return ['long' => $m ? "$h h $m min" : "$h h", 'short' => "$h h"];
    }

    /** @return list<array{key: string, label: string, range: string}> */
    public function periodPresets(): array
    {
        $out = [];
        foreach (['this-month', 'last-month', 'this-year'] as $key) {
            $p = $this->periods->resolve($key);
            $out[] = ['key' => $key, 'label' => $p->label(), 'range' => $p->range()];
        }

        return $out;
    }

    public function currency(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getCurrency() : 'USD';
    }
}
