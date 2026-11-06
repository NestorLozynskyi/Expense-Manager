<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Budget\BudgetLimit;
use App\Budget\BudgetStatus;
use App\Money\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetStatusTest extends TestCase
{
    /** @return iterable<string, array{int, int, string, float}> limit, spent (minor units), state, pct */
    public static function thresholds(): iterable
    {
        yield '79.9% → on track' => [100000, 79900, BudgetStatus::OK, 79.9];
        yield '80% → near limit' => [100000, 80000, BudgetStatus::WARNING, 80.0];
        yield '100% → near limit' => [100000, 100000, BudgetStatus::WARNING, 100.0];
        yield '100.01% → over' => [100000, 100010, BudgetStatus::OVER, 100.0];
        yield 'nothing spent' => [15000, 0, BudgetStatus::OK, 0.0];
        yield 'dining out 124.3%' => [15000, 18640, BudgetStatus::OVER, 124.3];
        yield 'transport 81.7%' => [12000, 9800, BudgetStatus::WARNING, 81.7];
    }

    #[DataProvider('thresholds')]
    public function testStateThresholds(int $limit, int $spent, string $state, float $pct): void
    {
        $s = new BudgetStatus($limit, $spent);

        self::assertSame($state, $s->state);
        self::assertSame($pct, $s->pct);
    }

    public function testRemainingAndOverAmounts(): void
    {
        $over = new BudgetStatus(15000, 18640);
        self::assertSame(0, $over->remaining);
        self::assertSame(3640, $over->over);
        self::assertSame(100.0, $over->fillPct());
        self::assertSame(19.5, $over->overflowPct()); // 36.40 / 186.40 (hatched share of the bar)

        $near = new BudgetStatus(12000, 9800);
        self::assertSame(2200, $near->remaining);
        self::assertSame(0, $near->over);
        self::assertSame(81.7, $near->fillPct());
        self::assertSame(0.0, $near->overflowPct());
    }

    public function testLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BudgetStatus(0, 10);
    }

    /** @return iterable<string, array{string, string, int|string}> */
    public static function limits(): iterable
    {
        yield 'plain' => ['150', 'USD', 15000];
        yield 'with symbol and comma' => ['$1,234.5', 'USD', 123450];
        yield 'empty' => ['  ', 'USD', 'Enter a monthly limit'];
        yield 'zero' => ['0', 'USD', 'Enter a limit greater than 0'];
        yield 'not a number' => ['abc', 'USD', 'Enter a limit greater than 0'];
        yield 'negative' => ['-5', 'USD', 'Enter a limit greater than 0'];
        yield 'too many decimals' => ['1.234', 'USD', 'Use no more than 2 decimal places'];
        yield 'too big' => ['100000000', 'USD', 'Enter a limit below $100,000,000'];
        yield 'yen has no minor units' => ['5000', 'JPY', 5000];
        yield 'hryvnia' => ['₴45,000', 'UAH', 4500000];
    }

    #[DataProvider('limits')]
    public function testLimitParsing(string $input, string $currency, int|string $expected): void
    {
        self::assertSame($expected, BudgetLimit::parse($input, $currency, new MoneyFormatter()));
    }
}
