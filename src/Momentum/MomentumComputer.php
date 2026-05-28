<?php

namespace App\Momentum;

use App\Entity\MomentumSnapshot;
use App\Repository\EtfRepository;
use App\Repository\MomentumSnapshotRepository;
use App\Repository\PricePointRepository;
use Doctrine\ORM\EntityManagerInterface;

class MomentumComputer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EtfRepository $etfRepository,
        private readonly PricePointRepository $pricePointRepository,
        private readonly MomentumSnapshotRepository $momentumSnapshotRepository,
        private readonly MomentumCalculator $momentumCalculator,
    ) {
    }

    /**
     * @return list<array{
     *     symbol: string,
     *     prices: int,
     *     score: string,
     *     signal: string,
     *     status: string
     * }>
     */
    public function computeAll(\DateTimeImmutable $computedAt): array
    {
        $rows = [];

        foreach ($this->etfRepository->findBy(['active' => true], ['symbol' => 'ASC']) as $etf) {
            $prices = $this->pricePointRepository->findForEtfUntil($etf, $computedAt);

            if (count($prices) < 2) {
                $rows[] = [
                    'symbol' => $etf->getSymbol(),
                    'prices' => count($prices),
                    'score' => '-',
                    'signal' => '-',
                    'status' => 'skipped',
                ];

                continue;
            }

            try {
                $snapshot = $this->momentumCalculator->calculate($etf, $prices, $computedAt);
                $this->replaceSnapshot($snapshot);
                $rows[] = [
                    'symbol' => $etf->getSymbol(),
                    'prices' => count($prices),
                    'score' => $snapshot->getScore(),
                    'signal' => $snapshot->getSignal(),
                    'status' => 'computed',
                ];
            } catch (\Throwable $exception) {
                $rows[] = [
                    'symbol' => $etf->getSymbol(),
                    'prices' => count($prices),
                    'score' => '-',
                    'signal' => '-',
                    'status' => $exception->getMessage(),
                ];
            }
        }

        return $rows;
    }

    private function replaceSnapshot(MomentumSnapshot $snapshot): void
    {
        $existingSnapshot = $this->momentumSnapshotRepository->findOneBy([
            'etf' => $snapshot->getEtf(),
            'computedAt' => $snapshot->getComputedAt(),
            'strategyCode' => $snapshot->getStrategyCode(),
        ]);

        if ($existingSnapshot instanceof MomentumSnapshot) {
            $this->entityManager->remove($existingSnapshot);
            $this->entityManager->flush();
        }

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();
    }
}
