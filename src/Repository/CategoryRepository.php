<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every query is scoped to the owner — there is intentionally no unscoped finder for controllers.
 *
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOwned(User $owner, int $id): ?Category
    {
        return $this->findOneBy(['owner' => $owner, 'id' => $id]);
    }

    /** @return list<Category> */
    public function findByOwnerAndType(User $owner, TransactionType $type): array
    {
        return $this->findBy(['owner' => $owner, 'type' => $type], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Category> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['type' => 'ASC', 'position' => 'ASC', 'id' => 'ASC']);
    }

    public function countByOwnerAndType(User $owner, TransactionType $type): int
    {
        return $this->count(['owner' => $owner, 'type' => $type]);
    }

    public function nameExists(User $owner, TransactionType $type, string $name, ?Category $except = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.owner = :owner')->setParameter('owner', $owner)
            ->andWhere('c.type = :type')->setParameter('type', $type)
            ->andWhere('c.nameKey = :key')->setParameter('key', Category::nameKey($name));
        if ($except && $except->getId()) {
            $qb->andWhere('c.id <> :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function nextPosition(User $owner, TransactionType $type): int
    {
        return 1 + (int) $this->createQueryBuilder('c')
            ->select('COALESCE(MAX(c.position), 0)')
            ->andWhere('c.owner = :owner')->setParameter('owner', $owner)
            ->andWhere('c.type = :type')->setParameter('type', $type)
            ->getQuery()->getSingleScalarResult();
    }
}
