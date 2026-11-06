<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Remembers in the session which demo account this browser uses and when it ends, so the
 * "demo ended" screen can be shown even after the scheduler already deleted the account.
 */
final class DemoSession
{
    public const KEY = '_demo';

    public function start(Request $request, User $demo): void
    {
        $request->getSession()->set(self::KEY, ['id' => $demo->getId(), 'until' => $demo->getDemoExpiresAt()?->getTimestamp()]);
    }

    /** @return array{id: int, until: int}|null */
    public function get(Request $request): ?array
    {
        if (!$request->hasPreviousSession()) {
            return null;
        }
        $value = $request->getSession()->get(self::KEY);

        return \is_array($value) && \is_int($value['id'] ?? null) && \is_int($value['until'] ?? null) ? $value : null;
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::KEY);
        }
    }
}
