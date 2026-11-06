<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\BudgetService;
use App\Category\CategoryRuleException;
use App\Category\CategoryService;
use App\Category\CustomCategoryStyle;
use App\Entity\Category;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\DateFormat;
use App\Finance\PeriodResolver;
use App\Http\Htmx;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Categories (design screens/categories.html): expense/income tabs, create, rename,
 * delete (simple confirm, or move transactions to another category of the same type first).
 *
 * Dialogs are server-rendered: with HTMX they are loaded into #modal-root and re-rendered with
 * 422 on validation errors; without JavaScript the same URLs render the page with the dialog open.
 * Every category is looked up with the signed-in owner (another account's id → 404).
 */
#[Route('/categories')]
final class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TransactionRepository $transactions,
        private readonly CategoryService $service,
        private readonly BudgetService $budgets,
        private readonly PeriodResolver $periods,
        private readonly Htmx $htmx,
    ) {
    }

    #[Route('', name: 'app_categories', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, Request $request): Response
    {
        return $this->renderPage($user, self::typeOf($request->query->get('type')));
    }

    #[Route('/new', name: 'app_category_new', methods: ['GET', 'POST'])]
    public function new(#[CurrentUser] User $user, Request $request): Response
    {
        $type = self::typeOf($request->request->get('type', $request->query->get('type')));
        $name = (string) $request->request->get('name', '');
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category', (string) $request->request->get('_token'))) {
                $error = 'Your session expired. Reload the page and try again.';
            } else {
                try {
                    $category = $this->service->create($user, $type, $name);

                    return $this->done(
                        'Category created',
                        '“'.$category->getName().'” was added to '.$type->value.' categories.',
                        $this->generateUrl('app_categories', ['type' => $type->value]),
                        forceUrl: true,
                    );
                } catch (CategoryRuleException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->dialog($user, $request, $type, 'categories/_form_dialog.html.twig', [
            'mode' => 'create',
            'type' => $type,
            'name' => $name,
            'error' => $error,
            'previews' => [
                'expense' => CustomCategoryStyle::pick(TransactionType::Expense, $this->categories->nextPosition($user, TransactionType::Expense) - 1),
                'income' => CustomCategoryStyle::pick(TransactionType::Income, $this->categories->nextPosition($user, TransactionType::Income) - 1),
            ],
        ], null !== $error);
    }

    #[Route('/{id<\d+>}/rename', name: 'app_category_rename', methods: ['GET', 'POST'])]
    public function rename(#[CurrentUser] User $user, Request $request, int $id): Response
    {
        $category = $this->owned($user, $id);
        $name = $request->isMethod('POST') ? (string) $request->request->get('name', '') : $category->getName();
        $error = null;

        if ($request->isMethod('POST')) {
            $old = $category->getName();
            if (!$this->isCsrfTokenValid('category', (string) $request->request->get('_token'))) {
                $error = 'Your session expired. Reload the page and try again.';
            } elseif (Category::cleanName($name) === $old) {
                return $this->done('Category renamed', 'The name didn’t change.', $this->generateUrl('app_categories', ['type' => $category->getType()->value]), 'info');
            } else {
                try {
                    $this->service->rename($category, $name);

                    return $this->done('Category renamed', '“'.$old.'” is now “'.$category->getName().'”.', $this->generateUrl('app_categories', ['type' => $category->getType()->value]));
                } catch (CategoryRuleException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->dialog($user, $request, $category->getType(), 'categories/_form_dialog.html.twig', [
            'mode' => 'rename',
            'type' => $category->getType(),
            'category' => $category,
            'tx_count' => $this->transactions->countByCategory($category),
            'name' => $name,
            'error' => $error,
        ], null !== $error);
    }

    #[Route('/{id<\d+>}/delete', name: 'app_category_delete', methods: ['GET', 'POST'])]
    public function delete(#[CurrentUser] User $user, Request $request, int $id): Response
    {
        $category = $this->owned($user, $id);
        $type = $category->getType();
        $back = $this->generateUrl('app_categories', ['type' => $type->value]);

        if ($this->service->isLastOfType($category)) {
            return $this->done('Can’t delete '.$category->getName(), 'It’s your last '.$type->value.' category.', $back, 'error');
        }

        $count = $this->transactions->countByCategory($category);
        $error = null;
        $moveTo = null;
        $moveToId = (string) $request->request->get('move_to', '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category-delete', (string) $request->request->get('_token'))) {
                $error = 'Your session expired. Reload the page and try again.';
            } else {
                if ($count > 0) {
                    $moveTo = ctype_digit($moveToId) ? $this->categories->findOwned($user, (int) $moveToId) : null;
                    if (!$moveTo || $moveTo === $category || $moveTo->getType() !== $type) {
                        $error = 'Choose a category to move the transactions to';
                    }
                }
                if (null === $error) {
                    try {
                        $moved = $this->service->delete($category, $moveTo);
                    } catch (CategoryRuleException $e) {
                        return $this->done('Can’t delete '.$category->getName(), $e->getMessage(), $back, 'error');
                    }
                    $title = $moved > 0 && $moveTo
                        ? 'Moved '.$moved.' '.self::txWord($moved).' to '.$moveTo->getName().' and deleted '.$category->getName()
                        : 'Deleted '.$category->getName();

                    return $this->done($title, null, $back);
                }
            }
        }

        $budget = TransactionType::Expense === $type && null !== $category->getId()
            ? $this->budgets->forMonth($user, $this->periods->currentMonth())->forCategoryId($category->getId())
            : null;
        $counts = $this->transactions->countsPerCategory($user);

        return $this->dialog($user, $request, $type, 'categories/_delete_dialog.html.twig', [
            'category' => $category,
            'tx_count' => $count,
            'targets' => array_values(array_filter($this->categories->findByOwnerAndType($user, $type), static fn (Category $c) => $c !== $category)),
            'counts' => $counts,
            'move_to' => $moveToId,
            'error' => $error,
            'budget_label' => null !== $budget ? DateFormat::monthLabel($this->periods->currentMonth()) : null,
        ], null !== $error);
    }

    private function renderPage(User $user, TransactionType $type, ?string $dialog = null, int $status = Response::HTTP_OK): Response
    {
        $month = $this->periods->currentMonth();
        $budgets = $this->budgets->forMonth($user, $month);
        $limits = [];
        foreach ($budgets->items as $item) {
            if ($item->category) {
                $limits[$item->category->getId()] = $item->limit;
            }
        }

        return $this->render('categories/index.html.twig', [
            'nav' => 'categories',
            'type' => $type,
            'lists' => [
                'expense' => $this->categories->findByOwnerAndType($user, TransactionType::Expense),
                'income' => $this->categories->findByOwnerAndType($user, TransactionType::Income),
            ],
            'counts' => $this->transactions->countsPerCategory($user),
            'limits' => $limits,
            'month_name' => (new \DateTimeImmutable($month.'-01'))->format('F'),
            'dialog' => $dialog,
        ], new Response(status: $status));
    }

    /**
     * HTMX: only the dialog (opened on load, removed on close; 422 keeps it open with errors).
     * Without HTMX: the page with the dialog open.
     *
     * @param array<string, mixed> $vars
     */
    private function dialog(User $user, Request $request, TransactionType $type, string $template, array $vars, bool $invalid): Response
    {
        $status = $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $vars['htmx'] = Htmx::isHtmx($request);
        $vars['back'] = $this->generateUrl('app_categories', ['type' => $type->value]);
        $html = $this->renderView($template, $vars);

        if ($vars['htmx']) {
            return new Response($html, $status);
        }

        return $this->renderPage($user, $type, $html, $status);
    }

    private function done(string $title, ?string $message, string $url, string $type = 'success', bool $forceUrl = false): Response
    {
        $response = $this->htmx->done($title, $message, $url, $type);
        if ($forceUrl && $response->headers->has('HX-Location')) {
            // Show the tab of the category that was just created.
            $response->headers->set('HX-Location', json_encode(['path' => $url, 'target' => '#main', 'select' => '#main', 'swap' => 'outerHTML'], \JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    private function owned(User $user, int $id): Category
    {
        return $this->categories->findOwned($user, $id) ?? throw new NotFoundHttpException('Category not found.');
    }

    private static function typeOf(mixed $value): TransactionType
    {
        return 'income' === $value ? TransactionType::Income : TransactionType::Expense;
    }

    private static function txWord(int $n): string
    {
        return 1 === $n ? 'transaction' : 'transactions';
    }
}
