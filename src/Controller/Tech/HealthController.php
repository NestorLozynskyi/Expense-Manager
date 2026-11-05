<?php

declare(strict_types=1);

namespace App\Controller\Tech;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness/readiness probe used by the Docker healthcheck.
 */
final class HealthController extends AbstractController
{
    #[Route('/healthz', name: 'tech_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        $connection->executeQuery('SELECT 1')->fetchOne();

        return new JsonResponse(['status' => 'ok']);
    }
}
