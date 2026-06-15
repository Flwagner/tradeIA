<?php

namespace App\Momentum;

use App\Entity\Etf;
use App\Entity\MomentumSnapshot;
use App\Entity\PricePoint;

class MomentumCalculator
{
    public const STRATEGY_CODE = 'momentum_v1';

    /**
     * @param list<PricePoint> $prices
     */
    public function calculate(Etf $etf, array $prices, \DateTimeImmutable $computedAt): MomentumSnapshot
    {
        if (count($prices) < 2) {
            throw new \RuntimeException(sprintf('%s needs at least two price points.', $etf->getSymbol()));
        }

        $prices = $this->sortByPricedAt($prices);
        $latest = $prices[array_key_last($prices)];
        $latestClose = $this->metricClose($latest);

        if ($latestClose <= 0.0) {
            throw new \RuntimeException(sprintf('%s latest close price must be positive.', $etf->getSymbol()));
        }

        $latestPricedAt = $latest->getPricedAt();
        $performance1Month = $this->performanceSince($prices, $latestPricedAt->modify('-1 month'), $latestClose);
        $performance3Months = $this->performanceSince($prices, $latestPricedAt->modify('-3 months'), $latestClose);
        $performance6Months = $this->performanceSince($prices, $latestPricedAt->modify('-6 months'), $latestClose);
        $performance12Months = $this->performanceSince($prices, $latestPricedAt->modify('-12 months'), $latestClose);
        $movingAverage50 = $this->movingAverage($prices, 50);
        $movingAverage200 = $this->movingAverage($prices, 200);
        $distanceToMovingAverage200 = null !== $movingAverage200 ? ($latestClose / $movingAverage200) - 1 : null;
        $volatilityAnnualized = $this->volatilityAnnualized($prices);
        $maxDrawdown = $this->maxDrawdown(array_slice($prices, -252));
        $atr14 = $this->atr($prices, 14);
        $trendComponent = $this->trendComponent($latestClose, $movingAverage50, $movingAverage200);
        $riskComponent = $this->riskComponent($volatilityAnnualized, $maxDrawdown, $distanceToMovingAverage200, $atr14, $latestClose);
        $score = (
            0.15 * $this->momentumComponent($performance1Month)
            + 0.25 * $this->momentumComponent($performance3Months)
            + 0.25 * $this->momentumComponent($performance6Months)
            + 0.10 * $this->momentumComponent($performance12Months)
            + 0.15 * $trendComponent
            + 0.10 * $riskComponent
        );
        $enoughHistory = count($prices) >= 126 && null !== $performance3Months && null !== $performance6Months;

        $snapshot = new MomentumSnapshot();
        $snapshot
            ->setEtf($etf)
            ->setComputedAt($computedAt)
            ->setStrategyCode(self::STRATEGY_CODE)
            ->setScore($this->decimal($score, 4))
            ->setPerformance1Month($this->percentDecimal($performance1Month))
            ->setPerformance3Months($this->percentDecimal($performance3Months))
            ->setPerformance6Months($this->percentDecimal($performance6Months))
            ->setPerformance12Months($this->percentDecimal($performance12Months))
            ->setVolatilityAnnualized($this->percentDecimal($volatilityAnnualized))
            ->setMaxDrawdown($this->percentDecimal($maxDrawdown))
            ->setMovingAverage50($this->decimalOrNull($movingAverage50, 6))
            ->setMovingAverage200($this->decimalOrNull($movingAverage200, 6))
            ->setDistanceToMovingAverage200($this->percentDecimal($distanceToMovingAverage200))
            ->setAtr14($this->decimalOrNull($atr14, 4))
            ->setSignal($this->signal($score, $enoughHistory, $latestClose, $movingAverage200))
            ->setDetails([
                'latest_close' => $this->decimal((float) $latest->getClosePrice(), 6),
                'latest_metric_close' => $this->decimal($latestClose, 6),
                'latest_priced_at' => $latestPricedAt->format('Y-m-d'),
                'price_basis' => 'adjusted_close_when_available',
                'price_points' => count($prices),
                'enough_history' => $enoughHistory,
                'score_profile' => 'hybrid_momentum_3_to_6_months',
                'weights' => [
                    'momentum_1_month' => 0.15,
                    'momentum_3_months' => 0.25,
                    'momentum_6_months' => 0.25,
                    'momentum_12_months' => 0.10,
                    'trend' => 0.15,
                    'risk' => 0.10,
                ],
                'components' => [
                    'momentum_1_month' => $this->decimal($this->momentumComponent($performance1Month), 4),
                    'momentum_3_months' => $this->decimal($this->momentumComponent($performance3Months), 4),
                    'momentum_6_months' => $this->decimal($this->momentumComponent($performance6Months), 4),
                    'momentum_12_months' => $this->decimal($this->momentumComponent($performance12Months), 4),
                    'trend' => $this->decimal($trendComponent, 4),
                    'risk' => $this->decimal($riskComponent, 4),
                ],
            ])
        ;

        return $snapshot;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function performanceSince(array $prices, \DateTimeImmutable $targetDate, float $latestClose): ?float
    {
        $reference = $this->priceAtOrBefore($prices, $targetDate);

        if (!$reference instanceof PricePoint) {
            return null;
        }

        $referenceClose = $this->metricClose($reference);

        if ($referenceClose <= 0.0) {
            return null;
        }

        return ($latestClose / $referenceClose) - 1;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function movingAverage(array $prices, int $window): ?float
    {
        $count = count($prices);

        if ($count < $window) {
            return null;
        }

        $sum = 0.0;

        for ($index = $count - $window; $index < $count; ++$index) {
            $sum += $this->metricClose($prices[$index]);
        }

        return $sum / $window;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function volatilityAnnualized(array $prices): ?float
    {
        $returns = [];
        $window = array_slice($prices, -253);
        $count = count($window);

        for ($index = 1; $index < $count; ++$index) {
            $previousClose = $this->metricClose($window[$index - 1]);
            $currentClose = $this->metricClose($window[$index]);

            if ($previousClose > 0.0 && $currentClose > 0.0) {
                $returns[] = log($currentClose / $previousClose);
            }
        }

        if (count($returns) < 2) {
            return null;
        }

        $average = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(
            static fn (float $return): float => ($return - $average) ** 2,
            $returns,
        )) / (count($returns) - 1);

        return sqrt($variance) * sqrt(252);
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function maxDrawdown(array $prices): ?float
    {
        if ([] === $prices) {
            return null;
        }

        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($prices as $price) {
            $close = $this->metricClose($price);
            $peak = max($peak, $close);

            if ($peak > 0.0) {
                $maxDrawdown = min($maxDrawdown, ($close / $peak) - 1);
            }
        }

        return $maxDrawdown;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function atr(array $prices, int $window): ?float
    {
        if (count($prices) < $window + 1) {
            return null;
        }

        $ranges = [];
        $slice = array_slice($prices, -($window + 1));

        for ($index = 1; $index < count($slice); ++$index) {
            $high = null !== $slice[$index]->getHighPrice() ? (float) $slice[$index]->getHighPrice() : null;
            $low = null !== $slice[$index]->getLowPrice() ? (float) $slice[$index]->getLowPrice() : null;

            if (null === $high || null === $low) {
                continue;
            }

            $previousClose = (float) $slice[$index - 1]->getClosePrice();
            $ranges[] = max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
        }

        if ([] === $ranges) {
            return null;
        }

        return array_sum($ranges) / count($ranges);
    }

    private function momentumComponent(?float $performance): float
    {
        if (null === $performance) {
            return 50.0;
        }

        return $this->clamp(50 + ($performance * 100), 0, 100);
    }

    private function trendComponent(float $latestClose, ?float $movingAverage50, ?float $movingAverage200): float
    {
        if (null === $movingAverage50) {
            return 50.0;
        }

        if (null !== $movingAverage200 && $latestClose > $movingAverage50 && $movingAverage50 > $movingAverage200) {
            return 100.0;
        }

        if ($latestClose > $movingAverage50 && (null === $movingAverage200 || $latestClose > $movingAverage200)) {
            return 80.0;
        }

        if ($latestClose > $movingAverage50) {
            return 70.0;
        }

        return 25.0;
    }

    private function riskComponent(?float $volatilityAnnualized, ?float $maxDrawdown, ?float $distanceToMovingAverage200, ?float $atr14, float $latestClose): float
    {
        $volatilityScore = null !== $volatilityAnnualized ? $this->clamp(100 - ($volatilityAnnualized * 120), 0, 100) : 50;
        $drawdownScore = null !== $maxDrawdown ? $this->clamp(100 + ($maxDrawdown * 120), 0, 100) : 50;
        $atrScore = null !== $atr14 && $latestClose > 0.0 ? $this->clamp(100 - (($atr14 / $latestClose) * 1000), 0, 100) : 50;
        $extensionScore = null !== $distanceToMovingAverage200 ? $this->extensionScore($distanceToMovingAverage200) : 60;

        return 0.35 * $volatilityScore
            + 0.25 * $drawdownScore
            + 0.25 * $atrScore
            + 0.15 * $extensionScore;
    }

    private function extensionScore(float $distanceToMovingAverage200): float
    {
        if ($distanceToMovingAverage200 < -0.05) {
            return 40.0;
        }

        if ($distanceToMovingAverage200 <= 0.25) {
            return 100.0;
        }

        return $this->clamp(100 - (($distanceToMovingAverage200 - 0.25) * 160), 30, 100);
    }

    private function signal(float $score, bool $enoughHistory, float $latestClose, ?float $movingAverage200): string
    {
        if (!$enoughHistory || (null !== $movingAverage200 && $latestClose < $movingAverage200)) {
            return 'watch';
        }

        if ($score >= 65) {
            return 'buy';
        }

        if ($score < 45) {
            return 'avoid';
        }

        return 'watch';
    }

    /**
     * @param list<PricePoint> $prices
     *
     * @return list<PricePoint>
     */
    private function sortByPricedAt(array $prices): array
    {
        usort(
            $prices,
            static fn (PricePoint $left, PricePoint $right): int => $left->getPricedAt() <=> $right->getPricedAt(),
        );

        return $prices;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function priceAtOrBefore(array $prices, \DateTimeImmutable $targetDate): ?PricePoint
    {
        $left = 0;
        $right = count($prices) - 1;
        $match = null;

        while ($left <= $right) {
            $middle = intdiv($left + $right, 2);
            $pricedAt = $prices[$middle]->getPricedAt();

            if ($pricedAt <= $targetDate) {
                $match = $prices[$middle];
                $left = $middle + 1;

                continue;
            }

            $right = $middle - 1;
        }

        return $match;
    }

    private function metricClose(PricePoint $price): float
    {
        $adjustedClose = $price->getAdjustedClosePrice();

        if (null !== $adjustedClose && (float) $adjustedClose > 0.0) {
            return (float) $adjustedClose;
        }

        return (float) $price->getClosePrice();
    }

    private function percentDecimal(?float $value): ?string
    {
        return $this->decimalOrNull(null !== $value ? $value * 100 : null, 4);
    }

    private function decimalOrNull(?float $value, int $scale): ?string
    {
        if (null === $value || is_nan($value) || is_infinite($value)) {
            return null;
        }

        return $this->decimal($value, $scale);
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
