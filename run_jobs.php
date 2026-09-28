<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Ai\AiFactory;
use App\Config;
use App\Jobs\ArbeitnowFetcher;
use App\Jobs\JobAnalyzer;
use App\Notifier\EmailAlert;
use App\Telegram\TelegramPublisher;

$options = getopt('', ['date:', 'dry-run', 'skip-ai', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Arbeitnow European PHP / Symfony / Drupal / Magento Jobs Daily Digest

Использование:
  php run_jobs.php [опции]

Опции:
  --date=YYYY-MM-DD  Указать дату публикации вакансий (по умолчанию: вчера)
  --dry-run          Тестовый запуск: без отправки сообщения в Telegram-канал
  --skip-ai          Пропустить вызов ИИ (только проверка загрузки вакансий с Arbeitnow)
  --help             Показать эту справку

Примеры:
  php run_jobs.php --dry-run
  php run_jobs.php --date=2026-09-27 --dry-run
  php run_jobs.php --skip-ai

HELP;
    exit(0);
}

$config = Config::getInstance();
$alert = new EmailAlert($config);

$isDryRun = isset($options['dry-run']);
$skipAi = isset($options['skip-ai']);

$timezone = (string)$config->get('timezone', 'Europe/Vilnius');
$targetDate = $options['date'] ?? (new DateTimeImmutable('yesterday', new DateTimeZone($timezone)))->format('Y-m-d');
$enabled = (bool)$config->get('enable_jobs_digest', true);

echo "====================================================\n";
echo "💼 Arbeitnow European PHP/Symfony/Drupal/Magento Jobs\n";
echo "📅 Целевая дата: {$targetDate}\n";
echo "🌍 Часовой пояс: {$timezone}\n";
echo "🧠 AI Провайдер: " . $config->get('ai_provider') . "\n";
echo "🤖 Режим публикации: " . ($isDryRun ? "DRY RUN (без отправки в TG)" : "PRODUCTION") . "\n";
echo "====================================================\n\n";

if (!$enabled) {
    echo "ℹ️ Мониторинг вакансий отключен настройкой ENABLE_JOBS_DIGEST=false в .env. Завершение.\n";
    exit(0);
}

try {
    $fetcher = new ArbeitnowFetcher();

    // 1. Поиск свежих вакансий за указанную дату
    echo "📥 Поиск свежих вакансий за {$targetDate} на Arbeitnow...\n";
    $candidates = $fetcher->fetchJobsForDate($targetDate, $timezone);
    echo "   -> Найдено потенциальных кандидатов (PHP/Symfony/Drupal/Magento + Remote/Hybrid): " . count($candidates) . "\n";

    if (empty($candidates)) {
        echo "ℹ️ Свежих вакансий PHP/Symfony/Drupal/Magento за {$targetDate} не обнаружено. Завершение работы.\n";
        exit(0);
    }

    if ($skipAi) {
        echo "\nℹ️ Анализ ИИ пропущен флагом --skip-ai.\n";
        echo "Список найденных кандидатов:\n";
        foreach ($candidates as $idx => $c) {
            echo "   [" . ($idx + 1) . "] {$c['title']} @ {$c['company']} ({$c['location']})\n";
            echo "       Формат: {$c['work_type']} | Дата: {$c['date_formatted']}\n";
            echo "       URL: {$c['url']}\n";
            if (!empty($c['tags'])) {
                echo "       Теги: " . implode(', ', $c['tags']) . "\n";
            }
            echo "       Сниппет: " . mb_substr($c['description_snippet'], 0, 150) . "...\n\n";
        }
        exit(0);
    }

    // 2. Анализ и фильтрация через ИИ
    echo "\n🧠 Анализ и отбор вакансий через AI (" . $config->get('ai_provider') . ")...\n";
    $aiClient = AiFactory::create($config);
    $analyzer = new JobAnalyzer($aiClient);

    $analysis = $analyzer->analyze($targetDate, $candidates);

    // 3. Проверка результатов фильтрации
    if (!$analysis['has_valid_jobs'] || empty(trim($analysis['post_text']))) {
        echo "✅ Анализ завершен: ни одна вакансия не прошла строгий фильтр (не подтвержден PHP/не Remote/не English).\n";
        if (!empty($analysis['summary'])) {
            echo "ℹ️ Причина/резюме ИИ: {$analysis['summary']}\n";
        }
        echo "🔇 Публикация в канал не требуется.\n";
        exit(0);
    }

    // 4. Валидные вакансии найдены
    echo "🔥 Найдено релевантных вакансий: {$analysis['valid_count']}!\n";
    if (!empty($analysis['summary'])) {
        echo "📝 Резюме: {$analysis['summary']}\n\n";
    }

    $postText = trim($analysis['post_text']);

    echo "------------------- [ДАЙДЖЕСТ ВАКАНСИЙ ARBEITNOW] -------------------\n";
    echo $postText . "\n";
    echo "--------------------------------------------------------------------\n\n";

    // 5. Публикация в Telegram-канал
    if ($isDryRun) {
        echo "ℹ️ DRY RUN активен. Отправка в Telegram пропущена.\n";
    } else {
        echo "📤 Публикация вакансий в Telegram-канал...\n";
        $botToken = (string)$config->get('telegram_bot_token');
        $chatId = (string)$config->get('telegram_chat_id');
        $disableNotif = (bool)$config->get('telegram_disable_notification');

        $publisher = new TelegramPublisher($botToken, $chatId, $disableNotif);
        $publisher->sendDigest($postText);
        echo "✅ Вакансии успешно опубликованы в Telegram!\n";
    }

    echo "\n🎉 Успешно завершено!\n";
} catch (Throwable $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА JOBS MONITOR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $alert->sendExecutionErrorAlert($e, 'PHP Jobs Monitor');
    exit(1);
}
