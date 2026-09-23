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
     * Post a list of media items in albums of up to 10 items each.
     * Photos (jpg, png) are grouped into albums.
     * Videos (mp4) are sent separately to avoid Telegram API 400 errors with mixed remote URLs.
     *
     * @param array<int, array{url: string, is_video?: bool, ext?: string}> $mediaItems
     * @return int Number of successfully sent items
     */
    public function sendMediaAlbums(array $mediaItems): int
    {
        if (empty($mediaItems)) {
            return 0;
        }

        // Separate photos and videos
        // Telegram sendMediaGroup reliably accepts photos (jpg, jpeg, png).
        // Mixing video URLs with photo URLs in sendMediaGroup frequently triggers 400 Bad Request.
        $photos = [];
        $videos = [];

        foreach ($mediaItems as $item) {
            $ext = strtolower($item['ext'] ?? pathinfo($item['url'], PATHINFO_EXTENSION));
            $cleanExt = ltrim($ext, '.');
            if (in_array($cleanExt, ['jpg', 'jpeg', 'png'], true)) {
                $photos[] = $item;
            } elseif ($cleanExt === 'mp4') {
                $videos[] = $item;
            }
        }

        $sentCount = 0;

        // 1. Send photos in albums of up to 10
        if (!empty($photos)) {
            $batches = array_chunk($photos, self::MAX_MEDIA_GROUP_SIZE);

            foreach ($batches as $index => $batch) {
                if (count($batch) === 1) {
                    try {
                        $this->sendSinglePhoto($batch[0]['url']);
                        $sentCount++;
                    } catch (\Exception $e) {
                        echo "⚠️ Ошибка отправки фото {$batch[0]['url']}: " . $e->getMessage() . "\n";
                    }
                } else {
                    $mediaGroup = [];
                    foreach ($batch as $item) {
                        $mediaGroup[] = [
                            'type' => 'photo',
                            'media' => $item['url'],
                        ];
                    }

                    try {
                        $this->sendMediaGroup($mediaGroup);
                        $sentCount += count($batch);
                    } catch (\Exception $e) {
                        echo "⚠️ Ошибка отправки альбома (" . count($batch) . " фото): " . $e->getMessage() . "\n";
                        echo "ℹ️ Пробуем отправить фото из этого альбома по отдельности...\n";

                        foreach ($batch as $item) {
                            try {
                                $this->sendSinglePhoto($item['url']);
                                $sentCount++;
                                usleep(300000);
                            } catch (\Exception $singleErr) {
                                echo "⚠️ Не удалось отправить фото {$item['url']}: " . $singleErr->getMessage() . "\n";
                            }
                        }
                    }
                }

                if (isset($batches[$index + 1])) {
                    sleep(2);
                }
            }
        }

        // 2. Send videos individually (if any mp4)
        foreach ($videos as $videoItem) {
            try {
                $this->sendSingleVideo($videoItem['url']);
                $sentCount++;
                sleep(2);
            } catch (\Exception $e) {
                echo "⚠️ Ошибка отправки видео {$videoItem['url']}: " . $e->getMessage() . "\n";
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

    private function sendSingleVideo(string $videoUrl): void
    {
        $url = sprintf(self::TELEGRAM_API_BASE, $this->botToken, 'sendVideo');
        $this->httpClient->post($url, [
            'json' => [
                'chat_id' => $this->chatId,
                'video' => $videoUrl,
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
