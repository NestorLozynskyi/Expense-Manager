<?php

declare(strict_types=1);

namespace App\Demo;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeExpiredDemosHandler
{
    public function __construct(private DemoPurger $purger, private LoggerInterface $logger)
    {
    }

    public function __invoke(PurgeExpiredDemos $message): void
    {
        $deleted = $this->purger->purgeExpired();
        if ($deleted > 0) {
            $this->logger->info('Deleted {count} expired demo account(s).', ['count' => $deleted]);
        }
    }
}
