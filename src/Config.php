<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

class Config
{
    private static ?self $instance = null;
    private array $data = [];

    private function __construct()
    {
        $rootDir = dirname(__DIR__);
        if (file_exists($rootDir . '/.env')) {
            $dotenv = Dotenv::createImmutable($rootDir);
            $dotenv->safeLoad();
        }

        $this->data = [
            'app_env' => $_ENV['APP_ENV'] ?? 'production',
            'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Moscow',
            'ai_provider' => strtolower($_ENV['AI_PROVIDER'] ?? 'grok'),
            
            // xAI Grok
            'grok_api_key' => $_ENV['GROK_API_KEY'] ?? '',
            'grok_model' => $_ENV['GROK_MODEL'] ?? 'grok-beta',

            // Groq Cloud
            'groq_api_key' => $_ENV['GROQ_API_KEY'] ?? '',
            'groq_model' => $_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-20b',
            
            // Google Gemini
            'gemini_api_key' => $_ENV['GEMINI_API_KEY'] ?? '',
            'gemini_model' => $_ENV['GEMINI_MODEL'] ?? 'gemini-2.0-flash',

            // DeepL Translator
            'deepl_api_key' => $_ENV['DEEPL_API_KEY'] ?? '',
            'deepl_target_lang' => strtoupper($_ENV['DEEPL_TARGET_LANG'] ?? 'RU'),

            // Telegram Bot (Publishing)
            'telegram_bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            'telegram_chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
            'telegram_disable_notification' => filter_var($_ENV['TELEGRAM_DISABLE_NOTIFICATION'] ?? false, FILTER_VALIDATE_BOOLEAN),

            // Telegram MTProto (MadelineProto for reading @drupal_rus)
            'telegram_api_id' => (int)($_ENV['TELEGRAM_API_ID'] ?? 0),
            'telegram_api_hash' => $_ENV['TELEGRAM_API_HASH'] ?? '',
            'telegram_madeline_session' => $_ENV['TELEGRAM_MADELINE_SESSION'] ?? 'data/drupal_session.madeline',
            'telegram_drupal_chat' => $_ENV['TELEGRAM_DRUPAL_CHAT'] ?? 'drupal_rus',
            'enable_drupal_bonus' => filter_var($_ENV['ENABLE_DRUPAL_BONUS'] ?? true, FILTER_VALIDATE_BOOLEAN),

            // Email Alerts (Brevo / SMTP)
            'alert_email_to' => $_ENV['ALERT_EMAIL_TO'] ?? '',
            'alert_email_from' => $_ENV['ALERT_EMAIL_FROM'] ?? 'alerts@utwg-digest.local',
            'smtp_dsn' => $_ENV['SMTP_DSN'] ?? '', // Brevo: smtp://<login>:<key>@smtp-relay.brevo.com:587
        ];

        date_default_timezone_set($this->data['timezone']);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return !empty($this->data[$key]);
    }
}
