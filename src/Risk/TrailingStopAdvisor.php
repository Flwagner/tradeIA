<?php

namespace App\Risk;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\Repository\PricePointRepository;

class TrailingStopAdvisor
{
    private const CANDIDATE_PERCENTAGES = [5.0, 7.0, 10.0, 12.0, 15.0];
    private const ENTRY_STEP_SESSIONS = 5;
    private const TRAILING_UPDATE_SESSIONS = 5;
    private const MAX_HOLDING_SESSIONS = 60;
    private const MAX_ACCEPTABLE_STOP_HIT_RATE = 45.0;

    public function __construct(
        private readonly PricePointRepository $pricePointRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function advise(Etf $etf): array
    {
        $prices = $this->pricePointRepository->findForEtfUntil($etf, (new \DateTimeImmutable('today'))->setTime(23, 59, 59));

        if (count($prices) < self::MAX_HOLDING_SESSIONS + 2) {
            return [
                'available' => false,
                'message' => 'Historique insuffisant pour recommander un trailing stop.',
                'candidates' => [],
                'recommended' => null,
            ];
        }

        $latestPrice = $prices[array_key_last($prices)];
        $latestClose = $this->metricClose($latestPrice);
        $lookbackStart = $latestPrice->getPricedAt()->modify('-1 year');
        $candidates = [];

        foreach (self::CANDIDATE_PERCENTAGES as $percentage) {
            $trades = $this->simulateCandidate($prices, $lookbackStart, $percentage);

            if ([] === $trades) {
                continue;
            }

            $returns = array_column($trades, 'returnPercent');
            $stopHits = count(array_filter($trades, static fn (array $trade): bool => 'stop_loss' === $trade['exitReason']));
            $averageReturn = array_sum($returns) / count($returns);
            $worstReturn = min($returns);
            $winRate = count(array_filter($returns, static fn (float $return): bool => $return > 0.0)) / count($returns) * 100;
            $stopHitRate = $stopHits / count($trades) * 100;
            $riskAdjustedScore = $this->riskAdjustedScore($averageReturn, $worstReturn, $stopHitRate);

            $candidates[] = [
                'percentage' => $percentage,
                'stopPrice' => $latestClose * (1 - ($percentage / 100)),
                'trades' => count($trades),
                'averageReturn' => $averageReturn,
                'worstReturn' => $worstReturn,
                'bestReturn' => max($returns),
                'winRate' => $winRate,
                'stopHitRate' => $stopHitRate,
                'riskAdjustedScore' => $riskAdjustedScore,
            ];
        }

        $recommended = $this->recommendedCandidate($candidates);

        if (null === $recommended) {
            return [
                'available' => false,
                'message' => 'Aucun scenario de trailing stop exploitable sur cet historique.',
                'candidates' => [],
                'recommended' => null,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['percentage'] <=> $right['percentage'],
        );

        return [
            'available' => true,
            'message' => '',
            'currentPrice' => $latestClose,
            'latestPricedAt' => $latestPrice->getPricedAt(),
            'lookbackStart' => $lookbackStart,
            'updateFrequency' => 'hebdomadaire',
            'holdingHorizonSessions' => self::MAX_HOLDING_SESSIONS,
            'maxAcceptableStopHitRate' => self::MAX_ACCEPTABLE_STOP_HIT_RATE,
            'candidates' => $candidates,
            'recommended' => $recommended,
        ];
    }

    /**
     * @param list<PricePoint> $prices
     *
     * @return list<array{returnPercent: float, exitReason: string}>
     */
    private function simulateCandidate(array $prices, \DateTimeImmutable $lookbackStart, float $percentage): array
    {
        $trades = [];
        $count = count($prices);

        for ($entryIndex = 0; $entryIndex < $count - 1; $entryIndex += self::ENTRY_STEP_SESSIONS) {
            $entry = $prices[$entryIndex];

            if ($entry->getPricedAt() < $lookbackStart) {
                continue;
            }

            $exitIndex = min($count - 1, $entryIndex + self::MAX_HOLDING_SESSIONS);

            if ($exitIndex <= $entryIndex) {
                continue;
            }

            $entryPrice = $this->metricClose($entry);

            if ($entryPrice <= 0.0) {
                continue;
            }

            $highestWeeklyClose = $entryPrice;
            $stopPrice = $entryPrice * (1 - ($percentage / 100));
            $exitPrice = $this->metricClose($prices[$exitIndex]);
            $exitReason = 'horizon';

            for ($index = $entryIndex + 1; $index <= $exitIndex; ++$index) {
                $sessionsHeld = $index - $entryIndex;

                if ($this->stopTouched($prices[$index], $stopPrice)) {
                    $exitPrice = $this->exitPrice($prices[$index], $stopPrice);
                    $exitReason = 'stop_loss';

                    break;
                }

                if (0 === $sessionsHeld % self::TRAILING_UPDATE_SESSIONS) {
                    $highestWeeklyClose = max($highestWeeklyClose, $this->metricClose($prices[$index]));
                    $stopPrice = max($stopPrice, $highestWeeklyClose * (1 - ($percentage / 100)));
                }
            }

            $trades[] = [
                'returnPercent' => (($exitPrice / $entryPrice) - 1) * 100,
                'exitReason' => $exitReason,
            ];
        }

        return $trades;
    }

    private function riskAdjustedScore(float $averageReturn, float $worstReturn, float $stopHitRate): float
    {
        $turnoverPenalty = $stopHitRate * 0.12;
        $excessTurnoverPenalty = max(0.0, $stopHitRate - self::MAX_ACCEPTABLE_STOP_HIT_RATE) * 0.30;
        $lossPenalty = abs(min(0.0, $worstReturn)) * 0.75;

        return $averageReturn - $lossPenalty - $turnoverPenalty - $excessTurnoverPenalty;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>|null
     */
    private function recommendedCandidate(array $candidates): ?array
    {
        if ([] === $candidates) {
            return null;
        }

        $acceptableCandidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['stopHitRate'] <= self::MAX_ACCEPTABLE_STOP_HIT_RATE,
        ));
        $pool = [] !== $acceptableCandidates ? $acceptableCandidates : $candidates;

        usort(
            $pool,
            static fn (array $left, array $right): int => $right['riskAdjustedScore'] <=> $left['riskAdjustedScore'],
        );

        return $pool[0];
    }

    private function stopTouched(PricePoint $price, float $stopPrice): bool
    {
        $low = $price->getLowPrice();

        if (null !== $low) {
            return (float) $low <= $stopPrice;
        }

        return $this->metricClose($price) <= $stopPrice;
    }

    private function exitPrice(PricePoint $price, float $stopPrice): float
    {
        return null !== $price->getLowPrice() ? $stopPrice : $this->metricClose($price);
    }

    private function metricClose(PricePoint $price): float
    {
        $adjustedClose = $price->getAdjustedClosePrice();

        if (null !== $adjustedClose && (float) $adjustedClose > 0.0) {
            return (float) $adjustedClose;
        }

        return (float) $price->getClosePrice();
    }
}
