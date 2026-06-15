<?php

namespace App\Boursobank;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BoursobankTopEtfClient
{
    private const SEARCH_URL = 'https://www.boursorama.com/bourse/trackers/recherche/';
    private const BASE_URL = 'https://www.boursorama.com';
    private const USER_AGENT = 'Mozilla/5.0 tradeIA/0.1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{rank: int, name: string, isin: string, boursoIdentifier: string, url: string}>
     */
    public function fetchTopEtfs(int $limit): array
    {
        $limit = min(20, max(1, $limit));

        try {
            $html = $this->fetchHtml(self::SEARCH_URL);
            $rows = $this->parseSearchRows($html, $limit);
            $etfs = [];

            foreach ($rows as $row) {
                $detailHtml = $this->fetchHtml($row['url']);
                $isin = $this->parseIsin($detailHtml);

                if (null === $isin) {
                    continue;
                }

                $etfs[] = [
                    ...$row,
                    'isin' => $isin,
                ];
            }
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Impossible de recuperer le palmares Boursobank.', previous: $exception);
        }

        return $etfs;
    }

    /**
     * @return list<array{rank: int, name: string, boursoIdentifier: string, url: string}>
     */
    private function parseSearchRows(string $html, int $limit): array
    {
        $xpath = $this->xpath($html);
        $rows = $xpath->query('//table[.//h3[contains(normalize-space(.), "Perf. 1 an")]]//tbody/tr');

        if (!$rows instanceof \DOMNodeList) {
            return [];
        }

        $results = [];
        $seen = [];

        foreach ($rows as $row) {
            $anchor = $xpath->query('.//a[contains(@href, "/bourse/trackers/cours/")]', $row)->item(0);

            if (!$anchor instanceof \DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($anchor->getAttribute('href'));
            $identifier = $this->boursoIdentifier($url);

            if ('' === $identifier || isset($seen[$identifier])) {
                continue;
            }

            $seen[$identifier] = true;
            $results[] = [
                'rank' => count($results) + 1,
                'name' => $this->cleanText($anchor->textContent),
                'boursoIdentifier' => $identifier,
                'url' => $url,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function parseIsin(string $html): ?string
    {
        $xpath = $this->xpath($html);
        $node = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " c-faceplate__isin ")]')->item(0);

        if ($node instanceof \DOMNode && 1 === preg_match('/\b[A-Z]{2}[A-Z0-9]{9}[0-9]\b/', $node->textContent, $match)) {
            return $match[0];
        }

        return null;
    }

    private function fetchHtml(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => self::USER_AGENT,
            ],
            'timeout' => 25,
            'max_redirects' => 5,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Boursobank a rejete "%s".', $url));
        }

        return $response->getContent(false);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return new \DOMXPath($document);
    }

    private function cleanText(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'https://')) {
            return $href;
        }

        return self::BASE_URL.'/'.ltrim($href, '/');
    }

    private function boursoIdentifier(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return '';
        }

        return trim(basename(rtrim($path, '/')));
    }
}
