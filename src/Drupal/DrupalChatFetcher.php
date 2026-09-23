<?php

declare(strict_types=1);

namespace App\Drupal;

use App\Config;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger as MPLogger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class DrupalChatFetcher
{
    private string $sessionPath;
    private int $apiId;
    private string $apiHash;
    private string $timezone;
    private ?API $mp = null;

    public function __construct(?Config $config = null, ?API $mp = null)
    {
        $cfg = $config ?? Config::getInstance();
        $this->sessionPath = (string)$cfg->get('telegram_madeline_session', 'data/drupal_session.madeline');
        $this->apiId = (int)$cfg->get('telegram_api_id', 0);
        $this->apiHash = (string)$cfg->get('telegram_api_hash', '');
        $this->timezone = (string)$cfg->get('timezone', 'Europe/Moscow');
        $this->mp = $mp;
    }

    /**
     * Check if a MadelineProto session file already exists.
     */
    public function hasSession(): bool
    {
        return file_exists($this->sessionPath);
    }

    /**
     * Get or initialize MadelineProto instance.
     */
    public function getMadelineProto(): API
    {
        if ($this->mp !== null) {
            return $this->mp;
        }

        if ($this->apiId <= 0 || empty($this->apiHash)) {
            throw new RuntimeException(
                "TELEGRAM_API_ID и TELEGRAM_API_HASH обязательны для работы MadelineProto. " .
                "Получите их бесплатно на https://my.telegram.org и укажите в .env"
            );
        }

        $sessionDir = dirname($this->sessionPath);
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        $settings = new Settings();
        
        $appInfo = (new AppInfo())
            ->setApiId($this->apiId)
            ->setApiHash($this->apiHash);
        $settings->setAppInfo($appInfo);

        // Keep console output readable
        $logger = (new Logger())
            ->setLevel(MPLogger::LEVEL_ERROR);
        $settings->setLogger($logger);

        $this->mp = new API($this->sessionPath, $settings);
        return $this->mp;
    }

    /**
     * Interactive terminal login for Telegram MTProto.
     * Prompts for phone number, login code, and optional 2FA password in the console.
     */
    public function loginInteractive(): void
    {
        echo "\n🔑 Интерактивная авторизация Telegram MTProto (MadelineProto)...\n";
        echo "Файл сессии: {$this->sessionPath}\n\n";

        $mp = $this->getMadelineProto();
        $mp->start();

        echo "\n✅ Авторизация успешно выполнена! Сессия сохранена в: {$this->sessionPath}\n";
    }

    /**
     * Fetch all text messages from the specified public chat for a target 24h date.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param string $chatUsername e.g. 'drupal_rus'
     * @param int $maxMessagesLimit Safety limit
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     timestamp: int,
     *     sender_username: ?string,
     *     sender_name: string,
     *     text: string,
     *     reply_to_msg_id: ?int
     * }>
     */
    public function fetchMessagesForDate(string $targetDate, string $chatUsername, int $maxMessagesLimit = 1500): array
    {
        $tz = new DateTimeZone($this->timezone);
        $startDt = new DateTimeImmutable("{$targetDate} 00:00:00", $tz);
        $endDt = new DateTimeImmutable("{$targetDate} 23:59:59", $tz);
        $startTimestamp = $startDt->getTimestamp();
        $endTimestamp = $endDt->getTimestamp();

        $cleanChat = ltrim($chatUsername, '@');

        $mp = $this->getMadelineProto();

        // Ensure session is started
        $mp->start();

        $offsetId = 0;
        $batchSize = 100;
        $collected = [];
        $maxBatches = 30; // Max 3000 messages checked
        $batchCount = 0;

        while ($batchCount < $maxBatches && count($collected) < $maxMessagesLimit) {
            $batchCount++;

            $params = [
                'peer' => $cleanChat,
                'offset_id' => $offsetId,
                'limit' => $batchSize,
            ];

            try {
                $history = $mp->messages->getHistory($params);
            } catch (\Throwable $e) {
                throw new RuntimeException("Ошибка запроса истории сообщений Telegram: " . $e->getMessage(), 0, $e);
            }

            $messages = $history['messages'] ?? [];
            if (empty($messages)) {
                break;
            }

            // Build user map for fast sender resolution
            $userMap = [];
            foreach ($history['users'] ?? [] as $user) {
                $uid = $user['id'] ?? null;
                if ($uid) {
                    $userMap[$uid] = $user;
                }
            }

            $reachedOlderDate = false;

            foreach ($messages as $msg) {
                $offsetId = $msg['id'] ?? $offsetId;

                // Skip service messages
                if (($msg['_'] ?? '') === 'messageService') {
                    continue;
                }

                $msgDate = (int)($msg['date'] ?? 0);
                if ($msgDate <= 0) {
                    continue;
                }

                // If message is newer than target date window, keep searching older
                if ($msgDate > $endTimestamp) {
                    continue;
                }

                // If message is older than target date window, we reached the end of target day
                if ($msgDate < $startTimestamp) {
                    $reachedOlderDate = true;
                    break;
                }

                // Message is within [startTimestamp, endTimestamp]
                $text = trim((string)($msg['message'] ?? ''));
                if (empty($text) || mb_strlen($text) < 2) {
                    continue;
                }

                // Resolve sender details
                $senderId = null;
                if (isset($msg['from_id']['user_id'])) {
                    $senderId = $msg['from_id']['user_id'];
                } elseif (isset($msg['from_id']) && is_numeric($msg['from_id'])) {
                    $senderId = (int)$msg['from_id'];
                }

                $senderUsername = null;
                $senderName = 'Участник';

                if ($senderId && isset($userMap[$senderId])) {
                    $u = $userMap[$senderId];
                    if (!empty($u['username'])) {
                        $senderUsername = '@' . $u['username'];
                    }
                    $first = $u['first_name'] ?? '';
                    $last = $u['last_name'] ?? '';
                    $fullName = trim("{$first} {$last}");
                    if (!empty($fullName)) {
                        $senderName = $fullName;
                    }
                }

                $replyToId = null;
                if (isset($msg['reply_to']['reply_to_msg_id'])) {
                    $replyToId = (int)$msg['reply_to']['reply_to_msg_id'];
                }

                $formattedDate = (new DateTimeImmutable("@{$msgDate}"))
                    ->setTimezone($tz)
                    ->format('H:i:s');

                $collected[] = [
                    'id' => (int)$msg['id'],
                    'date' => $formattedDate,
                    'timestamp' => $msgDate,
                    'sender_username' => $senderUsername,
                    'sender_name' => $senderName,
                    'text' => $text,
                    'reply_to_msg_id' => $replyToId,
                ];

                if (count($collected) >= $maxMessagesLimit) {
                    break;
                }
            }

            if ($reachedOlderDate) {
                break;
            }
        }

        // Sort chronologically (oldest first)
        usort($collected, function ($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });

        return $collected;
    }
}
