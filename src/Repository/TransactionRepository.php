<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\TransactionFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every query is scoped to the owner.
 *
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findOwned(User $owner, int $id): ?Transaction
    {
        return $this->findOneBy(['owner' => $owner, 'id' => $id]);
    }

    /** @return list<Transaction> newest first */
    public function findFiltered(User $owner, TransactionFilter $filter, int $limit, int $offset = 0): array
    {
        return $this->filtered($owner, $filter)
            ->addSelect('c')
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return array{income: int, expense: int, count: int} */
    public function totals(User $owner, TransactionFilter $filter): array
    {
        $rows = $this->filtered($owner, $filter)
            ->select('c.type AS type', 'COALESCE(SUM(t.amountMinor), 0) AS total', 'COUNT(t.id) AS n')
            ->groupBy('c.type')
            ->getQuery()
            ->getArrayResult();

        $out = ['income' => 0, 'expense' => 0, 'count' => 0];
        foreach ($rows as $row) {
            $type = $row['type'] instanceof TransactionType ? $row['type']->value : (string) $row['type'];
            $out[$type] = (int) $row['total'];
            $out['count'] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * Sum per category for a date range.
     *
     * @return array<int, int> categoryId => total minor units
     */
    public function sumByCategory(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to, TransactionType $type): array
    {
        $rows = $this->filtered($owner, new TransactionFilter($from, $to, $type))
            ->select('IDENTITY(t.category) AS category', 'SUM(t.amountMinor) AS total')
            ->groupBy('t.category')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['category']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Monthly totals between two dates (inclusive).
     *
     * @return array<string, array{income: int, expense: int}> keyed by "YYYY-MM"
     */
    public function monthlyTotals(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->filtered($owner, new TransactionFilter($from, $to))
            ->select('t.date AS date', 'c.type AS type', 't.amountMinor AS amount')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $month = $row['date']->format('Y-m');
            $type = $row['type'] instanceof TransactionType ? $row['type']->value : (string) $row['type'];
            $out[$month] ??= ['income' => 0, 'expense' => 0];
            $out[$month][$type] += (int) $row['amount'];
        }
        ksort($out);

        return $out;
    }

    public function hasAny(User $owner): bool
    {
        return null !== $this->findOneBy(['owner' => $owner]);
    }

    public function countByCategory(Category $category): int
    {
        return $this->count(['category' => $category]);
    }

    /** @return array<int, int> categoryId => transaction count */
    public function countsPerCategory(User $owner): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) AS category', 'COUNT(t.id) AS n')
            ->andWhere('t.owner = :owner')->setParameter('owner', $owner)
            ->groupBy('t.category')
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['category']] = (int) $row['n'];
        }

        return $out;
    }

    public function reassignCategory(Category $from, Category $to): int
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.category', ':to')
            ->andWhere('t.category = :from')
            ->andWhere('t.owner = :owner')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->setParameter('owner', $from->getOwner())
            ->getQuery()
            ->execute();
    }

    private function filtered(User $owner, TransactionFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.category', 'c')
            ->andWhere('t.owner = :owner')->setParameter('owner', $owner);
        if ($filter->from) {
            $qb->andWhere('t.date >= :from')->setParameter('from', $filter->from->setTime(0, 0), 'date_immutable');
        }
        if ($filter->to) {
            $qb->andWhere('t.date <= :to')->setParameter('to', $filter->to->setTime(0, 0), 'date_immutable');
        }
        if ($filter->type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $filter->type);
        }
        if ($filter->category) {
            $qb->andWhere('t.category = :category')->setParameter('category', $filter->category);
        }

        return $qb;
    }
}
