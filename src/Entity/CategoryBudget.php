<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Monthly limit for one expense category inside a BudgetPlan. */
#[ORM\Entity]
#[ORM\Table(name: 'category_budget')]
#[ORM\UniqueConstraint(name: 'uniq_category_budget_plan_category', fields: ['plan', 'category'])]
class CategoryBudget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'categoryBudgets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BudgetPlan $plan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(type: 'bigint')]
    private int|string $limitMinor;

    public function __construct(BudgetPlan $plan, Category $category, int $limitMinor)
    {
        $this->plan = $plan;
        $this->category = $category;
        $this->setLimitMinor($limitMinor);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): BudgetPlan
    {
        return $this->plan;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getLimitMinor(): int
    {
        return (int) $this->limitMinor;
    }

    public function setLimitMinor(int $limitMinor): void
    {
        if ($limitMinor <= 0) {
            throw new \InvalidArgumentException('Limit must be positive.');
        }
        $this->limitMinor = $limitMinor;
    }
}
