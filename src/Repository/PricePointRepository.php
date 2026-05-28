<?php

namespace App\Repository;

use App\Entity\Etf;
use App\Entity\PricePoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PricePoint>
 */
class PricePointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricePoint::class);
    }

    public function countForEtf(Etf $etf): int
    {
        return (int) $this->createQueryBuilder('pricePoint')
            ->select('COUNT(pricePoint.id)')
            ->andWhere('pricePoint.etf = :etf')
            ->setParameter('etf', $etf)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findLatestForEtf(Etf $etf): ?PricePoint
    {
        return $this->createQueryBuilder('pricePoint')
            ->andWhere('pricePoint.etf = :etf')
            ->setParameter('etf', $etf)
            ->orderBy('pricePoint.pricedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
