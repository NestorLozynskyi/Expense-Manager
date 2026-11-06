<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Parallel test runs with their own database (TEST_DB_NAME, see .env.test) also get their own
     * container cache, so they don't rebuild each other's cache mid-run.
     */
    public function getCacheDir(): string
    {
        $name = $_SERVER['TEST_DB_NAME'] ?? $_ENV['TEST_DB_NAME'] ?? null;
        if ('test' === $this->environment && \is_string($name) && 1 === preg_match('/^test_[a-z0-9_]+$/', $name)) {
            return $this->getProjectDir().'/var/cache/'.$name;
        }

        return parent::getCacheDir();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
