<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\DemoAccountFactory;
use App\Demo\DemoSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Try demo": every start creates a separate temporary account (USD, sample data, 24 h).
 * POST + CSRF so links, prefetchers and crawlers can't create accounts; rate-limited per IP
 * and globally.
 */
final class DemoController extends AbstractController
{
    #[Route('/demo', name: 'app_demo_start', methods: ['POST'])]
    public function start(
        Request $request,
        DemoAccountFactory $factory,
        DemoSession $demoSession,
        Security $security,
        #[Target('demo_start_ip')] RateLimiterFactoryInterface $perIp,
        #[Target('demo_start_global')] RateLimiterFactoryInterface $global,
    ): Response {
        if (!$this->isCsrfTokenValid('demo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired|Reload the page and try again.');

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }

        $ip = $perIp->create((string) $request->getClientIp())->consume();
        $all = $ip->isAccepted() ? $global->create('all')->consume() : $ip;
        if (!$all->isAccepted()) {
            $minutes = max(1, (int) ceil(($all->getRetryAfter()->getTimestamp() - time()) / 60));
            $this->addFlash('error', 'Too many demo sessions right now|Try again in '.$minutes.' min, or create a free account.');

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }

        $user = $factory->create();
        $security->login($user, 'form_login', 'main');
        $demoSession->start($request, $user);

        return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/demo/ended', name: 'app_demo_ended', methods: ['GET'])]
    public function ended(DemoSession $demoSession, Request $request): Response
    {
        $demoSession->forget($request);

        return $this->render('demo/ended.html.twig');
    }
}
