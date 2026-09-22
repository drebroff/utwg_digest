<?php

declare(strict_types=1);

namespace App\Telegram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class TelegramPublisher
{
    private const TELEGRAM_API_BASE = 'https://api.telegram.org/bot%s/%s';
    private const MAX_MESSAGE_LENGTH = 4096;
    private const MAX_MEDIA_GROUP_SIZE = 10;

    private Client $httpClient;
    private string $botToken;
    private string $chatId;
    private bool $disableNotification;

    public function __construct(
        string $botToken,
        string $chatId,
        bool $disableNotification = false,
        ?Client $client = null
    ) {
        if (empty($botToken)) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is required.');
        }
        if (empty($chatId)) {
            throw new RuntimeException('TELEGRAM_CHAT_ID is required.');
        }

        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->disableNotification = $disableNotification;
        $this->httpClient = $client ?? new Client(['timeout' => 30.0]);
    }

    /**
     * Send text digest message to the Telegram channel.
     * Automatically splits if message unexpectedly exceeds Telegram's 4096 limit.
     *
     * @param string $text
     * @return array Returned message data from Telegram
     * @throws RuntimeException
     */
    public function sendDigest(string $text): array
    {
        $chunks = $this->splitMessage($text);
        $results = [];

        foreach ($chunks as $chunk) {
            $results[] = $this->sendSingleMessage($chunk);
            if (count($chunks) > 1) {
                usleep(500000); // 0.5s pause between split chunks
            }
        }

        return $results;
    }

    private function sendSingleMessage(string $text): array
    {
        $url = sprintf(self::TELEGRAM_API_BASE, $this->botToken, 'sendMessage');

        try {
            // First attempt with Markdown
            $response = $this->httpClient->post($url, [
                'json' => [
                    'chat_id' => $this->chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'disable_notification' => $this->disableNotification,
                    'disable_web_page_preview' => true,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            // If Markdown parsing failed, retry as plain text to ensure message is delivered
            try {
                $response = $this->httpClient->post($url, [
                    'json' => [
                        'chat_id' => $this->chatId,
                        'text' => $text,
                        'disable_notification' => $this->disableNotification,
                        'disable_web_page_preview' => true,
                    ],
                ]);
                return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Exception $fallbackError) {
                throw new RuntimeException('Failed to send Telegram message: ' . $fallbackError->getMessage(), 0, $fallbackError);
            }
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to decode Telegram response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Post a list of media items (photos and videos) in albums of up to 10 items each.
     *
     * @param array<int, array{url: string, is_video?: bool, ext?: string}> $mediaItems
     * @return int Number of successfully sent albums / media items
     */
    public function sendMediaAlbums(array $mediaItems): int
    {
        if (empty($mediaItems)) {
            return 0;
        }

        // Filter out items that Telegram photo/video endpoints can accept
        $validMedia = array_values(array_filter($mediaItems, function ($item) {
            $ext = strtolower($item['ext'] ?? pathinfo($item['url'], PATHINFO_EXTENSION));
            // Telegram sendMediaGroup photo supports jpg, png; video supports mp4
            // Webm is often not supported in standard album photo/video without conversion
            return in_array($ext, ['.jpg', '.jpeg', '.png', '.mp4', 'jpg', 'jpeg', 'png', 'mp4'], true);
        }));

        if (empty($validMedia)) {
            return 0;
        }

        $batches = array_chunk($validMedia, self::MAX_MEDIA_GROUP_SIZE);
        $sentCount = 0;

        foreach ($batches as $index => $batch) {
            // Telegram sendMediaGroup requires between 2 and 10 items
            if (count($batch) === 1) {
                $this->sendSinglePhoto($batch[0]['url']);
                $sentCount++;
            } else {
                $mediaGroup = [];
                foreach ($batch as $item) {
                    $isVideo = !empty($item['is_video']) || in_array(strtolower($item['ext'] ?? ''), ['.mp4', 'mp4'], true);
                    $mediaGroup[] = [
                        'type' => $isVideo ? 'video' : 'photo',
                        'media' => $item['url'],
                    ];
                }

                $this->sendMediaGroup($mediaGroup);
                $sentCount += count($batch);
            }

            // Be polite to Telegram flood limits between albums
            if (isset($batches[$index + 1])) {
                sleep(2);
            }
        }

        return $sentCount;
    }

    private function sendSinglePhoto(string $photoUrl): void
    {
        $url = sprintf(self::TELEGRAM_API_BASE, $this->botToken, 'sendPhoto');
        $this->httpClient->post($url, [
            'json' => [
                'chat_id' => $this->chatId,
                'photo' => $photoUrl,
                'disable_notification' => true,
            ],
        ]);
    }

    private function sendMediaGroup(array $mediaGroup): void
    {
        $url = sprintf(self::TELEGRAM_API_BASE, $this->botToken, 'sendMediaGroup');
        $this->httpClient->post($url, [
            'json' => [
                'chat_id' => $this->chatId,
                'media' => $mediaGroup,
                'disable_notification' => true,
            ],
        ]);
    }

    private function splitMessage(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_MESSAGE_LENGTH) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > self::MAX_MESSAGE_LENGTH) {
            $splitPos = mb_strrpos(mb_substr($remaining, 0, self::MAX_MESSAGE_LENGTH), "\n");
            if ($splitPos === false || $splitPos < self::MAX_MESSAGE_LENGTH / 2) {
                $splitPos = self::MAX_MESSAGE_LENGTH;
            }

            $chunks[] = mb_substr($remaining, 0, $splitPos);
            $remaining = ltrim(mb_substr($remaining, $splitPos));
        }

        if (!empty($remaining)) {
            $chunks[] = $remaining;
        }

        return $chunks;
    }
}
