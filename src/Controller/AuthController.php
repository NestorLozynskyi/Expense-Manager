<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRegistrar;
use App\Account\RegistrationData;
use App\Budget\BudgetStatus;
use App\Demo\DemoSession;
use App\Entity\User;
use App\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Sign in / sign up page (design screens/auth.html: one card with two tabs).
 */
final class AuthController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute($this->getUser() ? 'app_dashboard' : 'app_login');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->isPermanentUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        $error = $auth->getLastAuthenticationError();

        return $this->render('security/auth.html.twig', [
            'tab' => 'sign-in',
            'last_email' => $auth->getLastUsername(),
            'login_error' => $error?->getMessageKey(),
            'signup' => $this->createForm(RegistrationType::class, new RegistrationData(), ['action' => $this->generateUrl('app_register')])->createView(),
        ] + self::previews(), new Response(status: $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, AccountRegistrar $registrar, Security $security, DemoSession $demo): Response
    {
        if ($this->isPermanentUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($registrar->emailTaken((string) $data->email)) {
                $form->get('email')->addError(new FormError('An account with this email already exists. Sign in instead.'));
            } else {
                $user = $registrar->register($data);
                $demo->forget($request);
                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('security/auth.html.twig', [
            'tab' => 'sign-up',
            'last_email' => '',
            'login_error' => null,
            'signup' => $form->createView(),
        ] + self::previews(), new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the logout listener of the firewall.');
    }

    /**
     * Decorative example budgets of the side panel (design: demo Transport budget in USD and a
     * monthly budget of an account in UAH).
     *
     * @return array{preview_usd: BudgetStatus, preview_uah: BudgetStatus}
     */
    private static function previews(): array
    {
        return ['preview_usd' => new BudgetStatus(12000, 9800), 'preview_uah' => new BudgetStatus(4500000, 3895000)];
    }

    /** Signed-in non-demo users don't need the auth pages; demo users may sign up or switch account. */
    private function isPermanentUser(): bool
    {
        $user = $this->getUser();

        return $user instanceof User && !$user->isDemo();
    }
}
