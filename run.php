<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Ai\AiFactory;
use App\Ai\PromptBuilder;
use App\Chan\CatalogParser;
use App\Chan\ThreadParser;
use App\Config;
use App\Notifier\EmailAlert;
use App\Telegram\TelegramPublisher;

$options = getopt('', ['date:', 'dry-run', 'skip-ai', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
4chan /twg/ & /utwg/ Daily Digest Bot

Использование:
  php run.php [опции]

Опции:
  --date=YYYY-MM-DD  Указать дату для анализа (по умолчанию: вчера)
  --dry-run          Тестовый запуск: без отправки сообщений и картинок в Telegram
  --skip-ai          Пропустить вызов ИИ (для проверки парсинга и картинок)
  --help             Показать эту справку

HELP;
    exit(0);
}

$isDryRun = isset($options['dry-run']);
$skipAi = isset($options['skip-ai']);

$config = Config::getInstance();
$alert = new EmailAlert($config);

$targetDate = $options['date'] ?? (new DateTimeImmutable('yesterday', new DateTimeZone((string)$config->get('timezone'))))->format('Y-m-d');

echo "====================================================\n";
echo "🚀 4chan /twg/ & /utwg/ Daily Digest Runner\n";
echo "📅 Целевая дата: {$targetDate}\n";
echo "🌍 Часовой пояс: " . $config->get('timezone') . "\n";
echo "🧠 AI Провайдер: " . $config->get('ai_provider') . "\n";
echo "🤖 Режим публикации: " . ($isDryRun ? "DRY RUN (без отправки в TG)" : "PRODUCTION") . "\n";
echo "====================================================\n\n";

try {
    // 1. Поиск тредов в каталоге 4chan /g/
    echo "🔍 Сканирование каталога 4chan /g/ на наличие /twg/ и /utwg/...\n";
    $catalogParser = new CatalogParser();
    $found = $catalogParser->findTargetThreads();

    $missing = [];
    if (empty($found['twg'])) {
        $missing[] = '/twg/ (Tech Workers General)';
    }
    if (empty($found['utwg'])) {
        $missing[] = '/utwg/ (Unemployable Tech Workers General)';
    }

    if (!empty($missing)) {
        echo "⚠️ Внимание: следующие треды не найдены: " . implode(', ', $missing) . "\n";
        $alert->sendThreadNotFoundAlert($missing, 'https://boards.4chan.org/g/catalog');
    }

    if (empty($found['twg']) && empty($found['utwg'])) {
        echo "❌ Ни один целевой тред не найден в каталоге. Завершение работы.\n";
        exit(1);
    }

    // 2. Парсинг постов и картинок за вчерашний день
    $threadParser = new ThreadParser(null, (string)$config->get('timezone'));

    $twgData = ['posts' => [], 'context_posts' => [], 'images' => [], 'thread_no' => 0];
    if (!empty($found['twg'])) {
        $twgThread = $found['twg'][0];
        echo "📥 Парсинг /twg/ (тред #{$twgThread['no']}) за {$targetDate}...\n";
        $twgData = $threadParser->parseThreadForDate($twgThread['no'], $targetDate);
        echo "   -> Найдено постов за вчера: " . count($twgData['posts']) . "\n";
        echo "   -> Подтянуто контекстных родительских постов: " . count($twgData['context_posts']) . "\n";
        echo "   -> Найдено картинок за вчера: " . count($twgData['images']) . "\n";

        if (empty($twgData['posts'])) {
            $alert->sendNoPostsAlert('/twg/', $twgThread['no'], $targetDate);
        }
    }

    $utwgData = ['posts' => [], 'context_posts' => [], 'images' => [], 'thread_no' => 0];
    if (!empty($found['utwg'])) {
        $utwgThread = $found['utwg'][0];
        echo "📥 Парсинг /utwg/ (тред #{$utwgThread['no']}) за {$targetDate}...\n";
        $utwgData = $threadParser->parseThreadForDate($utwgThread['no'], $targetDate);
        echo "   -> Найдено постов за вчера: " . count($utwgData['posts']) . "\n";
        echo "   -> Подтянуто контекстных родительских постов: " . count($utwgData['context_posts']) . "\n";
        echo "   -> Найдено картинок за вчера: " . count($utwgData['images']) . "\n";

        if (empty($utwgData['posts'])) {
            $alert->sendNoPostsAlert('/utwg/', $utwgThread['no'], $targetDate);
        }
    }

    $totalPosts = count($twgData['posts']) + count($utwgData['posts']);
    if ($totalPosts === 0) {
        echo "⚠️ Суммарно 0 постов за вчерашний день во всех тредах. Выжимку делать не из чего.\n";
        exit(0);
    }

    // 3. Объединение изображений за вчерашний день
    $allImages = array_merge($twgData['images'], $utwgData['images']);
    echo "\n🖼 Всего картинок за вчерашний день по обоим тредам: " . count($allImages) . "\n";

    // 4. Генерация дайджеста через ИИ
    $digestText = '';
    if (!$skipAi) {
        echo "\n🧠 Генерация дайджеста на сленге имиджборд через AI (" . $config->get('ai_provider') . ")...\n";
        
        $promptBuilder = new PromptBuilder();
        $prompts = $promptBuilder->buildPrompts($targetDate, $twgData, $utwgData);

        $aiClient = AiFactory::create($config);
        $digestText = $aiClient->generateDigest($prompts['system'], $prompts['user']);
        echo "✅ Дайджест успешно сгенерирован (" . mb_strlen($digestText) . " символов).\n";

        echo "\n------------------- [ИТОГОВЫЙ ДАЙДЖЕСТ] -------------------\n";
        echo $digestText . "\n";
        echo "-----------------------------------------------------------\n\n";
    } else {
        $digestText = "🗓 **Дайджест /twg/ & /utwg/ за {$targetDate}**\n\n(AI генерация пропущена через флаг --skip-ai)";
    }

    // 5. Публикация в Telegram
    if ($isDryRun) {
        echo "ℹ️ DRY RUN активен. Отправка в Telegram пропущена.\n";
        echo "Будет отправлено постов: 1 текст + " . ceil(count($allImages) / 10) . " медиагрупп(ы).\n";
    } else {
        echo "📤 Публикация дайджеста в Telegram-канал...\n";
        $botToken = (string)$config->get('telegram_bot_token');
        $chatId = (string)$config->get('telegram_chat_id');
        $disableNotif = (bool)$config->get('telegram_disable_notification');

        $publisher = new TelegramPublisher($botToken, $chatId, $disableNotif);
        $publisher->sendDigest($digestText);
        echo "✅ Текстовый дайджест опубликован!\n";

        if (!empty($allImages)) {
            echo "📤 Отправка " . count($allImages) . " картинок альбомами...\n";
            $sentImages = $publisher->sendMediaAlbums($allImages);
            echo "✅ Отправлено картинок: {$sentImages}!\n";
        }
    }

    echo "\n🎉 Успешно завершено!\n";
} catch (Throwable $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $alert->sendExecutionErrorAlert($e);
    exit(1);
}
