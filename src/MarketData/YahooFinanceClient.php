<?php

namespace App\MarketData;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YahooFinanceClient
{
    private const SOURCE = 'yahoo';

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
                    'User-Agent' => 'Mozilla/5.0 tradeIA/0.1',
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

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }
}
