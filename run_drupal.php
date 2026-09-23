<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Ai\AiFactory;
use App\Config;
use App\Drupal\DrupalChatFetcher;
use App\Drupal\DrupalJobMarketAnalyzer;
use App\Notifier\EmailAlert;
use App\Telegram\TelegramPublisher;

$options = getopt('', ['date:', 'dry-run', 'skip-ai', 'auth', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Drupal RU (@drupal_rus) Job Market Daily Monitor

Использование:
  php run_drupal.php [опции]

Опции:
  --auth             Интерактивная разовая авторизация в Telegram MTProto (ввод телефона и кода)
  --date=YYYY-MM-DD  Указать дату для анализа (по умолчанию: вчера)
  --dry-run          Тестовый запуск: без отправки сообщения в Telegram-канал
  --skip-ai          Пропустить вызов ИИ (только проверка загрузки сообщений из чата)
  --help             Показать эту справку

Примеры:
  php run_drupal.php --auth
  php run_drupal.php --dry-run
  php run_drupal.php --date=2026-09-22 --dry-run

HELP;
    exit(0);
}

$config = Config::getInstance();
$alert = new EmailAlert($config);

// Режим интерактивной авторизации MTProto
if (isset($options['auth'])) {
    try {
        $fetcher = new DrupalChatFetcher($config);
        $fetcher->loginInteractive();
        exit(0);
    } catch (Throwable $e) {
        echo "\n❌ Ошибка авторизации: " . $e->getMessage() . "\n";
        exit(1);
    }
}

$isDryRun = isset($options['dry-run']);
$skipAi = isset($options['skip-ai']);

$timezone = (string)$config->get('timezone', 'Europe/Moscow');
$targetDate = $options['date'] ?? (new DateTimeImmutable('yesterday', new DateTimeZone($timezone)))->format('Y-m-d');
$chatUsername = (string)$config->get('telegram_drupal_chat', 'drupal_rus');
$enabled = (bool)$config->get('enable_drupal_bonus', true);

echo "====================================================\n";
echo "🚀 Drupal RU (@{$chatUsername}) Job Market Monitor\n";
echo "📅 Целевая дата: {$targetDate}\n";
echo "🌍 Часовой пояс: {$timezone}\n";
echo "🧠 AI Провайдер: " . $config->get('ai_provider') . "\n";
echo "🤖 Режим публикации: " . ($isDryRun ? "DRY RUN (без отправки в TG)" : "PRODUCTION") . "\n";
echo "====================================================\n\n";

if (!$enabled) {
    echo "ℹ️ Мониторинг Drupal отключен настройкой ENABLE_DRUPAL_BONUS=false в .env. Завершение.\n";
    exit(0);
}

try {
    $fetcher = new DrupalChatFetcher($config);

    if (!$fetcher->hasSession()) {
        echo "⚠️ ВНИМАНИЕ: Файл сессии Telegram MTProto не найден!\n";
        echo "Для первого запуска необходимо выполнить разовую интерактивную авторизацию:\n";
        echo "  php run_drupal.php --auth\n\n";
        exit(1);
    }

    // 1. Загрузка сообщений из чата за сутки
    echo "📥 Загрузка сообщений из @{$chatUsername} за {$targetDate}...\n";
    $messages = $fetcher->fetchMessagesForDate($targetDate, $chatUsername);
    echo "   -> Найдено текстовых сообщений за день: " . count($messages) . "\n";

    if (empty($messages)) {
        echo "ℹ️ Сообщений в чате @{$chatUsername} за {$targetDate} не обнаружено. Завершение работы.\n";
        exit(0);
    }

    if ($skipAi) {
        echo "\nℹ️ Анализ ИИ пропущен флагом --skip-ai.\n";
        echo "Пример первых 3 сообщений:\n";
        foreach (array_slice($messages, 0, 3) as $m) {
            echo "   [{$m['date']}] {$m['sender_name']}: " . mb_substr($m['text'], 0, 80) . "...\n";
        }
        exit(0);
    }

    // 2. Анализ сообщений через ИИ (Grok / AI)
    echo "\n🧠 Анализ сообщений через AI (" . $config->get('ai_provider') . ")...\n";
    $aiClient = AiFactory::create($config);
    $analyzer = new DrupalJobMarketAnalyzer($aiClient);

    $analysis = $analyzer->analyze($targetDate, $chatUsername, $messages);

    // 3. Проверка результата
    if (!$analysis['has_job_discussion']) {
        echo "✅ Анализ завершен: тем рынка труда, поиска или предложений работы НЕ ОБНАРУЖЕНО.\n";
        if (!empty($analysis['reason'])) {
            echo "ℹ️ Причина/резюме: {$analysis['reason']}\n";
        }
        echo "🔇 Публикация в канал не требуется.\n";
        exit(0);
    }

    // 4. Если темы обсуждались — готовим публикацию
    echo "🔥 ОБНАРУЖЕНО обсуждение рынка труда / работы в сообществе!\n";
    if (!empty($analysis['topics'])) {
        echo "📌 Темы: " . implode(', ', $analysis['topics']) . "\n";
    }
    if (!empty($analysis['participants'])) {
        echo "👥 Участники: " . implode(', ', $analysis['participants']) . "\n";
    }
    echo "📝 Суть: {$analysis['summary']}\n\n";

    $postText = trim($analysis['post_text']);
    if (empty($postText)) {
        echo "⚠️ Ошибка: ИИ подтвердил наличие темы, но не вернул текст поста (post_text).\n";
        exit(1);
    }

    echo "------------------- [БОНУСНЫЙ ДАЙДЖЕСТ DRUPAL] -------------------\n";
    echo $postText . "\n";
    echo "------------------------------------------------------------------\n\n";

    // 5. Публикация в Telegram-канал
    if ($isDryRun) {
        echo "ℹ️ DRY RUN активен. Отправка в Telegram пропущена.\n";
    } else {
        echo "📤 Публикация бонусного сообщения в Telegram-канал...\n";
        $botToken = (string)$config->get('telegram_bot_token');
        $chatId = (string)$config->get('telegram_chat_id');
        $disableNotif = (bool)$config->get('telegram_disable_notification');

        $publisher = new TelegramPublisher($botToken, $chatId, $disableNotif);
        $publisher->sendDigest($postText);
        echo "✅ Бонус успешно опубликован в Telegram!\n";
    }

    echo "\n🎉 Успешно завершено!\n";
} catch (Throwable $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА DRUPAL MONITOR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $alert->sendExecutionErrorAlert($e);
    exit(1);
}
