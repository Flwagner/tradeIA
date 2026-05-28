<?php

namespace App\Controller;

use App\MarketData\MarketDataImporter;
use App\Repository\EtfRepository;
use App\Repository\PricePointRepository;
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
        MarketDataImporter $marketDataImporter,
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
                $isins = $this->parseIsins($isinValue);

                if ($isins === []) {
                    $importResults[] = [
                        'status' => 'error',
                        'isin' => '',
                        'symbol' => '',
                        'name' => '',
                        'providerSymbol' => '',
                        'fetched' => 0,
                        'inserted' => 0,
                        'updated' => 0,
                        'message' => 'Saisis au moins un code ISIN.',
                    ];
                } else {
                    $importResults = $marketDataImporter->importIsins($isins, $from, $to);
                }
            } catch (\Throwable $exception) {
                $importResults[] = [
                    'status' => 'error',
                    'isin' => '',
                    'symbol' => '',
                    'name' => '',
                    'providerSymbol' => '',
                    'fetched' => 0,
                    'inserted' => 0,
                    'updated' => 0,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $this->render('home/index.html.twig', [
            'from' => $fromValue,
            'isins' => $isinValue,
            'importResults' => $importResults,
            'universe' => $this->universe($etfRepository, $pricePointRepository),
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
}
