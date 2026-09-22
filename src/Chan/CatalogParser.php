<?php

declare(strict_types=1);

namespace App\Chan;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CatalogParser
{
    private const CATALOG_URL = 'https://a.4cdn.org/g/catalog.json';
    private Client $httpClient;

    public function __construct(?Client $client = null)
    {
        $this->httpClient = $client ?? new Client([
            'timeout' => 15.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Fetch catalog and find active /twg/ and /utwg/ threads.
     * Returns an associative array with found threads:
     * [
     *   'twg' => [ ['no' => 123, 'sub' => '...', 'replies' => 45], ... ],
     *   'utwg' => [ ['no' => 456, 'sub' => '...', 'replies' => 67], ... ]
     * ]
     *
     * @return array{twg: array<int, array>, utwg: array<int, array>}
     * @throws GuzzleException|\JsonException
     */
    public function findTargetThreads(): array
    {
        $response = $this->httpClient->get(self::CATALOG_URL);
        $pages = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        $results = [
            'twg' => [],
            'utwg' => [],
        ];

        foreach ($pages as $page) {
            foreach ($page['threads'] as $thread) {
                $sub = (string)($thread['sub'] ?? '');
                $com = (string)($thread['com'] ?? '');
                $fullText = $sub . ' ' . $com;

                // Match /utwg/ (Unemployable Tech Workers General)
                if ($this->isUtwg($sub, $com)) {
                    $results['utwg'][] = $this->formatThreadSummary($thread);
                    continue; // An utwg thread should not be counted as a regular twg thread
                }

                // Match /twg/ (Tech Workers General)
                if ($this->isTwg($sub, $com)) {
                    $results['twg'][] = $this->formatThreadSummary($thread);
                }
            }
        }

        return $results;
    }

    private function isUtwg(string $sub, string $com): bool
    {
        if (preg_match('/(?:\/utwg\/|unemployable\s+tech\s+workers)/i', $sub)) {
            return true;
        }

        return (bool)preg_match('/(?:\/utwg\/|unemployable\s+tech\s+workers\s+general)/i', $com);
    }

    private function isTwg(string $sub, string $com): bool
    {
        // Must match /twg/ but NOT /utwg/
        if (preg_match('/(?<!u)\/twg\//i', $sub) || stripos($sub, 'tech workers general') !== false) {
            return true;
        }

        return (bool)(preg_match('/(?<!u)\/twg\//i', $com) && stripos($com, 'tech workers general') !== false);
    }

    private function formatThreadSummary(array $thread): array
    {
        return [
            'no' => (int)$thread['no'],
            'sub' => (string)($thread['sub'] ?? ''),
            'com' => (string)($thread['com'] ?? ''),
            'time' => (int)($thread['time'] ?? 0),
            'replies' => (int)($thread['replies'] ?? 0),
            'images' => (int)($thread['images'] ?? 0),
            'last_modified' => (int)($thread['last_modified'] ?? 0),
            'url' => sprintf('https://boards.4chan.org/g/thread/%d', $thread['no']),
        ];
    }
}
