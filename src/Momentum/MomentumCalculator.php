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

        $latest = $prices[array_key_last($prices)];
        $latestClose = (float) $latest->getClosePrice();
        $performance1Month = $this->performanceSince($prices, $computedAt->modify('-1 month'), $latestClose);
        $performance3Months = $this->performanceSince($prices, $computedAt->modify('-3 months'), $latestClose);
        $performance6Months = $this->performanceSince($prices, $computedAt->modify('-6 months'), $latestClose);
        $performance12Months = $this->performanceSince($prices, $computedAt->modify('-12 months'), $latestClose);
        $movingAverage50 = $this->movingAverage($prices, 50);
        $movingAverage200 = $this->movingAverage($prices, 200);
        $distanceToMovingAverage200 = $movingAverage200 !== null ? ($latestClose / $movingAverage200) - 1 : null;
        $volatilityAnnualized = $this->volatilityAnnualized($prices);
        $maxDrawdown = $this->maxDrawdown(array_slice($prices, -252));
        $atr14 = $this->atr($prices, 14);
        $trendComponent = $this->trendComponent($latestClose, $movingAverage50, $movingAverage200);
        $volatilityComponent = $volatilityAnnualized !== null ? $this->clamp(100 - ($volatilityAnnualized * 100), 0, 100) : 50;
        $score = (
            0.40 * $this->momentumComponent($performance6Months)
            + 0.30 * $this->momentumComponent($performance12Months)
            + 0.20 * $trendComponent
            + 0.10 * $volatilityComponent
        );
        $enoughHistory = count($prices) >= 200 && $performance6Months !== null;

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
                'latest_close' => $this->decimal($latestClose, 6),
                'price_points' => count($prices),
                'enough_history' => $enoughHistory,
                'components' => [
                    'momentum_6_months' => $this->decimal($this->momentumComponent($performance6Months), 4),
                    'momentum_12_months' => $this->decimal($this->momentumComponent($performance12Months), 4),
                    'trend' => $this->decimal($trendComponent, 4),
                    'low_volatility' => $this->decimal($volatilityComponent, 4),
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
        $reference = null;

        foreach ($prices as $price) {
            if ($price->getPricedAt() <= $targetDate) {
                $reference = $price;
            }
        }

        if (!$reference instanceof PricePoint) {
            return null;
        }

        $referenceClose = (float) $reference->getClosePrice();

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
        if (count($prices) < $window) {
            return null;
        }

        $sum = array_sum(array_map(
            static fn (PricePoint $price): float => (float) $price->getClosePrice(),
            array_slice($prices, -$window),
        ));

        return $sum / $window;
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function volatilityAnnualized(array $prices): ?float
    {
        $returns = [];
        $window = array_slice($prices, -253);

        for ($index = 1; $index < count($window); ++$index) {
            $previousClose = (float) $window[$index - 1]->getClosePrice();
            $currentClose = (float) $window[$index]->getClosePrice();

            if ($previousClose > 0.0) {
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
        if ($prices === []) {
            return null;
        }

        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($prices as $price) {
            $close = (float) $price->getClosePrice();
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
            $high = $slice[$index]->getHighPrice() !== null ? (float) $slice[$index]->getHighPrice() : null;
            $low = $slice[$index]->getLowPrice() !== null ? (float) $slice[$index]->getLowPrice() : null;

            if ($high === null || $low === null) {
                continue;
            }

            $previousClose = (float) $slice[$index - 1]->getClosePrice();
            $ranges[] = max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
        }

        if ($ranges === []) {
            return null;
        }

        return array_sum($ranges) / count($ranges);
    }

    private function momentumComponent(?float $performance): float
    {
        if ($performance === null) {
            return 50.0;
        }

        return $this->clamp(50 + ($performance * 100), 0, 100);
    }

    private function trendComponent(float $latestClose, ?float $movingAverage50, ?float $movingAverage200): float
    {
        if ($movingAverage200 === null) {
            return 50.0;
        }

        if ($latestClose > $movingAverage200 && $movingAverage50 !== null && $movingAverage50 > $movingAverage200) {
            return 100.0;
        }

        if ($latestClose > $movingAverage200) {
            return 70.0;
        }

        return 25.0;
    }

    private function signal(float $score, bool $enoughHistory, float $latestClose, ?float $movingAverage200): string
    {
        if (!$enoughHistory || $movingAverage200 === null || $latestClose < $movingAverage200) {
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

    private function percentDecimal(?float $value): ?string
    {
        return $this->decimalOrNull($value !== null ? $value * 100 : null, 4);
    }

    private function decimalOrNull(?float $value, int $scale): ?string
    {
        if ($value === null || is_nan($value) || is_infinite($value)) {
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
