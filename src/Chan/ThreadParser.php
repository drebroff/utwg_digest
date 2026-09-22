<?php

declare(strict_types=1);

namespace App\Chan;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ThreadParser
{
    private const THREAD_URL_TEMPLATE = 'https://a.4cdn.org/g/thread/%d.json';
    private const MEDIA_URL_TEMPLATE = 'https://i.4cdn.org/g/%s%s';

    private Client $httpClient;
    private DateTimeZone $timezone;

    public function __construct(?Client $client = null, ?string $timezone = null)
    {
        $this->httpClient = $client ?? new Client([
            'timeout' => 20.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
            ],
        ]);

        $this->timezone = new DateTimeZone($timezone ?? date_default_timezone_get());
    }

    /**
     * Fetch a thread by ID and extract yesterday's posts with contextual parents and images.
     *
     * @param int $threadNo
     * @param string|null $targetDate YYYY-MM-DD date string. If null, calculates 'yesterday'.
     * @return array{
     *   thread_no: int,
     *   target_date: string,
     *   posts: array<int, array>,
     *   context_posts: array<int, array>,
     *   images: array<int, array>,
     *   total_posts_yesterday: int
     * }
     * @throws GuzzleException|\JsonException
     */
    public function parseThreadForDate(int $threadNo, ?string $targetDate = null): array
    {
        $targetDate = $targetDate ?? (new DateTimeImmutable('yesterday', $this->timezone))->format('Y-m-d');
        
        $startOfDay = (new DateTimeImmutable($targetDate . ' 00:00:00', $this->timezone))->getTimestamp();
        $endOfDay = (new DateTimeImmutable($targetDate . ' 23:59:59', $this->timezone))->getTimestamp();

        $url = sprintf(self::THREAD_URL_TEMPLATE, $threadNo);
        $response = $this->httpClient->get($url);
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        $rawPosts = $data['posts'] ?? [];

        // Index all thread posts by their post number
        $allPostsMap = [];
        foreach ($rawPosts as $post) {
            $allPostsMap[(int)$post['no']] = $post;
        }

        $targetPosts = [];
        $contextPosts = [];
        $images = [];

        foreach ($rawPosts as $rawPost) {
            $postTime = (int)($rawPost['time'] ?? 0);
            $postNo = (int)$rawPost['no'];

            if ($postTime >= $startOfDay && $postTime <= $endOfDay) {
                $cleanedCom = $this->cleanComment((string)($rawPost['com'] ?? ''));
                $quotes = $this->extractQuotes((string)($rawPost['com'] ?? ''));

                $postEntry = [
                    'no' => $postNo,
                    'time' => $postTime,
                    'datetime' => (new DateTimeImmutable("@$postTime"))->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
                    'name' => (string)($rawPost['name'] ?? 'Anonymous'),
                    'text' => $cleanedCom,
                    'quotes' => $quotes,
                    'has_image' => !empty($rawPost['tim']),
                ];

                // If post has an image attached, save image metadata
                if (!empty($rawPost['tim']) && !empty($rawPost['ext'])) {
                    $mediaUrl = sprintf(self::MEDIA_URL_TEMPLATE, $rawPost['tim'], $rawPost['ext']);
                    $images[] = [
                        'post_no' => $postNo,
                        'tim' => (string)$rawPost['tim'],
                        'ext' => (string)$rawPost['ext'],
                        'filename' => (string)($rawPost['filename'] ?? ''),
                        'url' => $mediaUrl,
                        'is_video' => in_array(strtolower((string)$rawPost['ext']), ['.webm', '.mp4'], true),
                    ];
                }

                // Check quotes for parent context lookback
                foreach ($quotes as $quotedNo) {
                    if (isset($allPostsMap[$quotedNo])) {
                        $parentPost = $allPostsMap[$quotedNo];
                        $parentTime = (int)($parentPost['time'] ?? 0);

                        // If parent post was posted BEFORE the target day, include it as lookback context
                        if ($parentTime < $startOfDay && !isset($contextPosts[$quotedNo])) {
                            $contextPosts[$quotedNo] = [
                                'no' => $quotedNo,
                                'time' => $parentTime,
                                'datetime' => (new DateTimeImmutable("@$parentTime"))->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
                                'name' => (string)($parentPost['name'] ?? 'Anonymous'),
                                'text' => $this->cleanComment((string)($parentPost['com'] ?? '')),
                                'quotes' => $this->extractQuotes((string)($parentPost['com'] ?? '')),
                                'is_parent_context' => true,
                            ];
                        }
                    }
                }

                $targetPosts[] = $postEntry;
            }
        }

        return [
            'thread_no' => $threadNo,
            'target_date' => $targetDate,
            'posts' => $targetPosts,
            'context_posts' => array_values($contextPosts),
            'images' => $images,
            'total_posts_yesterday' => count($targetPosts),
        ];
    }

    public function cleanComment(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Convert line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Strip HTML tags
        $text = strip_tags($text ?? '');

        // Decode HTML entities (e.g. &gt;, &#039;, &quot;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace and trim
        return trim($text);
    }

    public function extractQuotes(string $html): array
    {
        if (empty($html)) {
            return [];
        }

        // 4chan quotes look like >>12345678 or class="quotelink">&gt;&gt;12345678</a>
        preg_match_all('/(?:&gt;&gt;|>>)(\d+)/', $html, $matches);

        if (empty($matches[1])) {
            return [];
        }

        return array_map('intval', array_unique($matches[1]));
    }
}
