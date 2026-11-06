<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BudgetPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The set of budgets an account defined for one month ("YYYY-MM"): an optional overall
 * limit plus per-expense-category limits. A month without its own plan uses the latest
 * earlier plan (carry-over); editing such a month first copies that plan into the month,
 * so earlier months never change.
 */
#[ORM\Entity(repositoryClass: BudgetPlanRepository::class)]
#[ORM\Table(name: 'budget_plan')]
#[ORM\UniqueConstraint(name: 'uniq_budget_plan_owner_month', fields: ['owner', 'month'])]
class BudgetPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 7)]
    private string $month;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $overallLimitMinor = null;

    /** @var Collection<int, CategoryBudget> */
    #[ORM\OneToMany(targetEntity: CategoryBudget::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $categoryBudgets;

    public function __construct(User $owner, string $month)
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException('Month must be YYYY-MM.');
        }
        $this->owner = $owner;
        $this->month = $month;
        $this->categoryBudgets = new ArrayCollection();
    }

    public function copyFor(string $month): self
    {
        $copy = new self($this->owner, $month);
        $copy->overallLimitMinor = $this->overallLimitMinor;
        foreach ($this->categoryBudgets as $budget) {
            $copy->setCategoryLimit($budget->getCategory(), $budget->getLimitMinor());
        }

        return $copy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getMonth(): string
    {
        return $this->month;
    }

    public function getOverallLimitMinor(): ?int
    {
        return null === $this->overallLimitMinor ? null : (int) $this->overallLimitMinor;
    }

    public function setOverallLimit(?int $limitMinor): void
    {
        if (null !== $limitMinor && $limitMinor <= 0) {
            throw new \InvalidArgumentException('Limit must be positive.');
        }
        $this->overallLimitMinor = $limitMinor;
    }

    /** @return list<CategoryBudget> */
    public function getCategoryBudgets(): array
    {
        return array_values($this->categoryBudgets->toArray());
    }

    public function findCategoryBudget(Category $category): ?CategoryBudget
    {
        foreach ($this->categoryBudgets as $budget) {
            if ($budget->getCategory() === $category) {
                return $budget;
            }
        }

        return null;
    }

    public function setCategoryLimit(Category $category, int $limitMinor): void
    {
        if (TransactionType::Expense !== $category->getType() || $category->getOwner() !== $this->owner) {
            throw new \InvalidArgumentException('Budgets are only for expense categories of the same account.');
        }
        $existing = $this->findCategoryBudget($category);
        if ($existing) {
            $existing->setLimitMinor($limitMinor);

            return;
        }
        $this->categoryBudgets->add(new CategoryBudget($this, $category, $limitMinor));
    }

    public function removeCategoryLimit(Category $category): void
    {
        $existing = $this->findCategoryBudget($category);
        if ($existing) {
            $this->categoryBudgets->removeElement($existing);
        }
    }

    public function isEmpty(): bool
    {
        return null === $this->overallLimitMinor && $this->categoryBudgets->isEmpty();
    }
}
