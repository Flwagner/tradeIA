<?php

namespace App\Controller;

use App\Boursobank\BoursobankTopEtfClient;
use App\Entity\PricePoint;
use App\MarketData\MarketDataImporter;
use App\Momentum\MomentumCalculator;
use App\Momentum\MomentumComputer;
use App\Repository\EtfRepository;
use App\Repository\MomentumSnapshotRepository;
use App\Repository\PricePointRepository;
use App\Risk\TrailingStopAdvisor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        $universe = $this->universe($etfRepository, $pricePointRepository);
        $momentumRanking = $this->momentumRanking($momentumSnapshotRepository, $trailingStopAdvisor);

        return $this->render('home/index.html.twig', [
            'from' => $fromValue,
            'isins' => $isinValue,
            'importResults' => $importResults,
            'universe' => $universe,
            'momentumRanking' => $momentumRanking,
            'decision' => $this->decision($momentumRanking, $pricePointRepository),
        ]);
    }

    #[Route('/decision/refresh', name: 'app_decision_refresh', methods: ['POST'])]
    public function refreshDecision(
        Request $request,
        EtfRepository $etfRepository,
        MarketDataImporter $marketDataImporter,
        MomentumComputer $momentumComputer,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('refresh_decision', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $from = (new \DateTimeImmutable('-1 year'))->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(23, 59, 59);
        $refreshed = 0;
        $failed = [];

        foreach ($etfRepository->findBy([], ['symbol' => 'ASC']) as $etf) {
            try {
                $marketDataImporter->refreshEtf($etf, $from, $to);
                ++$refreshed;
            } catch (\Throwable) {
                $failed[] = $etf->getSymbol();
            }
        }

        $rows = $momentumComputer->computeAll((new \DateTimeImmutable('today'))->setTime(0, 0));
        $computed = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'computed'));
        $message = sprintf(
            'Decision mise a jour : %d ETF rafraichi%s, %d score%s momentum recalcule%s.',
            $refreshed,
            $refreshed > 1 ? 's' : '',
            $computed,
            $computed > 1 ? 's' : '',
            $computed > 1 ? 's' : '',
        );

        if ($failed !== []) {
            $message .= sprintf(' Erreur prix sur : %s.', implode(', ', $failed));
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_home');
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

    /**
     * @param list<array<string, mixed>> $momentumRanking
     *
     * @return array<string, mixed>|null
     */
    private function decision(array $momentumRanking, PricePointRepository $pricePointRepository): ?array
    {
        if ($momentumRanking === []) {
            return null;
        }

        $topRanked = $momentumRanking[0];
        $snapshot = $topRanked['snapshot'];
        $latestPrice = $pricePointRepository->findLatestForEtf($snapshot->getEtf());
        $latestClose = $this->latestClose($latestPrice);

        return [
            'snapshot' => $snapshot,
            'trailingStopAdvice' => $topRanked['trailingStopAdvice'],
            'latestPrice' => $latestPrice,
            'latestClose' => $latestClose,
            'freshness' => $this->freshness($latestPrice, $snapshot->getComputedAt()),
        ];
    }

    /**
     * @return array{status: string, label: string, priceAgeDays: int|null, scoreAgeDays: int}
     */
    private function freshness(?PricePoint $latestPrice, \DateTimeImmutable $computedAt): array
    {
        $priceAgeDays = $latestPrice instanceof PricePoint ? $this->daysSince($latestPrice->getPricedAt()) : null;
        $scoreAgeDays = $this->daysSince($computedAt);
        $isFresh = $priceAgeDays !== null && $priceAgeDays <= 3 && $scoreAgeDays <= 1;

        return [
            'status' => $isFresh ? 'fresh' : 'stale',
            'label' => $isFresh ? 'Données fraîches' : 'À rafraîchir',
            'priceAgeDays' => $priceAgeDays,
            'scoreAgeDays' => $scoreAgeDays,
        ];
    }

    private function daysSince(\DateTimeImmutable $date): int
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $date = $date->setTime(0, 0);

        return max(0, (int) $date->diff($today)->format('%r%a'));
    }

    private function latestClose(?PricePoint $pricePoint): ?float
    {
        if ($pricePoint === null) {
            return null;
        }

        $adjustedClose = $pricePoint->getAdjustedClosePrice();

        if ($adjustedClose !== null) {
            return (float) $adjustedClose;
        }

        return (float) $pricePoint->getClosePrice();
    }
}
