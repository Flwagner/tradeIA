<?php

namespace App\Tests\Momentum;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\Momentum\MomentumCalculator;
use PHPUnit\Framework\TestCase;

class MomentumCalculatorTest extends TestCase
{
    public function testItRequiresAtLeastTwoPricePoints(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TEST needs at least two price points.');

        $etf = $this->etf();

        (new MomentumCalculator())->calculate($etf, [
            $this->pricePoint($etf, '2026-01-01', 100.0, 100.0),
        ], new \DateTimeImmutable('2026-01-02'));
    }

    public function testItUsesAdjustedCloseAsMetricCloseWhenAvailable(): void
    {
        $etf = $this->etf();
        $calculator = new MomentumCalculator();

        $snapshot = $calculator->calculate($etf, [
            $this->pricePoint($etf, '2026-01-01', 100.0, 100.0),
            $this->pricePoint($etf, '2026-01-02', 500.0, 125.0),
        ], new \DateTimeImmutable('2026-01-03'));

        self::assertSame('500.000000', $snapshot->getDetails()['latest_close']);
        self::assertSame('125.000000', $snapshot->getDetails()['latest_metric_close']);
        self::assertSame('adjusted_close_when_available', $snapshot->getDetails()['price_basis']);
    }

    public function testItKeepsWatchSignalWhenHistoryIsTooShort(): void
    {
        $etf = $this->etf();
        $calculator = new MomentumCalculator();

        $snapshot = $calculator->calculate($etf, [
            $this->pricePoint($etf, '2026-01-01', 100.0, 100.0),
            $this->pricePoint($etf, '2026-01-02', 120.0, 120.0),
        ], new \DateTimeImmutable('2026-01-03'));

        self::assertSame('watch', $snapshot->getSignal());
        self::assertFalse($snapshot->getDetails()['enough_history']);
        self::assertNull($snapshot->getMovingAverage200());
    }

    public function testItProducesBuySignalForEnoughRisingHistory(): void
    {
        $etf = $this->etf();
        $calculator = new MomentumCalculator();
        $prices = [];
        $start = new \DateTimeImmutable('2025-01-01');

        for ($index = 0; $index < 252; ++$index) {
            $close = 100.0 + ($index * 0.5);
            $prices[] = $this->pricePoint($etf, $start->modify(sprintf('+%d days', $index))->format('Y-m-d'), $close, $close);
        }

        $snapshot = $calculator->calculate($etf, $prices, new \DateTimeImmutable('2025-09-10'));

        self::assertSame(MomentumCalculator::STRATEGY_CODE, $snapshot->getStrategyCode());
        self::assertSame('buy', $snapshot->getSignal());
        self::assertTrue($snapshot->getDetails()['enough_history']);
        self::assertNotNull($snapshot->getMovingAverage200());
        self::assertGreaterThan(75.0, (float) $snapshot->getScore());
    }

    private function etf(): Etf
    {
        return (new Etf())
            ->setIsin('FR0010000001')
            ->setSymbol('TEST')
            ->setName('Test ETF')
            ->setExchange('XPAR')
            ->setCurrency('EUR')
        ;
    }

    private function pricePoint(Etf $etf, string $pricedAt, float $close, ?float $adjustedClose): PricePoint
    {
        return (new PricePoint())
            ->setEtf($etf)
            ->setPricedAt(new \DateTimeImmutable($pricedAt))
            ->setOpenPrice($this->decimal($close))
            ->setHighPrice($this->decimal($close + 1.0))
            ->setLowPrice($this->decimal($close - 1.0))
            ->setClosePrice($this->decimal($close))
            ->setAdjustedClosePrice(null !== $adjustedClose ? $this->decimal($adjustedClose) : null)
            ->setSource('test')
        ;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
