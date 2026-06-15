<?php

namespace App\MarketData;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\Repository\EtfRepository;
use App\Repository\PricePointRepository;
use Doctrine\ORM\EntityManagerInterface;

class MarketDataImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EtfRepository $etfRepository,
        private readonly PricePointRepository $pricePointRepository,
        private readonly YahooFinanceClient $yahooFinanceClient,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function importRows(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $dryRun = false, ?string $symbolFilter = null): array
    {
        $summary = [];
        $symbolFilter = null !== $symbolFilter ? strtoupper($symbolFilter) : null;

        foreach ($rows as $row) {
            if (null !== $symbolFilter && strtoupper((string) $row['symbol']) !== $symbolFilter && strtoupper((string) ($row['data_provider_symbol'] ?? '')) !== $symbolFilter) {
                continue;
            }

            $summary[] = $this->importRow($row, $from, $to, $dryRun);
        }

        if ($dryRun) {
            $this->entityManager->clear();
        }

        return $summary;
    }

    /**
     * @param list<string> $isins
     *
     * @return list<array<string, mixed>>
     */
    public function importIsins(array $isins, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $summary = [];

        foreach ($isins as $isin) {
            $isin = strtoupper(trim($isin));

            if (!$this->isIsin($isin)) {
                $summary[] = $this->errorRow($isin, 'Code ISIN invalide.');

                continue;
            }

            try {
                $match = $this->yahooFinanceClient->searchEtfByIsin($isin);

                if (null === $match) {
                    $summary[] = $this->errorRow($isin, 'Aucun ETF trouve chez Yahoo Finance.');

                    continue;
                }

                $summary[] = $this->importRow([
                    ...$match,
                    'isin' => $isin,
                    'pea_eligible' => false,
                    'active' => true,
                ], $from, $to);
            } catch (\Throwable $exception) {
                $summary[] = $this->errorRow($isin, $exception->getMessage());
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshEtf(Etf $etf, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->importRow([
            'isin' => $etf->getIsin(),
            'symbol' => $etf->getSymbol(),
            'name' => $etf->getName(),
            'exchange' => $etf->getExchange(),
            'currency' => $etf->getCurrency(),
            'pea_eligible' => $etf->isPeaEligible(),
            'active' => $etf->isActive(),
            'bourso_identifier' => $etf->getBoursoIdentifier(),
            'data_provider_symbol' => $etf->getDataProviderSymbol(),
        ], $from, $to);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function importRow(array $row, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $dryRun = false): array
    {
        $etf = $this->upsertEtf($row);
        $providerSymbol = (string) ($row['data_provider_symbol'] ?? $row['symbol']);
        $prices = $this->yahooFinanceClient->fetchDailyPrices($providerSymbol, $from, $to);
        $inserted = 0;
        $updated = 0;

        foreach ($prices as $priceData) {
            $pricePoint = $this->pricePointRepository->findOneBy([
                'etf' => $etf,
                'pricedAt' => $priceData['pricedAt'],
                'source' => $priceData['source'],
            ]);

            if (!$pricePoint instanceof PricePoint) {
                $pricePoint = (new PricePoint())
                    ->setEtf($etf)
                    ->setPricedAt($priceData['pricedAt'])
                    ->setSource($priceData['source'])
                ;

                $this->entityManager->persist($pricePoint);
                ++$inserted;
            } else {
                ++$updated;
            }

            $pricePoint
                ->setOpenPrice($priceData['openPrice'])
                ->setHighPrice($priceData['highPrice'])
                ->setLowPrice($priceData['lowPrice'])
                ->setClosePrice($priceData['closePrice'])
                ->setAdjustedClosePrice($priceData['adjustedClosePrice'])
                ->setVolume($priceData['volume'])
            ;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return [
            'status' => 'success',
            'isin' => $etf->getIsin(),
            'symbol' => $etf->getSymbol(),
            'name' => $etf->getName(),
            'providerSymbol' => $providerSymbol,
            'fetched' => count($prices),
            'inserted' => $inserted,
            'updated' => $updated,
            'message' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertEtf(array $row): Etf
    {
        foreach (['isin', 'symbol', 'name', 'exchange'] as $requiredField) {
            if (!isset($row[$requiredField]) || '' === trim((string) $row[$requiredField])) {
                throw new \RuntimeException(sprintf('Missing required ETF field "%s".', $requiredField));
            }
        }

        $etf = $this->etfRepository->findOneBy(['isin' => strtoupper((string) $row['isin'])]) ?? new Etf();

        $etf
            ->setIsin((string) $row['isin'])
            ->setSymbol((string) $row['symbol'])
            ->setName((string) $row['name'])
            ->setExchange((string) $row['exchange'])
            ->setCurrency((string) ($row['currency'] ?? 'EUR'))
            ->setPeaEligible((bool) ($row['pea_eligible'] ?? false))
            ->setActive((bool) ($row['active'] ?? true))
            ->setBoursoIdentifier(isset($row['bourso_identifier']) ? (string) $row['bourso_identifier'] : null)
            ->setDataProviderSymbol(isset($row['data_provider_symbol']) ? (string) $row['data_provider_symbol'] : null)
            ->touch()
        ;

        $this->entityManager->persist($etf);

        return $etf;
    }

    private function isIsin(string $isin): bool
    {
        return 1 === preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorRow(string $isin, string $message): array
    {
        return [
            'status' => 'error',
            'isin' => $isin,
            'symbol' => '',
            'name' => '',
            'providerSymbol' => '',
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'message' => $message,
        ];
    }
}
