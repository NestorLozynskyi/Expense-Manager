<?php

declare(strict_types=1);

namespace App;

use App\Demo\PurgeExpiredDemos;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Background jobs, consumed by the `scheduler` Docker service:
 *   php bin/console messenger:consume scheduler_default
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Demo accounts live 24 h; delete expired ones (and all their data) every minute.
            ->add(RecurringMessage::every('1 minute', new PurgeExpiredDemos()));
    }
}
