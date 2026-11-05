<?php

declare(strict_types=1);

namespace App\Controller\Tech;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TECHNICAL protected route proving the security setup (access_control ^/app + IsGranted).
 * Replace with real private pages once the product UI exists.
 */
final class SecureCheckController extends AbstractController
{
    #[Route('/app/tech/secure-check', name: 'tech_secure_check', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'authenticated' => true,
            'email' => $user->getEmail(),
        ]);
    }
}
