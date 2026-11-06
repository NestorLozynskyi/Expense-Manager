<?php

declare(strict_types=1);

namespace App\Category;

use App\Entity\Category;
use App\Entity\CategoryBudget;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create / rename / delete categories with the approved rules:
 *  - names unique per account and type (case-insensitive), 1–30 characters;
 *  - the last category of a type can't be deleted;
 *  - a category with transactions is deleted only after moving them to another category of
 *    the same type; its budgets are removed with it. Move + delete run in one DB transaction.
 */
final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $categories,
        private TransactionRepository $transactions,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return string|null user-facing validation error */
    public function validateName(User $owner, TransactionType $type, string $name, ?Category $except = null): ?string
    {
        $clean = Category::cleanName($name);
        if ('' === $clean) {
            return 'Enter a category name';
        }
        if (mb_strlen($clean) > Category::NAME_MAX) {
            return 'Use '.Category::NAME_MAX.' characters or fewer';
        }
        if ($this->categories->nameExists($owner, $type, $clean, $except)) {
            return 'A category with this name already exists';
        }

        return null;
    }

    public function create(User $owner, TransactionType $type, string $name): Category
    {
        if ($error = $this->validateName($owner, $type, $name)) {
            throw new CategoryRuleException($error);
        }
        $position = $this->categories->nextPosition($owner, $type);
        [$color, $icon] = CustomCategoryStyle::pick($type, $position - 1);
        $category = new Category($owner, $type, $name, $color, $icon, $position);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    public function rename(Category $category, string $name): void
    {
        if ($error = $this->validateName($category->getOwner(), $category->getType(), $name, $category)) {
            throw new CategoryRuleException($error);
        }
        $category->rename($name);
        $this->em->flush();
    }

    public function isLastOfType(Category $category): bool
    {
        return $this->categories->countByOwnerAndType($category->getOwner(), $category->getType()) <= 1;
    }

    /**
     * @return int number of moved transactions
     */
    public function delete(Category $category, ?Category $moveTo = null): int
    {
        if ($this->isLastOfType($category)) {
            throw new CategoryRuleException('This is your last '.$category->getType()->value.' category, so it can’t be deleted.');
        }
        $count = $this->transactions->countByCategory($category);
        if ($count > 0) {
            if (null === $moveTo) {
                throw new CategoryRuleException('Choose a category to move the transactions to');
            }
            if ($moveTo === $category || $moveTo->getOwner() !== $category->getOwner() || $moveTo->getType() !== $category->getType()) {
                throw new CategoryRuleException('Choose another '.$category->getType()->value.' category');
            }
        }

        return $this->em->wrapInTransaction(function () use ($category, $moveTo, $count): int {
            $moved = $count > 0 && $moveTo ? $this->transactions->reassignCategory($category, $moveTo) : 0;
            $this->em->createQueryBuilder()
                ->delete(CategoryBudget::class, 'b')
                ->where('b.category = :category')
                ->setParameter('category', $category)
                ->getQuery()->execute();
            $this->em->remove($category);
            $this->em->flush();

            return $moved;
        });
    }
}
