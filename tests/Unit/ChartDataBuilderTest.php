<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Demo\ChartDataBuilder;
use App\Entity\TechDemoPing;
use PHPUnit\Framework\TestCase;

final class ChartDataBuilderTest extends TestCase
{
    public function testBucketsPingsPerMinuteForTheLastMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 12:30:40', new \DateTimeZone('UTC'));
        $pings = [
            new TechDemoPing(new \DateTimeImmutable('2026-01-01 12:30:05', new \DateTimeZone('UTC'))),
            new TechDemoPing(new \DateTimeImmutable('2026-01-01 12:30:39', new \DateTimeZone('UTC'))),
            new TechDemoPing(new \DateTimeImmutable('2026-01-01 12:28:00', new \DateTimeZone('UTC'))),
            new TechDemoPing(new \DateTimeImmutable('2026-01-01 12:20:00', new \DateTimeZone('UTC'))), // outside window
        ];

        $result = (new ChartDataBuilder())->pingsPerMinute($pings, $now, 3);

        self::assertSame(['12:28', '12:29', '12:30'], $result['labels']);
        self::assertSame([1, 0, 2], $result['data']);
    }

    public function testRejectsNonPositiveWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ChartDataBuilder())->pingsPerMinute([], new \DateTimeImmutable(), 0);
    }
}
