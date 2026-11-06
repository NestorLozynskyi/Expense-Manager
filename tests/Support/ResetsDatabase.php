<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Empties all application tables before each test (schema is created once by tests/bootstrap.php).
 *
 * @mixin KernelTestCase
 */
trait ResetsDatabase
{
    protected static function resetDatabase(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            if ('doctrine_migration_versions' === $table) {
                continue;
            }
            $connection->executeStatement('DELETE FROM '.$connection->quoteSingleIdentifier($table));
        }
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        // Rate limiter state (demo start, login throttling) must not leak between tests.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }
}
