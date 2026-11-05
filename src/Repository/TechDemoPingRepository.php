<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TechDemoPing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * TEMPORARY: backs the stack demo page only.
 *
 * @extends ServiceEntityRepository<TechDemoPing>
 */
class TechDemoPingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TechDemoPing::class);
    }

    /** @return list<TechDemoPing> */
    public function findLatest(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<TechDemoPing> */
    public function findCreatedSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function add(TechDemoPing $ping): void
    {
        $this->getEntityManager()->persist($ping);
        $this->getEntityManager()->flush();
    }
}
