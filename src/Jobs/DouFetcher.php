<?php

declare(strict_types=1);

namespace App\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;
use Throwable;

class DouFetcher
{
    private const FEEDS = [
        'https://jobs.dou.ua/vacancies/feeds/?category=PHP',
        'https://jobs.dou.ua/vacancies/feeds/?search=Symfony',
        'https://jobs.dou.ua/vacancies/feeds/?search=Drupal',
        'https://jobs.dou.ua/vacancies/feeds/?search=Magento',
    ];

    private Client $httpClient;

    public function __construct(?Client $client = null)
    {
        $this->httpClient = $client ?? new Client([
            'timeout' => 15.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
            ],
        ]);
    }

    /**
     * Fetch jobs from DOU.ua feeds published on a specific date matching PHP/Symfony/Drupal/Magento and Remote/Hybrid/Military criteria.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param string $timezone
     * @return array<int, array{
     *     id: string,
     *     title: string,
     *     company: string,
     *     location: string,
     *     salary: string,
     *     is_remote: bool,
     *     is_military: bool,
     *     work_type: string,
     *     url: string,
     *     pub_date: string,
     *     tags: array<string>,
     *     description_snippet: string,
     *     raw_description: string
     * }>
     */
    public function fetchJobsForDate(string $targetDate, string $timezone = 'Europe/Kyiv'): array
    {
        $tz = new DateTimeZone($timezone);
        $candidates = [];
        $seenUrls = [];

        foreach (self::FEEDS as $feedUrl) {
            try {
                $response = $this->httpClient->get($feedUrl);
                $xmlContent = $response->getBody()->getContents();
                $xml = simplexml_load_string($xmlContent, SimpleXMLElement::class, LIBXML_NOCDATA);
            } catch (GuzzleException | Throwable $e) {
                // Skip failed feed and continue with others
                continue;
            }

            if (!$xml || !isset($xml->channel->item)) {
                continue;
            }

            foreach ($xml->channel->item as $item) {
                $rawLink = (string)$item->link;
                $cleanUrl = preg_replace('/\?.*$/', '', $rawLink) ?? $rawLink;

                if (isset($seenUrls[$cleanUrl])) {
                    continue;
                }

                $pubDateStr = (string)$item->pubDate;
                if (empty($pubDateStr)) {
                    continue;
                }

                try {
                    $dt = new DateTimeImmutable($pubDateStr);
                    $itemDate = $dt->setTimezone($tz)->format('Y-m-d');
                } catch (Throwable) {
                    continue;
                }

                // Check date match
                if ($itemDate !== $targetDate) {
                    continue;
                }

                $rawTitle = (string)$item->title;
                $rawDescription = (string)$item->description;

                // Check tech stack / relevance match
                if (!$this->matchesTechStack($rawTitle, $rawDescription)) {
                    continue;
                }

                $parsedTitle = $this->parseTitleMeta($rawTitle);
                $workType = $this->determineWorkType($rawTitle, $rawDescription, $parsedTitle['is_military']);

                // If strictly on-site non-military, skip
                if ($workType === null) {
                    continue;
                }

                $tags = $this->extractTags($rawTitle, $rawDescription);
                $snippet = $this->cleanDescriptionSnippet($rawDescription);

                $seenUrls[$cleanUrl] = true;
                $candidates[] = [
                    'id' => $cleanUrl,
                    'source' => 'DOU.ua',
                    'title' => $parsedTitle['title'],
                    'company' => $parsedTitle['company'],
                    'location' => $parsedTitle['location'],
                    'salary' => $parsedTitle['salary'],
                    'is_remote' => $parsedTitle['is_remote'],
                    'is_military' => $parsedTitle['is_military'],
                    'work_type' => $workType,
                    'url' => $cleanUrl,
                    'pub_date' => $dt->setTimezone($tz)->format('Y-m-d H:i:s'),
                    'tags' => $tags,
                    'description_snippet' => $snippet,
                    'raw_description' => $rawDescription,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Parse title string into components (Title, Company, Salary, Location, Remote, Military).
     *
     * Example DOU title formats:
     * "Senior PHP Software Engineer в TrueLabel, Лісабон (Португалія), віддалено"
     * "Backend Developer (PHP, Laravel) в 13 бригада оперативного призначення НГУ «Хартія», $560–1700"
     * "Drupal developer в DevBranch, $1000–5200, Луцьк"
     *
     * @return array{
     *     title: string,
     *     company: string,
     *     salary: string,
     *     location: string,
     *     is_remote: bool,
     *     is_military: bool
     * }
     */
    public function parseTitleMeta(string $rawTitle): array
    {
        $parts = preg_split('/\s+в\s+/u', $rawTitle, 2);
        $jobTitle = trim($parts[0] ?? $rawTitle);
        $meta = trim($parts[1] ?? '');

        $salary = '';
        if (preg_match('/(?:[\$€₴]\s*[\d\s,.\x{2013}\x{2014}\-]+|\b\d+[\d\s,.\x{2013}\x{2014}\-]*\s*(?:грн|USD|EUR|UAH))/u', $rawTitle, $sm)) {
            $salary = trim($sm[0]);
        }

        $isRemote = (bool)preg_match('/віддалено|remote/iu', $meta);
        $isMilitary = $this->isMilitaryVacancy($rawTitle);

        // Split meta components by comma
        $metaParts = array_map('trim', explode(',', $meta));
        $company = $metaParts[0] ?? 'Компанія на DOU';

        // Extract location parts (excluding salary and company)
        $locationParts = [];
        for ($i = 1, $count = count($metaParts); $i < $count; $i++) {
            $p = $metaParts[$i];
            if ($salary !== '' && str_contains($p, $salary)) {
                continue;
            }
            $locationParts[] = $p;
        }

        $location = !empty($locationParts) ? implode(', ', $locationParts) : ($isRemote ? 'Віддалено' : 'Україна');

        return [
            'title' => $jobTitle,
            'company' => $company,
            'salary' => $salary,
            'location' => $location,
            'is_remote' => $isRemote,
            'is_military' => $isMilitary,
        ];
    }

    /**
     * Check if the vacancy matches PHP / Symfony / Drupal / Magento criteria or military PHP roles.
     */
    public function matchesTechStack(string $title, string $description): bool
    {
        $text = $title . ' ' . strip_tags($description);

        // Required keywords
        $pattern = '/\b(php|symfony|drupal|magento|adobe\s*commerce|laravel|wordpress)\b/i';
        return (bool)preg_match($pattern, $text);
    }

    /**
     * Check if vacancy is from Armed Forces, National Guard, Brigades or Military Tech.
     */
    public function isMilitaryVacancy(string $text): bool
    {
        $pattern = '/\b(нгу|зсу|бригада|хартія|азов|військовослужбовець|військов\w+|оборон\w+|defensetech|defense|army|military|сили\s+оборони|рекрутинг\s+до\s+лав)\b/iu';
        return (bool)preg_match($pattern, $text);
    }

    /**
     * Determine work format (Remote, Hybrid, Military, Office).
     *
     * @return string|null null if non-military and strictly on-site
     */
    public function determineWorkType(string $title, string $description, bool $isMilitary = false): ?string
    {
        $text = $title . ' ' . $description;

        if ($isMilitary) {
            if (preg_match('/віддалено|remote/iu', $text)) {
                return 'Remote / Служба';
            }
            if (preg_match('/гібрид|hybrid/iu', $text)) {
                return 'Hybrid / Служба';
            }
            return 'Служба / База підрозділу';
        }

        if (preg_match('/віддалено|remote|дистанційно/iu', $text)) {
            return 'Remote';
        }

        if (preg_match('/гібрид|hybrid|гібридний/iu', $text)) {
            return 'Hybrid';
        }

        // If specific cities are mentioned (e.g. Київ, Дніпро, Варшава), treat as Hybrid/Office in Europe/Ukraine
        if (preg_match('/(?:Київ|Львів|Дніпро|Одеса|Харків|Варшава|Краків|Лісабон|Берлін|Луцьк|Вінниця|Івано-Франківськ|Тернопіль)/iu', $title)) {
            return 'Hybrid / Офіс';
        }

        return 'Remote';
    }

    /**
     * Extract recognizable tech and domain tags.
     *
     * @return array<string>
     */
    public function extractTags(string $title, string $description): array
    {
        $text = $title . ' ' . strip_tags($description);
        $tags = [];

        $keywords = [
            'PHP' => '/\bphp\b/i',
            'Symfony' => '/\bsymfony\b/i',
            'Drupal' => '/\bdrupal\b/i',
            'Magento' => '/\b(magento|adobe commerce)\b/i',
            'Laravel' => '/\blaravel\b/i',
            'WordPress' => '/\bwordpress\b/i',
            'PostgreSQL' => '/\b(postgresql|postgres)\b/i',
            'MySQL' => '/\bmysql\b/i',
            'Docker' => '/\bdocker\b/i',
            'Redis' => '/\bredis\b/i',
            'RabbitMQ' => '/\brabbitmq\b/i',
            'REST API' => '/\b(rest api|restful)\b/i',
            'GraphQL' => '/\bgraphql\b/i',
            'Vue.js' => '/\b(vue|vuejs)\b/i',
            'React' => '/\b(react|reactjs)\b/i',
            'Military Tech' => '/\b(нгу|зсу|бригада|хартія|азов|військов\w+|defensetech)\b/iu',
        ];

        foreach ($keywords as $tag => $regex) {
            if (preg_match($regex, $text)) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Clean and truncate HTML description to a text snippet.
     */
    public function cleanDescriptionSnippet(string $html, int $maxLength = 800): string
    {
        // Remove apply buttons and links markup
        $html = preg_replace('/<div[^>]*>\s*<a[^>]*>Відгукнутись[^<]*<\/a>\s*<\/div>/iu', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength) . '...';
        }

        return $text;
    }
}
