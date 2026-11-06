<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\BudgetLimit;
use App\Budget\BudgetService;
use App\Budget\MonthBudgets;
use App\Entity\Category;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\DateFormat;
use App\Finance\PeriodResolver;
use App\Http\Htmx;
use App\Money\Currencies;
use App\Money\MoneyFormatter;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Budgets screen (design screens/budgets.html): month picker, over-budget banner, overall and
 * per-category monthly budgets; set / edit / delete dialogs (HTMX into #modal-root, full page
 * without JavaScript). All lookups are scoped to the signed-in account.
 */
#[Route('/budgets')]
final class BudgetController extends AbstractController
{
    private const OVERALL = 'overall';

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly CategoryRepository $categories,
        private readonly PeriodResolver $periods,
        private readonly MoneyFormatter $money,
        private readonly Htmx $htmx,
    ) {
    }

    #[Route('', name: 'app_budgets', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, #[MapQueryParameter] ?string $month = null): Response
    {
        return $this->render('budgets/index.html.twig', $this->page($user, $month));
    }

    /** "Set budget": overall or a category without a budget in the month. */
    #[Route('/new', name: 'app_budget_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, #[MapQueryParameter] ?string $month = null): Response
    {
        $page = $this->page($user, $month);
        /** @var MonthBudgets $mb */
        $mb = $page['budgets'];
        $available = $page['available'];
        $hasOverall = null !== $mb->overall;

        $wanted = (string) ($request->request->get('scope') ?? $request->query->get('scope', ''));
        $scope = match (true) {
            self::OVERALL === $wanted && !$hasOverall => self::OVERALL,
            'category' === $wanted && $available => 'category',
            default => $available ? 'category' : self::OVERALL,
        };
        $form = ['scope' => $scope, 'category' => '', 'limit' => ''];
        $errors = [];

        if ($request->isMethod('POST')) {
            $form['category'] = (string) $request->request->get('category', '');
            $form['limit'] = (string) $request->request->get('limit', '');
            if (!$this->isCsrfTokenValid('budget', (string) $request->request->get('_token'))) {
                $errors['limit'] = 'Your session expired. Reload the page and try again.';
            }
            $category = null;
            if (self::OVERALL === $scope) {
                if ($hasOverall) {
                    $errors['scope'] = 'An overall budget is already set for '.$mb->label().' — edit it from its card.';
                }
            } else {
                $category = $this->findAvailable($available, $form['category']);
                if (!$category) {
                    $errors['category'] = 'Choose a category';
                }
            }
            $limit = BudgetLimit::parse($form['limit'], $user->getCurrency(), $this->money);
            if (\is_string($limit)) {
                $errors['limit'] = $limit;
            }
            if (!$errors && \is_int($limit)) {
                if ($category) {
                    $this->budgets->setCategoryLimit($user, $mb->month, $category, $limit);
                } else {
                    $this->budgets->setOverall($user, $mb->month, $limit);
                }
                $name = $category ? $category->getName() : 'Overall';

                return $this->htmx->done($name.' budget set', $this->money->format($limit, $user->getCurrency()).' limit for '.$mb->label().'.', $this->backUrl($mb->month));
            }
        }

        return $this->dialog($request, $page, 'budgets/_form_dialog.html.twig', [
            'mode' => 'set',
            'form' => $form,
            'errors' => $errors,
            'has_overall' => $hasOverall,
            'action' => $this->generateUrl('app_budget_new', ['month' => $mb->month]),
        ], $errors);
    }

    #[Route('/{target}/edit', name: 'app_budget_edit', requirements: ['target' => 'overall|\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, string $target, #[MapQueryParameter] ?string $month = null): Response
    {
        $page = $this->page($user, $month);
        /** @var MonthBudgets $mb */
        $mb = $page['budgets'];
        [$category, $status] = $this->target($user, $mb, $target);

        $form = ['limit' => MoneyFormatter::toInput($status->limit, $user->getCurrency())];
        $errors = [];
        if ($request->isMethod('POST')) {
            $form['limit'] = (string) $request->request->get('limit', '');
            if (!$this->isCsrfTokenValid('budget', (string) $request->request->get('_token'))) {
                $errors['limit'] = 'Your session expired. Reload the page and try again.';
            }
            $limit = BudgetLimit::parse($form['limit'], $user->getCurrency(), $this->money);
            if (\is_string($limit)) {
                $errors['limit'] = $limit;
            }
            if (!$errors && \is_int($limit)) {
                if ($category) {
                    $this->budgets->setCategoryLimit($user, $mb->month, $category, $limit);
                } else {
                    $this->budgets->setOverall($user, $mb->month, $limit);
                }
                $name = $category ? $category->getName() : 'Overall';

                return $this->htmx->done($name.' budget updated', 'New limit '.$this->money->format($limit, $user->getCurrency()).' for '.$mb->label().'.', $this->backUrl($mb->month));
            }
        }

        return $this->dialog($request, $page, 'budgets/_form_dialog.html.twig', [
            'mode' => 'edit',
            'form' => $form,
            'errors' => $errors,
            'target' => $target,
            'category' => $category,
            'status' => $status,
            'action' => $this->generateUrl('app_budget_edit', ['target' => $target, 'month' => $mb->month]),
        ], $errors);
    }

    #[Route('/{target}/delete', name: 'app_budget_delete', requirements: ['target' => 'overall|\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, string $target, #[MapQueryParameter] ?string $month = null): Response
    {
        $page = $this->page($user, $month);
        /** @var MonthBudgets $mb */
        $mb = $page['budgets'];
        [$category] = $this->target($user, $mb, $target);
        $errors = [];

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('budget', (string) $request->request->get('_token'))) {
                if ($category) {
                    $this->budgets->removeCategoryLimit($user, $mb->month, $category);
                } else {
                    $this->budgets->removeOverall($user, $mb->month);
                }
                $name = $category ? $category->getName() : 'Overall';

                return $this->htmx->done($name.' budget removed', $mb->label().' · transactions unchanged.', $this->backUrl($mb->month));
            }
            $errors['_token'] = 'Your session expired. Reload the page and try again.';
        }

        return $this->dialog($request, $page, 'budgets/_delete_dialog.html.twig', [
            'target' => $target,
            'category' => $category,
            'errors' => $errors,
            'action' => $this->generateUrl('app_budget_delete', ['target' => $target, 'month' => $mb->month]),
        ], $errors);
    }

    /**
     * Everything the Budgets page needs for one month (clamped to the picker range).
     *
     * @return array<string, mixed>
     */
    private function page(User $user, ?string $month): array
    {
        $current = $this->periods->currentMonth();
        $range = $this->budgets->monthRangeFor($user, $current);
        $month = BudgetService::isValidMonth($month) ? $month : $current;
        $month = max($range['min'], min($range['max'], $month));
        $mb = $this->budgets->forMonth($user, $month);

        return [
            'nav' => 'budgets',
            'currency_digits' => Currencies::fractionDigits($user->getCurrency()),
            'budgets' => $mb,
            'available' => $this->budgets->availableCategories($user, $mb),
            'month' => $month,
            'current_month' => $current,
            'min_month' => $range['min'],
            'max_month' => $range['max'],
            'prev_month' => BudgetService::addMonths($month, -1),
            'next_month' => BudgetService::addMonths($month, 1),
            'review_query' => $this->reviewQuery($month, $current),
            'source_label' => $mb->isCarriedOver() && $mb->sourceMonth ? DateFormat::monthLabel($mb->sourceMonth) : null,
        ];
    }

    /**
     * Dialog response: the modal alone for HTMX (422 when invalid so HTMX re-renders it),
     * otherwise the Budgets page with the dialog open (works without JavaScript).
     *
     * @param array<string, mixed>  $page
     * @param array<string, mixed>  $vars
     * @param array<string, string> $errors
     */
    private function dialog(Request $request, array $page, string $template, array $vars, array $errors): Response
    {
        $status = $errors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $vars += ['back' => $this->backUrl($page['month'])];
        if (Htmx::isHtmx($request)) {
            return $this->render($template, $page + $vars + ['standalone' => false], new Response(status: $status));
        }

        return $this->render('budgets/index.html.twig', $page + ['dialog' => ['template' => $template, 'vars' => $vars + ['standalone' => true]]], new Response(status: $status));
    }

    /**
     * @return array{0: ?Category, 1: \App\Budget\BudgetStatus}
     */
    private function target(User $user, MonthBudgets $mb, string $target): array
    {
        if (self::OVERALL === $target) {
            if (!$mb->overall) {
                throw $this->createNotFoundException('No overall budget in this month.');
            }

            return [null, $mb->overall];
        }
        $category = $this->categories->findOwned($user, (int) $target);
        $status = $category ? $mb->forCategoryId((int) $category->getId()) : null;
        if (!$category || !$status || TransactionType::Expense !== $category->getType()) {
            throw $this->createNotFoundException('No such budget.');
        }

        return [$category, $status];
    }

    /** @param list<Category> $available */
    private function findAvailable(array $available, string $id): ?Category
    {
        foreach ($available as $c) {
            if ((string) $c->getId() === $id) {
                return $c;
            }
        }

        return null;
    }

    private function backUrl(string $month): string
    {
        return $this->generateUrl('app_budgets', ['month' => $month]);
    }

    /** @return array<string, string> transactions period filter covering the month */
    private function reviewQuery(string $month, string $current): array
    {
        if ($month === $current) {
            return ['period' => 'this-month'];
        }
        if ($month === BudgetService::addMonths($current, -1)) {
            return ['period' => 'last-month'];
        }
        [$from, $to] = BudgetService::monthRange($month);

        return ['period' => 'custom', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }
}
