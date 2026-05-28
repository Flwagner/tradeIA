<?php

namespace App\MarketData;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YahooFinanceClient
{
    private const SOURCE = 'yahoo';
    private const USER_AGENT = 'Mozilla/5.0 tradeIA/0.1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{
     *     pricedAt: \DateTimeImmutable,
     *     openPrice: string|null,
     *     highPrice: string|null,
     *     lowPrice: string|null,
     *     closePrice: string,
     *     adjustedClosePrice: string|null,
     *     volume: int|null,
     *     source: string
     * }>
     */
    public function fetchDailyPrices(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $url = sprintf('https://query1.finance.yahoo.com/v8/finance/chart/%s', rawurlencode($symbol));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'query' => [
                    'period1' => $from->getTimestamp(),
                    'period2' => $to->getTimestamp(),
                    'interval' => '1d',
                    'events' => 'history',
                    'includeAdjustedClose' => 'true',
                ],
                'timeout' => 20,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException(sprintf('Unable to fetch Yahoo Finance data for "%s".', $symbol), previous: $exception);
        }

        if (($payload['chart']['error'] ?? null) !== null) {
            $description = $payload['chart']['error']['description'] ?? 'Unknown Yahoo Finance error';

            throw new \RuntimeException(sprintf('Yahoo Finance rejected "%s": %s', $symbol, $description));
        }

        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $adjustedCloses = $result['indicators']['adjclose'][0]['adjclose'] ?? [];
        $timezone = new \DateTimeZone($result['meta']['exchangeTimezoneName'] ?? 'Europe/Paris');
        $prices = [];

        foreach ($timestamps as $index => $timestamp) {
            $close = $quote['close'][$index] ?? null;

            if ($close === null) {
                continue;
            }

            $prices[] = [
                'pricedAt' => (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->setTime(0, 0),
                'openPrice' => $this->decimal($quote['open'][$index] ?? null),
                'highPrice' => $this->decimal($quote['high'][$index] ?? null),
                'lowPrice' => $this->decimal($quote['low'][$index] ?? null),
                'closePrice' => $this->decimal($close) ?? throw new \RuntimeException('Close price cannot be null.'),
                'adjustedClosePrice' => $this->decimal($adjustedCloses[$index] ?? null),
                'volume' => isset($quote['volume'][$index]) ? (int) $quote['volume'][$index] : null,
                'source' => self::SOURCE,
            ];
        }

        return $prices;
    }

    /**
     * @return array{
     *     symbol: string,
     *     name: string,
     *     exchange: string,
     *     currency: string,
     *     data_provider_symbol: string
     * }|null
     */
    public function searchEtfByIsin(string $isin): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://query1.finance.yahoo.com/v1/finance/search', [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'query' => [
                    'q' => strtoupper($isin),
                    'quotesCount' => 10,
                    'newsCount' => 0,
                ],
                'timeout' => 20,
            ]);

            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException(sprintf('Unable to search Yahoo Finance data for ISIN "%s".', $isin), previous: $exception);
        }

        $quotes = $payload['quotes'] ?? [];

        if (!is_array($quotes)) {
            return null;
        }

        $quote = $this->firstEtfQuote($quotes);

        if ($quote === null || !isset($quote['symbol'])) {
            return null;
        }

        $providerSymbol = (string) $quote['symbol'];

        return [
            'symbol' => $this->localSymbol($providerSymbol),
            'name' => (string) ($quote['longname'] ?? $quote['shortname'] ?? $providerSymbol),
            'exchange' => $this->exchangeCode((string) ($quote['exchange'] ?? '')),
            'currency' => $this->currencyForProviderSymbol($providerSymbol),
            'data_provider_symbol' => $providerSymbol,
        ];
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

    /**
     * @param list<array<string, mixed>> $quotes
     *
     * @return array<string, mixed>|null
     */
    private function firstEtfQuote(array $quotes): ?array
    {
        foreach ($quotes as $quote) {
            if (($quote['quoteType'] ?? null) === 'ETF') {
                return $quote;
            }
        }

        return $quotes[0] ?? null;
    }

    private function localSymbol(string $providerSymbol): string
    {
        return strtoupper(strtok($providerSymbol, '.') ?: $providerSymbol);
    }

    private function exchangeCode(string $exchange): string
    {
        return match (strtoupper($exchange)) {
            'PAR' => 'XPAR',
            default => strtoupper($exchange !== '' ? $exchange : 'UNKNOWN'),
        };
    }

    private function currencyForProviderSymbol(string $providerSymbol): string
    {
        return str_ends_with(strtoupper($providerSymbol), '.PA') ? 'EUR' : 'EUR';
    }
}
