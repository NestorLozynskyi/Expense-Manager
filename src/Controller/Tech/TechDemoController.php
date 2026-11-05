<?php

declare(strict_types=1);

namespace App\Controller\Tech;

use App\Demo\ChartDataBuilder;
use App\Entity\TechDemoPing;
use App\Repository\TechDemoPingRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * TEMPORARY technical page proving the stack: SQLite via Doctrine, HTMX, Alpine.js,
 * Chart.js and Tailwind. No product design; remove when real screens arrive.
 */
#[Route('/tech/demo')]
final class TechDemoController extends AbstractController
{
    private const LATEST_LIMIT = 5;

    public function __construct(
        private readonly TechDemoPingRepository $pings,
        private readonly UserRepository $users,
        private readonly Connection $connection,
        private readonly ChartDataBuilder $chartData,
    ) {
    }

    #[Route('', name: 'tech_demo', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('tech_demo/index.html.twig', [
            'sqlite_version' => $this->connection->executeQuery('SELECT sqlite_version()')->fetchOne(),
            'user_count' => $this->users->count(),
        ] + $this->panelData());
    }

    /**
     * HTMX endpoint: stores a ping in SQLite and returns only the refreshed panel fragment.
     */
    #[Route('/ping', name: 'tech_demo_ping', methods: ['POST'])]
    public function ping(Request $request): Response
    {
        // Plain 403 (not a security exception): without an authenticator entry point
        // the firewall would otherwise turn an invalid token into a 401.
        if (!$this->isCsrfTokenValid('tech_demo_ping', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $this->pings->add(new TechDemoPing());

        if (!$request->headers->has('HX-Request')) {
            return $this->redirectToRoute('tech_demo', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('tech_demo/_ping_panel.html.twig', $this->panelData());
    }

    /**
     * @return array{
     *     ping_count: int,
     *     latest_pings: list<TechDemoPing>,
     *     chart: array{labels: list<string>, data: list<int>},
     *     rendered_at: \DateTimeImmutable,
     * }
     */
    private function panelData(): array
    {
        $now = new \DateTimeImmutable();

        return [
            'ping_count' => $this->pings->count(),
            'latest_pings' => $this->pings->findLatest(self::LATEST_LIMIT),
            'chart' => $this->chartData->pingsPerMinute($this->pings->findCreatedSince($now->modify('-10 minutes')), $now),
            'rendered_at' => $now,
        ];
    }
}
