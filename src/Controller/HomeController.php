<?php

namespace App\Controller;

use App\Boursobank\BoursobankTopEtfClient;
use App\MarketData\MarketDataImporter;
use App\Momentum\MomentumCalculator;
use App\Repository\EtfRepository;
use App\Repository\MomentumSnapshotRepository;
use App\Repository\PricePointRepository;
use App\Risk\TrailingStopAdvisor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        EtfRepository $etfRepository,
        PricePointRepository $pricePointRepository,
        MomentumSnapshotRepository $momentumSnapshotRepository,
        MarketDataImporter $marketDataImporter,
        BoursobankTopEtfClient $boursobankTopEtfClient,
        TrailingStopAdvisor $trailingStopAdvisor,
    ): Response
    {
        $defaultFrom = (new \DateTimeImmutable('-1 year'))->format('Y-m-d');
        $fromValue = (string) $request->request->get('from', $defaultFrom);
        $isinValue = (string) $request->request->get('isins', '');
        $importResults = [];

        if ($request->isMethod('POST')) {
            try {
                $from = (new \DateTimeImmutable($fromValue))->setTime(0, 0);
                $to = (new \DateTimeImmutable('today'))->setTime(23, 59, 59);
                $importSource = (string) $request->request->get('import_source', 'manual');
                $isins = $importSource === 'boursobank_top'
                    ? $this->boursobankTopIsins($request, $boursobankTopEtfClient)
                    : $this->parseIsins($isinValue);
                $isinValue = implode("\n", $isins);

                if ($isins === []) {
                    $importResults[] = $this->errorRow($importSource === 'boursobank_top' ? 'Aucun ISIN trouve dans le palmares Boursobank.' : 'Saisis au moins un code ISIN.');
                } else {
                    $importResults = $marketDataImporter->importIsins($isins, $from, $to);
                }
            } catch (\Throwable $exception) {
                $importResults[] = $this->errorRow($exception->getMessage());
            }
        }

        return $this->render('home/index.html.twig', [
            'from' => $fromValue,
            'isins' => $isinValue,
            'importResults' => $importResults,
            'universe' => $this->universe($etfRepository, $pricePointRepository),
            'momentumRanking' => $this->momentumRanking($momentumSnapshotRepository, $trailingStopAdvisor),
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseIsins(string $rawValue): array
    {
        $isins = preg_split('/[\s,;]+/', strtoupper($rawValue), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($isins ?: []));
    }

    /**
     * @return list<string>
     */
    private function boursobankTopIsins(Request $request, BoursobankTopEtfClient $boursobankTopEtfClient): array
    {
        $topCount = min(20, max(1, (int) $request->request->get('top_count', 5)));
        $topEtfs = $boursobankTopEtfClient->fetchTopEtfs($topCount);

        return array_values(array_unique(array_map(
            static fn (array $etf): string => $etf['isin'],
            $topEtfs,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function errorRow(string $message): array
    {
        return [
            'status' => 'error',
            'isin' => '',
            'symbol' => '',
            'name' => '',
            'providerSymbol' => '',
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'message' => $message,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function universe(EtfRepository $etfRepository, PricePointRepository $pricePointRepository): array
    {
        $rows = [];

        foreach ($etfRepository->findBy([], ['symbol' => 'ASC']) as $etf) {
            $latestPrice = $pricePointRepository->findLatestForEtf($etf);

            $rows[] = [
                'etf' => $etf,
                'pricePointCount' => $pricePointRepository->countForEtf($etf),
                'latestPrice' => $latestPrice,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function momentumRanking(
        MomentumSnapshotRepository $momentumSnapshotRepository,
        TrailingStopAdvisor $trailingStopAdvisor,
    ): array {
        return array_map(
            static fn ($snapshot): array => [
                'snapshot' => $snapshot,
                'trailingStopAdvice' => $trailingStopAdvisor->advise($snapshot->getEtf()),
            ],
            $momentumSnapshotRepository->findLatestByStrategy(MomentumCalculator::STRATEGY_CODE),
        );
    }
}
