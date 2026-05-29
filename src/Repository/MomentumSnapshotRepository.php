<?php

namespace App\Repository;

use App\Entity\Etf;
use App\Entity\MomentumSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MomentumSnapshot>
 */
class MomentumSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MomentumSnapshot::class);
    }

    /**
     * @return list<MomentumSnapshot>
     */
    public function findLatestByStrategy(string $strategyCode): array
    {
        $latestDate = $this->createQueryBuilder('snapshotDate')
            ->select('MAX(snapshotDate.computedAt)')
            ->andWhere('snapshotDate.strategyCode = :strategyCode')
            ->setParameter('strategyCode', $strategyCode)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        if ($latestDate === null) {
            return [];
        }

        return $this->createQueryBuilder('snapshot')
            ->addSelect('etf')
            ->innerJoin('snapshot.etf', 'etf')
            ->andWhere('snapshot.strategyCode = :strategyCode')
            ->andWhere('snapshot.computedAt = :computedAt')
            ->setParameter('strategyCode', $strategyCode)
            ->setParameter('computedAt', new \DateTimeImmutable((string) $latestDate))
            ->orderBy('snapshot.score', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }

    public function findLatestForEtfByStrategy(Etf $etf, string $strategyCode): ?MomentumSnapshot
    {
        return $this->createQueryBuilder('snapshot')
            ->andWhere('snapshot.etf = :etf')
            ->andWhere('snapshot.strategyCode = :strategyCode')
            ->setParameter('etf', $etf)
            ->setParameter('strategyCode', $strategyCode)
            ->orderBy('snapshot.computedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
