<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\TechDemoPing;

/**
 * TEMPORARY (stack demo): turns pings into a Chart.js dataset — number of pings
 * per minute for the last $minutes minutes, oldest first.
 */
final class ChartDataBuilder
{
    /**
     * @param iterable<TechDemoPing> $pings
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    public function pingsPerMinute(iterable $pings, \DateTimeImmutable $now, int $minutes = 10): array
    {
        if ($minutes < 1) {
            throw new \InvalidArgumentException('Minutes must be positive.');
        }

        $end = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        $buckets = [];
        for ($i = $minutes - 1; $i >= 0; --$i) {
            $buckets[$end->modify("-{$i} minutes")->format('H:i')] = 0;
        }

        foreach ($pings as $ping) {
            $key = $ping->getCreatedAt()->setTimezone($now->getTimezone())->format('H:i');
            $age = $end->getTimestamp() - $ping->getCreatedAt()->getTimestamp();
            if (\array_key_exists($key, $buckets) && $age < $minutes * 60) {
                ++$buckets[$key];
            }
        }

        return ['labels' => array_keys($buckets), 'data' => array_values($buckets)];
    }
}
