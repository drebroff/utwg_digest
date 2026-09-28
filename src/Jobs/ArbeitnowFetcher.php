<?php

declare(strict_types=1);

namespace App\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

class ArbeitnowFetcher
{
    private const API_ENDPOINT = 'https://arbeitnow.com/api/job-board-api';
    private const MAX_PAGES = 8;

    private Client $httpClient;

    public function __construct(?Client $client = null)
    {
        $this->httpClient = $client ?? new Client([
            'timeout' => 15.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Fetch jobs created on a specific date matching PHP/Symfony/Drupal/Magento and Remote/Hybrid criteria.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param string $timezone
     * @return array<int, array{
     *     id: string,
     *     title: string,
     *     company: string,
     *     location: string,
     *     is_remote: bool,
     *     work_type: string,
     *     url: string,
     *     original_url: string,
     *     created_at: int,
     *     date_formatted: string,
     *     tags: array<string>,
     *     description_snippet: string,
     *     raw_description: string
     * }>
     */
    public function fetchJobsForDate(string $targetDate, string $timezone = 'UTC'): array
    {
        $tz = new DateTimeZone($timezone);
        $startDateTime = new DateTimeImmutable($targetDate . ' 00:00:00', $tz);
        $endDateTime = new DateTimeImmutable($targetDate . ' 23:59:59', $tz);

        $startTimestamp = $startDateTime->getTimestamp();
        $endTimestamp = $endDateTime->getTimestamp();

        $candidates = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $url = self::API_ENDPOINT . '?page=' . $page;

            try {
                $response = $this->httpClient->get($url);
                $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (GuzzleException | \JsonException $e) {
                // If a page fetch fails, log/break out with whatever was collected
                break;
            }

            $jobs = $data['data'] ?? [];
            if (empty($jobs)) {
                break;
            }

            $reachedOlder = false;

            foreach ($jobs as $idx => $job) {
                $createdAt = (int)($job['created_at'] ?? 0);

                // Skip featured/pinned oldest item if on page 1 index 0
                if ($page === 1 && $idx === 0 && $createdAt < $startTimestamp) {
                    continue;
                }

                // If job is older than the target day start, we have paged past our target window
                if ($createdAt < $startTimestamp) {
                    $reachedOlder = true;
                    continue;
                }

                // Only consider jobs within target date
                if ($createdAt > $endTimestamp) {
                    // Job is newer than target date (e.g. posted today when fetching yesterday)
                    continue;
                }

                // Check keyword criteria (PHP / Symfony / Drupal / Magento)
                if (!$this->matchesTechStack($job)) {
                    continue;
                }

                // Check Remote or Hybrid criteria
                $workType = $this->determineWorkType($job);
                if ($workType === null) {
                    continue;
                }

                // Resolve direct company careers / ATS link if available
                $originalUrl = (string)($job['url'] ?? '');
                $resolvedUrl = $this->resolveDirectCareersUrl($originalUrl);

                $cleanDesc = html_entity_decode(strip_tags((string)($job['description'] ?? '')));
                $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', $cleanDesc)), 0, 1200);

                $candidates[] = [
                    'id' => (string)($job['slug'] ?? uniqid('job_', true)),
                    'title' => trim((string)($job['title'] ?? '')),
                    'company' => trim((string)($job['company_name'] ?? 'Unknown Company')),
                    'location' => trim((string)($job['location'] ?? 'Europe')),
                    'is_remote' => (bool)($job['remote'] ?? false),
                    'work_type' => $workType,
                    'url' => $resolvedUrl,
                    'original_url' => $originalUrl,
                    'created_at' => $createdAt,
                    'date_formatted' => (new DateTimeImmutable('@' . $createdAt))->setTimezone($tz)->format('Y-m-d H:i:s'),
                    'tags' => $job['tags'] ?? [],
                    'description_snippet' => $snippet,
                    'raw_description' => $cleanDesc,
                ];
            }

            if ($reachedOlder) {
                break;
            }

            $page++;
        }

        return $candidates;
    }

    /**
     * Check if job matches PHP, Symfony, Drupal, or Magento2 keywords.
     *
     * @param array<string, mixed> $job
     * @return bool
     */
    public function matchesTechStack(array $job): bool
    {
        $title = (string)($job['title'] ?? '');
        $tags = implode(' ', (array)($job['tags'] ?? []));
        $desc = html_entity_decode(strip_tags((string)($job['description'] ?? '')));

        // High confidence match in title or tags
        $titleAndTags = $title . ' ' . $tags;
        if (preg_match('/\b(php|symfony|drupal|magento|magento2|adobe\s+commerce)\b/i', $titleAndTags)) {
            return true;
        }

        // Frameworks in description
        if (preg_match('/\b(symfony|drupal|magento|magento2|adobe\s+commerce)\b/i', $desc)) {
            return true;
        }

        // PHP in description as backend tech
        if (preg_match('/\bphp\s*(\d|\.|\b|developer|engineer|backend|fullstack|software)/i', $desc)) {
            return true;
        }

        return false;
    }

    /**
     * Check if job allows Remote or Hybrid work.
     *
     * @param array<string, mixed> $job
     * @return string|null 'Remote', 'Hybrid', or null if purely on-site
     */
    public function determineWorkType(array $job): ?string
    {
        if (!empty($job['remote'])) {
            return 'Remote';
        }

        $haystack = strtolower(
            (string)($job['title'] ?? '') . ' ' .
            (string)($job['location'] ?? '') . ' ' .
            html_entity_decode(strip_tags((string)($job['description'] ?? '')))
        );

        if (preg_match('/\b(full\s*remote|100%\s*remote|remote\s*first|fully\s*remote)\b/i', $haystack)) {
            return 'Remote';
        }

        if (preg_match('/\b(hybrid|remote\s*m[oö]glich|home\s*office|homeoffice|telework|telecommute|flexible\s*location)\b/i', $haystack)) {
            return 'Hybrid';
        }

        if (preg_match('/\bremote\b/i', $haystack)) {
            return 'Remote';
        }

        return null;
    }

    /**
     * Resolve direct company careers or ATS link by inspecting Arbeitnow's /apply redirect.
     *
     * @param string $url
     * @return string
     */
    public function resolveDirectCareersUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        // If it's already an external company site, return it
        if (!str_contains($url, 'arbeitnow.com/jobs/companies/')) {
            return $url;
        }

        $applyUrl = rtrim($url, '/') . '/apply';

        try {
            $response = $this->httpClient->head($applyUrl, [
                'allow_redirects' => false,
                'timeout' => 4.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300 && $statusCode < 400 && $response->hasHeader('Location')) {
                $targetUrl = $response->getHeaderLine('Location');
                return $this->cleanTrackingParameters($targetUrl);
            }
        } catch (Throwable) {
            // If redirect resolution fails or times out, fallback to original Arbeitnow URL
        }

        return $url;
    }

    /**
     * Clean affiliate and tracking query parameters from resolved URL.
     *
     * @param string $url
     * @return string
     */
    private function cleanTrackingParameters(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        unset(
            $query['utm_source'],
            $query['utm_medium'],
            $query['utm_campaign'],
            $query['utm_term'],
            $query['utm_content'],
            $query['ref']
        );

        $cleanUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $cleanUrl .= ':' . $parts['port'];
        }
        $cleanUrl .= $parts['path'] ?? '';

        if (!empty($query)) {
            $cleanUrl .= '?' . http_build_query($query);
        }
        if (!empty($parts['fragment'])) {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }
}
