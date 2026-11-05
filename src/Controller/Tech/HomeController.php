<?php

declare(strict_types=1);

namespace App\Controller\Tech;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TEMPORARY: until the product home page exists, "/" points to the stack demo page.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('tech_demo');
    }
}
