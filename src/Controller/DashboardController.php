<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\BudgetService;
use App\Dashboard\DashboardService;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\DateFormat;
use App\Finance\InvalidPeriodException;
use App\Finance\Period;
use App\Finance\PeriodResolver;
use App\Http\Htmx;
use App\Money\MoneyFormatter;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Transaction\TransactionInput;
use App\Transaction\TransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Dashboard (design screens/dashboard.html): totals with comparison, charts, current-month
 * budgets, recent transactions and the quick-add form. Every number is for the signed-in account.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly PeriodResolver $periods,
        private readonly BudgetService $budgets,
        private readonly TransactionRepository $transactions,
        private readonly CategoryRepository $categories,
        private readonly TransactionService $transactionService,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $rangeError = null;
        try {
            $period = $this->periods->resolve($request->query->getString('period') ?: null, $request->query->getString('from') ?: null, $request->query->getString('to') ?: null);
        } catch (InvalidPeriodException $e) {
            $rangeError = 'End date must be on or after the start date';
            $period = $this->periods->resolve('this-month');
        }

        return $this->render('dashboard/index.html.twig', $this->viewData($user, $period, $this->transactionService->blank()) + [
            'range_error' => $rangeError,
        ], new Response(status: $rangeError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Quick add: today's date, no note. HTMX: on success the toast is shown and the dashboard
     * content reloaded; on validation errors only the quick-add card is re-rendered.
     */
    #[Route('/dashboard/quick-add', name: 'app_dashboard_quick_add', methods: ['POST'])]
    public function quickAdd(Request $request, #[CurrentUser] User $user, Htmx $htmx): Response
    {
        if (!$this->isCsrfTokenValid('quick-add', $request->request->getString('_token'))) {
            return $htmx->done('Your session expired', 'Reload the page and try again.', $this->generateUrl('app_dashboard'), 'error');
        }
        $input = TransactionInput::fromArray($request->request->all());
        $input->date = $this->periods->today()->format('Y-m-d');
        $input->note = '';
        $result = $this->transactionService->create($user, $input);

        if (\is_array($result)) {
            $vars = ['quick' => $input, 'quick_errors' => $result, 'today' => $this->periods->today()] + $this->quickCategories($user);
            if (Htmx::isHtmx($request)) {
                return $this->render('dashboard/_quick_add.html.twig', $vars, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
            $period = $this->periods->resolve('this-month');

            return $this->render('dashboard/index.html.twig', $this->viewData($user, $period, $input) + $vars + ['range_error' => null], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $title = ($result->getType() === TransactionType::Income ? 'Income' : 'Expense').' added — '
            .(new MoneyFormatter())->format($result->getAmountMinor(), $user->getCurrency()).' · '.$result->getCategory()->getName();
        $message = null;
        $current = $request->headers->get('HX-Current-URL');
        if ($current) {
            parse_str((string) parse_url($current, \PHP_URL_QUERY), $q);
            try {
                $shown = $this->periods->resolve(\is_string($q['period'] ?? null) ? $q['period'] : null, \is_string($q['from'] ?? null) ? $q['from'] : null, \is_string($q['to'] ?? null) ? $q['to'] : null);
                if ($result->getDate() < $shown->from || $result->getDate() > $shown->to) {
                    $message = 'Dated '.DateFormat::medium($result->getDate()).' — outside the selected period, so totals here don’t change.';
                }
            } catch (InvalidPeriodException) {
            }
        }

        return $htmx->done($title, $message, $this->generateUrl('app_dashboard'));
    }

    /** @return array<string, mixed> */
    private function viewData(User $user, Period $period, TransactionInput $quick): array
    {
        $currentMonth = $this->periods->currentMonth();

        return [
            'nav' => 'dashboard',
            'period' => $period,
            'has_any' => $this->transactions->hasAny($user),
            'totals' => $this->dashboard->totals($user, $period),
            'donut' => $this->dashboard->spendingByCategory($user, $period),
            'monthly' => $this->dashboard->monthly($user, $period),
            'budgets' => $this->budgets->forMonth($user, $currentMonth),
            'recent' => $this->dashboard->recent($user, $period),
            'today' => $this->periods->today(),
            'quick' => $quick,
            'quick_errors' => [],
        ] + $this->quickCategories($user);
    }

    /** @return array{quick_categories: array<string, list<array{id: int, name: string}>>} */
    private function quickCategories(User $user): array
    {
        $out = ['expense' => [], 'income' => []];
        foreach ($this->categories->findByOwner($user) as $c) {
            $out[$c->getType()->value][] = ['id' => (int) $c->getId(), 'name' => $c->getName()];
        }

        return ['quick_categories' => $out];
    }
}
