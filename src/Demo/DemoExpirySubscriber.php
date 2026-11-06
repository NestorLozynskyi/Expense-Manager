<?php

declare(strict_types=1);

namespace App\Demo;

use App\Http\Htmx;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ends an expired demo session on the next request: deletes the account right away (the
 * scheduler deletes expired demos anyway, without any request), signs out and shows the
 * "demo ended" screen — also when the scheduler already deleted the account.
 * Runs before the firewall (priority 8), so it works even when the account no longer exists.
 */
final readonly class DemoExpirySubscriber
{
    public function __construct(
        private ClockInterface $clock,
        private DemoSession $demoSession,
        private DemoPurger $purger,
        private UrlGeneratorInterface $urls,
        private UserRepository $users,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'app_demo_ended' === $request->attributes->get('_route')) {
            return;
        }
        $demo = $this->demoSession->get($request);
        if (null === $demo) {
            return;
        }
        $user = $this->users->find($demo['id']);
        if ($user && !$user->isDemoExpired(\DateTimeImmutable::createFromInterface($this->clock->now()))) {
            return;
        }

        if ($user) {
            $this->purger->deleteAccount($demo['id']);
        }
        $request->getSession()->invalidate();
        $event->setResponse(Htmx::redirect($request, $this->urls->generate('app_demo_ended')));
    }
}
