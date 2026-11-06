<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BudgetPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BudgetPlan>
 */
class BudgetPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetPlan::class);
    }

    public function findForMonth(User $owner, string $month): ?BudgetPlan
    {
        return $this->findOneBy(['owner' => $owner, 'month' => $month]);
    }

    /** Latest plan for $month or the closest earlier month (carry-over source). */
    public function findEffective(User $owner, string $month): ?BudgetPlan
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner)
            ->andWhere('p.month <= :month')->setParameter('month', $month)
            ->orderBy('p.month', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Earliest month ("YYYY-MM") that has a plan, or null. */
    public function earliestMonth(User $owner): ?string
    {
        $month = $this->createQueryBuilder('p')
            ->select('MIN(p.month)')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner)
            ->getQuery()->getSingleScalarResult();

        return \is_string($month) ? $month : null;
    }
}
