<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Recreate the separate test SQLite database from the versioned migrations once per run.
// This also proves that the migrations apply cleanly to an empty database.
if ('test' === ($_SERVER['APP_ENV'] ?? null) && !getenv('SKIP_TEST_DB_RESET')) {
    $testDb = dirname(__DIR__).'/var/data/test.db'; // keep in sync with DATABASE_URL in .env.test
    @mkdir(dirname($testDb), 0777, true);
    foreach ([$testDb, $testDb.'-wal', $testDb.'-shm', $testDb.'-journal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach (['doctrine:migrations:migrate --no-interaction --all-or-nothing'] as $command) {
        passthru(sprintf('php %s %s --env=test --quiet', $console, $command), $exitCode);
        if (0 !== $exitCode) {
            fwrite(STDERR, "Test database setup failed: {$command}\n");
            exit($exitCode);
        }
    }
}
