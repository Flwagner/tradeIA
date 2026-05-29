<?php

namespace App\Backtest;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\Momentum\MomentumCalculator;
use App\Repository\EtfRepository;
use App\Repository\PricePointRepository;

class TacticalBacktester
{
    private const INITIAL_CAPITAL = 1000.0;
    private const CHART_WIDTH = 760;
    private const CHART_HEIGHT = 280;
    private const CHART_PADDING = 28;

    public function __construct(
        private readonly EtfRepository $etfRepository,
        private readonly PricePointRepository $pricePointRepository,
        private readonly MomentumCalculator $momentumCalculator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $periodKey, float $stopLossPercent): array
    {
        $periods = $this->periods();
        $periodKey = isset($periods[$periodKey]) ? $periodKey : '6m';
        $stopLossPercent = min(25.0, max(1.0, $stopLossPercent));
        $histories = $this->histories();
        $latestDate = $this->latestDate($histories);

        if ($latestDate === null) {
            return $this->emptyResult($periodKey, $stopLossPercent, $periods);
        }

        $startDate = $latestDate->modify($periods[$periodKey]['modifier']);
        $dates = $this->tradingDates($histories, $startDate, $latestDate);
        $cash = self::INITIAL_CAPITAL;
        $position = null;
        $openTrade = null;
        $trades = [];
        $equityCurve = [];

        foreach ($dates as $dateString) {
            $date = new \DateTimeImmutable($dateString);
            $exitedToday = false;

            if ($position !== null && $openTrade !== null) {
                $todayPoint = $histories[$position['etfId']]['byDate'][$dateString] ?? null;

                if ($todayPoint instanceof PricePoint && $this->stopTouched($todayPoint, $position['stopPrice'])) {
                    $exitPrice = $this->exitPrice($todayPoint, $position['stopPrice']);
                    $cash = $position['shares'] * $exitPrice;
                    $trades[] = [
                        ...$openTrade,
                        'exitDate' => $dateString,
                        'exitPrice' => $exitPrice,
                        'exitReason' => 'stop_loss',
                        'returnPercent' => (($exitPrice / $openTrade['entryPrice']) - 1) * 100,
                        'holdingDays' => $openTrade['entryDate'] !== $dateString ? $date->diff(new \DateTimeImmutable($openTrade['entryDate']))->days : 0,
                        'capitalAfterExit' => $cash,
                    ];
                    $position = null;
                    $openTrade = null;
                    $exitedToday = true;
                }
            }

            if ($position === null && !$exitedToday) {
                $candidate = $this->bestCandidate($histories, $date, $dateString);

                if ($candidate !== null && $cash > 0.0) {
                    $entryPrice = $this->metricClose($candidate['point']);
                    $shares = $cash / $entryPrice;
                    $stopPrice = $entryPrice * (1 - ($stopLossPercent / 100));
                    $position = [
                        'etfId' => $candidate['etf']->getId(),
                        'shares' => $shares,
                        'stopPrice' => $stopPrice,
                    ];
                    $openTrade = [
                        'symbol' => $candidate['etf']->getSymbol(),
                        'name' => $candidate['etf']->getName(),
                        'entryDate' => $dateString,
                        'entryPrice' => $entryPrice,
                        'stopPrice' => $stopPrice,
                        'score' => $candidate['score'],
                        'signal' => $candidate['signal'],
                        'capitalAtEntry' => $cash,
                    ];
                    $cash = 0.0;
                }
            }

            $equityCurve[] = [
                'date' => $dateString,
                'value' => $this->equityValue($histories, $position, $cash, $dateString),
                'symbol' => $openTrade['symbol'] ?? null,
            ];
        }

        $finalValue = $equityCurve !== [] ? $equityCurve[array_key_last($equityCurve)]['value'] : self::INITIAL_CAPITAL;
        $closedReturns = array_column($trades, 'returnPercent');

        return [
            'settings' => [
                'initialCapital' => self::INITIAL_CAPITAL,
                'periodKey' => $periodKey,
                'periods' => $periods,
                'stopLossPercent' => $stopLossPercent,
                'startDate' => $dates[0] ?? null,
                'endDate' => $dates !== [] ? $dates[array_key_last($dates)] : null,
            ],
            'summary' => [
                'finalValue' => $finalValue,
                'returnPercent' => ((float) $finalValue / self::INITIAL_CAPITAL - 1) * 100,
                'tradeCount' => count($trades),
                'winRate' => $closedReturns !== [] ? (count(array_filter($closedReturns, static fn (float $return): bool => $return > 0.0)) / count($closedReturns)) * 100 : null,
                'averageTradeReturn' => $closedReturns !== [] ? array_sum($closedReturns) / count($closedReturns) : null,
                'maxDrawdown' => $this->maxDrawdown($equityCurve),
            ],
            'trades' => $trades,
            'openPosition' => $openTrade,
            'equityCurve' => $equityCurve,
            'chart' => $this->chart($equityCurve),
        ];
    }

    /**
     * @return array<string, array{label: string, modifier: string}>
     */
    public function periods(): array
    {
        return [
            '1y' => ['label' => '1 an', 'modifier' => '-1 year'],
            '6m' => ['label' => '6 mois', 'modifier' => '-6 months'],
            '3m' => ['label' => '3 mois', 'modifier' => '-3 months'],
        ];
    }

    /**
     * @return array<int, array{etf: Etf, prices: list<PricePoint>, byDate: array<string, PricePoint>}>
     */
    private function histories(): array
    {
        $histories = [];

        foreach ($this->etfRepository->findBy(['active' => true], ['symbol' => 'ASC']) as $etf) {
            $prices = $this->pricePointRepository->findForEtfUntil($etf, (new \DateTimeImmutable('today'))->setTime(23, 59, 59));

            if (count($prices) < 2 || $etf->getId() === null) {
                continue;
            }

            $byDate = [];

            foreach ($prices as $price) {
                $byDate[$price->getPricedAt()->format('Y-m-d')] = $price;
            }

            $histories[$etf->getId()] = [
                'etf' => $etf,
                'prices' => $prices,
                'byDate' => $byDate,
            ];
        }

        return $histories;
    }

    /**
     * @param array<int, array{prices: list<PricePoint>}> $histories
     */
    private function latestDate(array $histories): ?\DateTimeImmutable
    {
        $latest = null;

        foreach ($histories as $history) {
            $price = $history['prices'][array_key_last($history['prices'])];
            $pricedAt = $price->getPricedAt();

            if ($latest === null || $pricedAt > $latest) {
                $latest = $pricedAt;
            }
        }

        return $latest;
    }

    /**
     * @param array<int, array{byDate: array<string, PricePoint>}> $histories
     *
     * @return list<string>
     */
    private function tradingDates(array $histories, \DateTimeImmutable $startDate, \DateTimeImmutable $latestDate): array
    {
        $dates = [];

        foreach ($histories as $history) {
            foreach ($history['byDate'] as $dateString => $_price) {
                $date = new \DateTimeImmutable($dateString);

                if ($date >= $startDate && $date <= $latestDate) {
                    $dates[$dateString] = true;
                }
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /**
     * @param array<int, array{etf: Etf, prices: list<PricePoint>, byDate: array<string, PricePoint>}> $histories
     *
     * @return array{etf: Etf, point: PricePoint, score: float, signal: string}|null
     */
    private function bestCandidate(array $histories, \DateTimeImmutable $date, string $dateString): ?array
    {
        $best = null;

        foreach ($histories as $history) {
            $point = $history['byDate'][$dateString] ?? null;

            if (!$point instanceof PricePoint) {
                continue;
            }

            $prices = $this->pricesUntil($history['prices'], $date);

            if (count($prices) < 2) {
                continue;
            }

            try {
                $snapshot = $this->momentumCalculator->calculate($history['etf'], $prices, $date);
            } catch (\Throwable) {
                continue;
            }

            $score = (float) $snapshot->getScore();

            if ($best === null || $score > $best['score']) {
                $best = [
                    'etf' => $history['etf'],
                    'point' => $point,
                    'score' => $score,
                    'signal' => $snapshot->getSignal(),
                ];
            }
        }

        return $best;
    }

    /**
     * @param list<PricePoint> $prices
     *
     * @return list<PricePoint>
     */
    private function pricesUntil(array $prices, \DateTimeImmutable $date): array
    {
        $matches = [];

        foreach ($prices as $price) {
            if ($price->getPricedAt() > $date) {
                break;
            }

            $matches[] = $price;
        }

        return $matches;
    }

    private function stopTouched(PricePoint $price, float $stopPrice): bool
    {
        $low = $price->getLowPrice();

        if ($low !== null) {
            return (float) $low <= $stopPrice;
        }

        return $this->metricClose($price) <= $stopPrice;
    }

    private function exitPrice(PricePoint $price, float $stopPrice): float
    {
        return $price->getLowPrice() !== null ? $stopPrice : $this->metricClose($price);
    }

    /**
     * @param array<int, array{prices: list<PricePoint>}> $histories
     * @param array{etfId: int|null, shares: float, stopPrice: float}|null $position
     */
    private function equityValue(array $histories, ?array $position, float $cash, string $dateString): float
    {
        if ($position === null || !isset($histories[$position['etfId']])) {
            return $cash;
        }

        $price = $histories[$position['etfId']]['byDate'][$dateString] ?? $this->latestPointBefore($histories[$position['etfId']]['prices'], new \DateTimeImmutable($dateString));

        if (!$price instanceof PricePoint) {
            return $cash;
        }

        return $position['shares'] * $this->metricClose($price);
    }

    /**
     * @param list<PricePoint> $prices
     */
    private function latestPointBefore(array $prices, \DateTimeImmutable $date): ?PricePoint
    {
        $match = null;

        foreach ($prices as $price) {
            if ($price->getPricedAt() > $date) {
                break;
            }

            $match = $price;
        }

        return $match;
    }

    private function metricClose(PricePoint $price): float
    {
        $adjustedClose = $price->getAdjustedClosePrice();

        if ($adjustedClose !== null && (float) $adjustedClose > 0.0) {
            return (float) $adjustedClose;
        }

        return (float) $price->getClosePrice();
    }

    /**
     * @param list<array{value: float}> $equityCurve
     */
    private function maxDrawdown(array $equityCurve): ?float
    {
        if ($equityCurve === []) {
            return null;
        }

        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($equityCurve as $point) {
            $peak = max($peak, $point['value']);

            if ($peak > 0.0) {
                $maxDrawdown = min($maxDrawdown, ($point['value'] / $peak) - 1);
            }
        }

        return $maxDrawdown * 100;
    }

    /**
     * @param list<array{date: string, value: float}> $equityCurve
     *
     * @return array<string, mixed>
     */
    private function chart(array $equityCurve): array
    {
        if (count($equityCurve) < 2) {
            return [
                'path' => '',
                'first' => $equityCurve[0] ?? null,
                'last' => $equityCurve[0] ?? null,
                'min' => $equityCurve[0]['value'] ?? null,
                'max' => $equityCurve[0]['value'] ?? null,
                'viewBox' => sprintf('0 0 %d %d', self::CHART_WIDTH, self::CHART_HEIGHT),
            ];
        }

        $values = array_column($equityCurve, 'value');
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $usableWidth = self::CHART_WIDTH - (self::CHART_PADDING * 2);
        $usableHeight = self::CHART_HEIGHT - (self::CHART_PADDING * 2);
        $lastIndex = count($equityCurve) - 1;
        $pathParts = [];

        foreach ($equityCurve as $index => $point) {
            $x = self::CHART_PADDING + (($index / $lastIndex) * $usableWidth);
            $yRatio = $range > 0.0 ? (($point['value'] - $min) / $range) : 0.5;
            $y = self::CHART_HEIGHT - self::CHART_PADDING - ($yRatio * $usableHeight);
            $pathParts[] = sprintf('%s %.2F %.2F', $index === 0 ? 'M' : 'L', $x, $y);
            $equityCurve[$index]['x'] = $x;
            $equityCurve[$index]['y'] = $y;
        }

        return [
            'path' => implode(' ', $pathParts),
            'first' => $equityCurve[0],
            'last' => $equityCurve[$lastIndex],
            'min' => $min,
            'max' => $max,
            'viewBox' => sprintf('0 0 %d %d', self::CHART_WIDTH, self::CHART_HEIGHT),
        ];
    }

    /**
     * @param array<string, array{label: string, modifier: string}> $periods
     *
     * @return array<string, mixed>
     */
    private function emptyResult(string $periodKey, float $stopLossPercent, array $periods): array
    {
        return [
            'settings' => [
                'initialCapital' => self::INITIAL_CAPITAL,
                'periodKey' => $periodKey,
                'periods' => $periods,
                'stopLossPercent' => $stopLossPercent,
                'startDate' => null,
                'endDate' => null,
            ],
            'summary' => [
                'finalValue' => self::INITIAL_CAPITAL,
                'returnPercent' => 0.0,
                'tradeCount' => 0,
                'winRate' => null,
                'averageTradeReturn' => null,
                'maxDrawdown' => null,
            ],
            'trades' => [],
            'openPosition' => null,
            'equityCurve' => [],
            'chart' => [
                'path' => '',
                'first' => null,
                'last' => null,
                'min' => null,
                'max' => null,
                'viewBox' => sprintf('0 0 %d %d', self::CHART_WIDTH, self::CHART_HEIGHT),
            ],
        ];
    }
}
