<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\InvalidPeriodException;
use App\Finance\Period;
use App\Finance\PeriodResolver;
use App\Finance\TransactionFilter;
use App\Http\Htmx;
use App\Money\MoneyFormatter;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Transaction\TransactionInput;
use App\Transaction\TransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Transactions list (design screens/transactions.html) and the add/edit/delete dialogs
 * (screens/transaction-form.html). Every lookup is scoped to the signed-in account: another
 * account's transaction id is "not found".
 *
 * HTMX requests get the dialog fragment (loaded into #modal-root); without HTMX the list page
 * is rendered with the dialog already open, so everything also works as plain HTML forms.
 */
final class TransactionController extends AbstractController
{
    public const PAGE = 25;
    public const DEFAULT_PERIOD = 'this-year';

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly CategoryRepository $categories,
        private readonly TransactionService $service,
        private readonly PeriodResolver $periods,
        private readonly MoneyFormatter $money,
        private readonly Htmx $htmx,
    ) {
    }

    #[Route('/transactions', name: 'app_transactions', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->renderList($request, $user);
    }

    #[Route('/transactions/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $input = $request->isMethod('POST')
            ? TransactionInput::fromArray($request->request->all())
            : $this->service->blank((string) $request->query->get('type', 'expense'));
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('transaction', (string) $request->request->get('_token'))) {
                $errors['form'] = 'Your session expired. Reload the page and try again.';
            } else {
                $result = $this->service->create($user, $input);
                if ($result instanceof Transaction) {
                    return $this->htmx->done('Transaction added', $this->summary($result, false), $this->generateUrl('app_transactions'));
                }
                $errors = $result;
            }
        }

        return $this->renderForm($request, $user, $input, $errors, null);
    }

    #[Route('/transactions/{id}/edit', name: 'app_transaction_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        $tx = $this->owned($user, $id);
        $input = $request->isMethod('POST') ? TransactionInput::fromArray($request->request->all()) : TransactionService::inputFrom($tx);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('transaction', (string) $request->request->get('_token'))) {
                $errors['form'] = 'Your session expired. Reload the page and try again.';
            } else {
                $result = $this->service->update($tx, $input);
                if ($result instanceof Transaction) {
                    return $this->htmx->done('Transaction updated', $this->summary($result, false), $this->generateUrl('app_transactions'));
                }
                $errors = $result;
            }
        }

        return $this->renderForm($request, $user, $input, $errors, $tx);
    }

    #[Route('/transactions/{id}/delete', name: 'app_transaction_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        $tx = $this->owned($user, $id);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('delete-transaction-'.$tx->getId(), (string) $request->request->get('_token'))) {
                return $this->htmx->done('Transaction not deleted', 'Your session expired. Reload the page and try again.', $this->generateUrl('app_transactions'), 'error');
            }
            $message = $this->summary($tx, true);
            $this->service->delete($tx);

            return $this->htmx->done('Transaction deleted', $message, $this->generateUrl('app_transactions'));
        }

        if (Htmx::isHtmx($request)) {
            return $this->render('transactions/_delete_modal.html.twig', ['tx' => $tx, 'open' => true]);
        }

        return $this->renderList($request, $user, ['delete_tx' => $tx]);
    }

    /** @param array<string, string> $errors */
    private function renderForm(Request $request, User $user, TransactionInput $input, array $errors, ?Transaction $tx): Response
    {
        $status = $errors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $vars = [
            'input' => $input,
            'errors' => $errors,
            'tx' => $tx,
            'categories' => $this->categoryOptions($user),
        ];
        if (Htmx::isHtmx($request)) {
            return $this->render('transactions/_form_modal.html.twig', $vars + ['open' => true], new Response(status: $status));
        }

        return $this->renderList($request, $user, ['form' => $vars], $status);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderList(Request $request, User $user, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        $q = $request->query;
        $rangeError = null;
        try {
            $period = $this->periods->resolve((string) $q->get('period', self::DEFAULT_PERIOD), $q->getString('from') ?: null, $q->getString('to') ?: null, self::DEFAULT_PERIOD);
        } catch (InvalidPeriodException $e) {
            $rangeError = $e->getMessage();
            $period = $this->periods->resolve(self::DEFAULT_PERIOD);
        }

        $type = TransactionType::tryFrom((string) $q->get('type', ''));
        $category = ctype_digit((string) $q->get('category', '')) ? $this->categories->findOwned($user, (int) $q->get('category')) : null;
        if ($category && $type && $category->getType() !== $type) {
            $category = null; // the category belongs to the other type: drop it (as the design does)
        }
        $limit = max(self::PAGE, min(1000, (int) ceil(max(1, $q->getInt('limit', self::PAGE)) / self::PAGE) * self::PAGE));

        $filter = TransactionFilter::forPeriod($period, $type, $category);
        $totals = $this->transactions->totals($user, $filter);
        $rows = $totals['count'] > 0 ? $this->transactions->findFiltered($user, $filter, $limit) : [];

        $query = $this->filterQuery($period, $type, $category);
        $filtered = self::DEFAULT_PERIOD !== $period->key || $type || $category;

        return $this->render('transactions/index.html.twig', [
            'nav' => 'transactions',
            'period' => $period,
            'range_error' => $rangeError,
            'type' => $type?->value ?? 'all',
            'category' => $category,
            'categories' => $this->categoryOptions($user),
            'query' => $query,
            'filtered' => $filtered,
            'totals' => $totals,
            'rows' => $rows,
            'limit' => $limit,
            'page_size' => self::PAGE,
            'has_any' => $totals['count'] > 0 || $this->transactions->hasAny($user),
        ] + $extra, new Response(status: $status));
    }

    /** @return array<string, string> query parameters that reproduce the current filters */
    private function filterQuery(Period $period, ?TransactionType $type, ?Category $category): array
    {
        $q = $period->query();
        if (self::DEFAULT_PERIOD === $q['period']) {
            unset($q['period']);
        }
        if ($type) {
            $q['type'] = $type->value;
        }
        if ($category) {
            $q['category'] = (string) $category->getId();
        }

        return $q;
    }

    /** @return array{expense: list<Category>, income: list<Category>} */
    private function categoryOptions(User $user): array
    {
        return [
            'expense' => $this->categories->findByOwnerAndType($user, TransactionType::Expense),
            'income' => $this->categories->findByOwnerAndType($user, TransactionType::Income),
        ];
    }

    private function owned(User $user, int $id): Transaction
    {
        return $this->transactions->findOwned($user, $id) ?? throw new NotFoundHttpException('Transaction not found.');
    }

    /** "−$79.50 · Dining out · Nov 4" (toast message, design). */
    private function summary(Transaction $tx, bool $withDate): string
    {
        $text = $this->money->signed($tx->getAmountMinor(), $tx->getType()->value, $tx->getOwner()->getCurrency()).' · '.$tx->getCategory()->getName();

        return $withDate ? $text.' · '.$tx->getDate()->format('M j') : $text;
    }
}
