<?php

declare(strict_types=1);

namespace App\Demo;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Deletes expired demo accounts with all their data (runs every minute via Symfony Scheduler,
 * see App\Schedule). Deletion order respects foreign keys; each account is one DB transaction.
 */
final readonly class DemoPurger
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    /** @return int number of deleted demo accounts */
    public function purgeExpired(): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM "user" WHERE demo_expires_at IS NOT NULL AND demo_expires_at <= ? LIMIT 500',
            [$now],
        );
        foreach ($ids as $id) {
            $this->deleteAccount((int) $id);
        }

        return \count($ids);
    }

    public function deleteAccount(int $userId): void
    {
        $this->connection->transactional(static function (Connection $c) use ($userId): void {
            $c->executeStatement('DELETE FROM money_transaction WHERE owner_id = ?', [$userId]);
            $c->executeStatement('DELETE FROM category_budget WHERE plan_id IN (SELECT id FROM budget_plan WHERE owner_id = ?)', [$userId]);
            $c->executeStatement('DELETE FROM budget_plan WHERE owner_id = ?', [$userId]);
            $c->executeStatement('DELETE FROM category WHERE owner_id = ?', [$userId]);
            $c->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);
        });
    }
}
