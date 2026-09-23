<?php

declare(strict_types=1);

namespace App\Notifier;

use App\Config;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

class EmailAlert
{
    private ?Mailer $mailer = null;
    private string $toEmail;
    private string $fromEmail;
    private bool $isEnabled = false;

    public function __construct(?Config $config = null)
    {
        $config = $config ?? Config::getInstance();
        $dsn = (string)$config->get('smtp_dsn', '');
        $this->toEmail = (string)$config->get('alert_email_to', '');
        $this->fromEmail = (string)$config->get('alert_email_from', 'noreply@utwg-digest.local');

        if (!empty($dsn) && !empty($this->toEmail)) {
            try {
                $transport = Transport::fromDsn($dsn);
                $this->mailer = new Mailer($transport);
                $this->isEnabled = true;
            } catch (Throwable $e) {
                error_log("EmailAlert: Failed to initialize SMTP transport: " . $e->getMessage());
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled;
    }

    /**
     * Send alert about missing threads in catalog.
     */
    public function sendThreadNotFoundAlert(array $missingTags, string $catalogUrl): void
    {
        $tagsList = implode(', ', $missingTags);
        $subject = "🚨 [4chan Digest Alert] Тред не найден: {$tagsList}";
        
        $body = "Здравствуйте!\n\n"
            . "Бот дайджестов 4chan не смог обнаружить следующие целевые треды в каталоге /g/:\n"
            . "- " . implode("\n- ", $missingTags) . "\n\n"
            . "URL каталога: {$catalogUrl}\n"
            . "Время проверки: " . date('Y-m-d H:i:s T') . "\n\n"
            . "Возможно, тред умер от бамплимита и еще не был перекатан, либо изменен формат заголовка.\n";

        $this->send($subject, $body);
    }

    /**
     * Send alert about 0 posts for target day.
     */
    public function sendNoPostsAlert(string $threadTag, int $threadNo, string $targetDate): void
    {
        $subject = "⚠️ [4chan Digest Alert] 0 новых постов в {$threadTag} за {$targetDate}";

        $body = "Здравствуйте!\n\n"
            . "В треде {$threadTag} (#{$threadNo}) не обнаружено ни одного нового поста за дату: {$targetDate}.\n\n"
            . "Ссылка на тред: https://boards.4chan.org/g/thread/{$threadNo}\n"
            . "Время проверки: " . date('Y-m-d H:i:s T') . "\n\n"
            . "Проверьте активность в треде или корректность часового пояса в настройках.\n";

        $this->send($subject, $body);
    }

    /**
     * Send alert about an unhandled execution error.
     */
    public function sendExecutionErrorAlert(Throwable $e, string $serviceName = '4chan Digest'): void
    {
        $subject = "💥 [{$serviceName} Alert] Критическая ошибка выполнения скрипта";

        $body = "Здравствуйте!\n\n"
            . "Во время работы сервиса \"{$serviceName}\" произошла критическая ошибка:\n\n"
            . "Исключение: " . get_class($e) . "\n"
            . "Сообщение: " . $e->getMessage() . "\n"
            . "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n\n"
            . "Стек вызовов:\n" . $e->getTraceAsString() . "\n";

        $this->send($subject, $body);
    }

    private function send(string $subject, string $textBody): void
    {
        if (!$this->isEnabled || $this->mailer === null) {
            echo "[EmailAlert] SMTP не настроен. Алерт в консоль: {$subject}\n{$textBody}\n";
            return;
        }

        try {
            $email = (new Email())
                ->from($this->fromEmail)
                ->to($this->toEmail)
                ->subject($subject)
                ->text($textBody);

            $this->mailer->send($email);
            echo "[EmailAlert] Алерт успешно отправлен на {$this->toEmail}: {$subject}\n";
        } catch (Throwable $e) {
            error_log("[EmailAlert] Ошибка отправки email: " . $e->getMessage());
        }
    }
}
