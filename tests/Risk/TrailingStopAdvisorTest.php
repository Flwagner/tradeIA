<?php

namespace App\Tests\Risk;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\Repository\PricePointRepository;
use App\Risk\TrailingStopAdvisor;
use PHPUnit\Framework\TestCase;

class TrailingStopAdvisorTest extends TestCase
{
    public function testItReturnsUnavailableWhenHistoryIsInsufficient(): void
    {
        $etf = $this->etf();
        $repository = $this->createMock(PricePointRepository::class);
        $repository
            ->expects(self::once())
            ->method('findForEtfUntil')
            ->with($etf, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn($this->priceSeries($etf, 20))
        ;

        $advice = (new TrailingStopAdvisor($repository))->advise($etf);

        self::assertFalse($advice['available']);
        self::assertSame('Historique insuffisant pour recommander un trailing stop.', $advice['message']);
        self::assertSame([], $advice['candidates']);
        self::assertNull($advice['recommended']);
    }

    public function testItBuildsTrailingStopRecommendationFromPriceHistory(): void
    {
        $etf = $this->etf();
        $prices = $this->priceSeries($etf, 80, adjustedLastClose: 150.0);
        $repository = $this->createMock(PricePointRepository::class);
        $repository
            ->expects(self::once())
            ->method('findForEtfUntil')
            ->with($etf, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn($prices)
        ;

        $advice = (new TrailingStopAdvisor($repository))->advise($etf);

        self::assertTrue($advice['available']);
        self::assertSame(150.0, $advice['currentPrice']);
        self::assertSame('hebdomadaire', $advice['updateFrequency']);
        self::assertSame(60, $advice['holdingHorizonSessions']);
        self::assertSame(45.0, $advice['maxAcceptableStopHitRate']);
        self::assertCount(5, $advice['candidates']);
        self::assertSame([5.0, 7.0, 10.0, 12.0, 15.0], array_column($advice['candidates'], 'percentage'));

        $recommended = $advice['recommended'];

        self::assertIsArray($recommended);
        self::assertContains($recommended['percentage'], [5.0, 7.0, 10.0, 12.0, 15.0]);
        self::assertLessThanOrEqual(45.0, $recommended['stopHitRate']);
        self::assertEqualsWithDelta(150.0 * (1 - ($recommended['percentage'] / 100)), $recommended['stopPrice'], 0.0001);
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

    /**
     * @return list<PricePoint>
     */
    private function priceSeries(Etf $etf, int $count, ?float $adjustedLastClose = null): array
    {
        $prices = [];
        $start = new \DateTimeImmutable('2026-01-01');

        for ($index = 0; $index < $count; ++$index) {
            $close = 100.0 + ($index * 0.5);
            $adjustedClose = $index === $count - 1 ? $adjustedLastClose : null;
            $prices[] = $this->pricePoint($etf, $start->modify(sprintf('+%d days', $index))->format('Y-m-d'), $close, $adjustedClose);
        }

        return $prices;
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
