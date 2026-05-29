<?php

namespace App\Controller;

use App\Entity\PricePoint;
use App\MarketData\MarketDataImporter;
use App\Momentum\MomentumCalculator;
use App\Repository\EtfRepository;
use App\Repository\MomentumSnapshotRepository;
use App\Repository\PricePointRepository;
use App\Risk\TrailingStopAdvisor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EtfController extends AbstractController
{
    private const CHART_WIDTH = 760;
    private const CHART_HEIGHT = 280;
    private const CHART_PADDING = 28;

    #[Route('/etfs/{id}', name: 'app_etf_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        EtfRepository $etfRepository,
        PricePointRepository $pricePointRepository,
        MomentumSnapshotRepository $momentumSnapshotRepository,
        TrailingStopAdvisor $trailingStopAdvisor,
    ): Response {
        $etf = $etfRepository->find($id);

        if ($etf === null) {
            throw $this->createNotFoundException('ETF introuvable.');
        }

        $latestPrice = $pricePointRepository->findLatestForEtf($etf);
        $periods = $this->periods();
        $selectedPeriod = (string) $request->query->get('period', '1y');

        if (!isset($periods[$selectedPeriod])) {
            $selectedPeriod = '1y';
        }

        $anchorDate = $latestPrice?->getPricedAt() ?? new \DateTimeImmutable('today');
        $prices = $pricePointRepository->findForEtfSince($etf, $anchorDate->modify($periods[$selectedPeriod]['modifier']));
        $snapshot = $momentumSnapshotRepository->findLatestForEtfByStrategy($etf, MomentumCalculator::STRATEGY_CODE);
        $snapshotDetails = $snapshot?->getDetails() ?? [];

        return $this->render('etf/show.html.twig', [
            'etf' => $etf,
            'latestPrice' => $latestPrice,
            'snapshot' => $snapshot,
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'chart' => $this->chart($prices),
            'pricePointCount' => $pricePointRepository->countForEtf($etf),
            'momentumComponents' => $snapshotDetails['components'] ?? [],
            'trailingStopAdvice' => $trailingStopAdvisor->advise($etf),
        ]);
    }

    #[Route('/etfs/{id}/refresh-prices', name: 'app_etf_refresh_prices', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refreshPrices(
        int $id,
        Request $request,
        EtfRepository $etfRepository,
        MarketDataImporter $marketDataImporter,
    ): RedirectResponse {
        $etf = $etfRepository->find($id);

        if ($etf === null) {
            throw $this->createNotFoundException('ETF introuvable.');
        }

        if (!$this->isCsrfTokenValid('refresh_prices_etf_' . $etf->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $result = $marketDataImporter->refreshEtf(
            $etf,
            (new \DateTimeImmutable('-1 year'))->setTime(0, 0),
            (new \DateTimeImmutable('today'))->setTime(23, 59, 59),
        );

        $this->addFlash('success', sprintf(
            '%s : %d prix recuperes, %d crees, %d mis a jour.',
            $etf->getSymbol(),
            $result['fetched'],
            $result['inserted'],
            $result['updated'],
        ));

        return $this->redirectToRoute('app_home');
    }

    #[Route('/etfs/{id}/delete', name: 'app_etf_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        EtfRepository $etfRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $etf = $etfRepository->find($id);

        if ($etf === null) {
            throw $this->createNotFoundException('ETF introuvable.');
        }

        if (!$this->isCsrfTokenValid('delete_etf_' . $etf->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $entityManager->remove($etf);
        $entityManager->flush();

        $this->addFlash('success', sprintf('%s et ses donnees ont ete supprimes.', $etf->getSymbol()));

        return $this->redirectToRoute('app_home');
    }

    /**
     * @return array<string, array{label: string, modifier: string}>
     */
    private function periods(): array
    {
        return [
            '1y' => ['label' => '1 an', 'modifier' => '-1 year'],
            '6m' => ['label' => '6 mois', 'modifier' => '-6 months'],
            '3m' => ['label' => '3 mois', 'modifier' => '-3 months'],
        ];
    }

    /**
     * @param list<PricePoint> $prices
     *
     * @return array<string, mixed>
     */
    private function chart(array $prices): array
    {
        $points = array_map(fn (PricePoint $price): array => [
            'date' => $price->getPricedAt()->format('Y-m-d'),
            'price' => $this->metricClose($price),
            'closePrice' => (float) $price->getClosePrice(),
            'adjustedClosePrice' => $price->getAdjustedClosePrice() !== null ? (float) $price->getAdjustedClosePrice() : null,
        ], $prices);

        if (count($points) < 2) {
            return [
                'points' => $points,
                'path' => '',
                'min' => $points[0]['price'] ?? null,
                'max' => $points[0]['price'] ?? null,
                'first' => $points[0] ?? null,
                'last' => $points[0] ?? null,
                'performance' => null,
                'viewBox' => sprintf('0 0 %d %d', self::CHART_WIDTH, self::CHART_HEIGHT),
            ];
        }

        $min = min(array_column($points, 'price'));
        $max = max(array_column($points, 'price'));
        $range = $max - $min;
        $usableWidth = self::CHART_WIDTH - (self::CHART_PADDING * 2);
        $usableHeight = self::CHART_HEIGHT - (self::CHART_PADDING * 2);
        $lastIndex = count($points) - 1;
        $pathParts = [];

        foreach ($points as $index => $point) {
            $x = self::CHART_PADDING + (($index / $lastIndex) * $usableWidth);
            $yRatio = $range > 0.0 ? (($point['price'] - $min) / $range) : 0.5;
            $y = self::CHART_HEIGHT - self::CHART_PADDING - ($yRatio * $usableHeight);
            $pathParts[] = sprintf('%s %.2F %.2F', $index === 0 ? 'M' : 'L', $x, $y);
            $points[$index]['x'] = $x;
            $points[$index]['y'] = $y;
        }

        $first = $points[0];
        $last = $points[$lastIndex];
        $performance = $first['price'] > 0.0 ? (($last['price'] / $first['price']) - 1) * 100 : null;

        return [
            'points' => $points,
            'path' => implode(' ', $pathParts),
            'min' => $min,
            'max' => $max,
            'first' => $first,
            'last' => $last,
            'performance' => $performance,
            'viewBox' => sprintf('0 0 %d %d', self::CHART_WIDTH, self::CHART_HEIGHT),
        ];
    }

    private function metricClose(PricePoint $price): float
    {
        $adjustedClose = $price->getAdjustedClosePrice();

        if ($adjustedClose !== null && (float) $adjustedClose > 0.0) {
            return (float) $adjustedClose;
        }

        return (float) $price->getClosePrice();
    }
}
