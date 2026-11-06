<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoBannerTest extends TestCase
{
    /** @return iterable<array{int, string, string}> */
    public static function cases(): iterable
    {
        yield 'full day' => [86400, '24 h', '24 h'];
        yield 'design example' => [23 * 3600 + 41 * 60, '23 h 41 min', '23 h'];
        yield 'rounds up to the minute' => [23 * 3600 + 40 * 60 + 1, '23 h 41 min', '23 h'];
        yield 'ten minutes' => [600, '10 min', '10 min'];
        yield 'last seconds' => [5, '1 min', '1 min'];
    }

    #[DataProvider('cases')]
    public function testWording(int $seconds, string $long, string $short): void
    {
        self::assertSame(['long' => $long, 'short' => $short], AppExtension::demoLeft($seconds));
    }
}
