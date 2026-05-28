<?php

namespace App\Command;

use App\Entity\Etf;
use App\Entity\PricePoint;
use App\MarketData\YahooFinanceClient;
use App\Repository\EtfRepository;
use App\Repository\PricePointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:market-data:import',
    description: 'Import daily ETF prices from the configured market data provider.',
)]
class ImportMarketDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EtfRepository $etfRepository,
        private readonly PricePointRepository $pricePointRepository,
        private readonly YahooFinanceClient $yahooFinanceClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'ETF universe YAML file.', 'config/tradeia/etfs.yaml')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Import only one ETF symbol from the YAML file.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date, parseable by DateTimeImmutable.', '-1 year')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date, parseable by DateTimeImmutable.', 'today')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and parse data without writing to the database.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configPath = $this->resolvePath((string) $input->getOption('config'));
        $symbolFilter = $input->getOption('symbol') !== null ? strtoupper((string) $input->getOption('symbol')) : null;
        $from = $this->date((string) $input->getOption('from'))->setTime(0, 0);
        $to = $this->date((string) $input->getOption('to'))->setTime(23, 59, 59);
        $dryRun = (bool) $input->getOption('dry-run');
        $rows = $this->loadUniverse($configPath);
        $summary = [];

        foreach ($rows as $row) {
            if ($symbolFilter !== null && strtoupper((string) $row['symbol']) !== $symbolFilter && strtoupper((string) ($row['data_provider_symbol'] ?? '')) !== $symbolFilter) {
                continue;
            }

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

            $summary[] = [$etf->getSymbol(), $providerSymbol, count($prices), $inserted, $updated];

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        if ($dryRun) {
            $this->entityManager->clear();
            $io->warning('Dry run: no database write was performed.');
        }

        if ($summary === []) {
            $io->warning('No ETF matched the given options.');

            return Command::SUCCESS;
        }

        $io->table(['ETF', 'Provider symbol', 'Fetched', 'Inserted', 'Updated'], $summary);
        $io->success('Market data import completed.');

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectDir . '/' . $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUniverse(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new \RuntimeException(sprintf('ETF universe file not found: %s', $configPath));
        }

        $config = Yaml::parseFile($configPath);
        $rows = $config['etfs'] ?? null;

        if (!is_array($rows)) {
            throw new \RuntimeException(sprintf('ETF universe file must contain an "etfs" list: %s', $configPath));
        }

        return array_values($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertEtf(array $row): Etf
    {
        foreach (['isin', 'symbol', 'name', 'exchange'] as $requiredField) {
            if (!isset($row[$requiredField]) || trim((string) $row[$requiredField]) === '') {
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

    private function date(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf('Invalid date "%s".', $value), previous: $exception);
        }
    }
}
