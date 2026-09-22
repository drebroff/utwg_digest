<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\PromptBuilder;
use App\Chan\CatalogParser;
use App\Chan\ThreadParser;
use App\Telegram\TelegramPublisher;

echo "🧪 Запуск тестов компонентов...\n\n";

// Test 1: ThreadParser comment cleaning and quote extraction
echo "1. Тест очистки комментариев и цитат в ThreadParser... ";
$tp = new ThreadParser(null, 'UTC');
$rawHtml = "&gt;&gt;109848719<br>Hello anon!<br/>&gt;greentext line<br />Another line &#039;test&#039; &amp; &quot;quote&quot;";
$cleaned = $tp->cleanComment($rawHtml);
$quotes = $tp->extractQuotes($rawHtml);

assert($quotes === [109848719], "Quotes must contain 109848719");
assert(str_contains($cleaned, ">>109848719"), "Cleaned comment must have >>109848719");
assert(str_contains($cleaned, "Hello anon!"), "Cleaned comment must have text");
assert(str_contains($cleaned, ">greentext line"), "Cleaned comment must have greentext");
assert(str_contains($cleaned, "'test' & \"quote\""), "HTML entities must be decoded");
echo "OK!\n";

// Test 2: Lookback Context logic
echo "2. Тест подтягивания родительского контекста по цитатам... ";
// Test with a mock or real thread
$dummyThread = [
    'posts' => [
        [
            'no' => 100,
            'time' => 1700000000 - 3600, // 1 hour before yesterday
            'com' => 'I got laid off yesterday.',
            'name' => 'Anon1',
        ],
        [
            'no' => 101,
            'time' => 1700000000 + 100, // yesterday
            'com' => '>>100<br>Same here bro.',
            'name' => 'Anon2',
            'tim' => 1700000000123,
            'ext' => '.png',
        ],
    ]
];

// Verify that PromptBuilder handles context posts
$builder = new PromptBuilder();
$twgMock = [
    'posts' => [
        ['no' => 101, 'time' => 1700000000 + 100, 'datetime' => '2026-09-21 00:15:00', 'quotes' => [100], 'text' => 'Same here bro.']
    ],
    'context_posts' => [
        ['no' => 100, 'time' => 1700000000 - 3600, 'datetime' => '2026-09-20 23:45:00', 'quotes' => [], 'text' => 'I got laid off yesterday.']
    ],
    'images' => [
        ['url' => 'https://i.4cdn.org/g/123.png', 'ext' => '.png']
    ]
];
$utwgMock = [
    'posts' => [
        ['no' => 201, 'time' => 1700000000 + 500, 'datetime' => '2026-09-21 01:00:00', 'quotes' => [], 'text' => 'Still no job. Sent 500 applications.']
    ],
    'context_posts' => [],
    'images' => []
];

$prompts = $builder->buildPrompts('2026-09-21', $twgMock, $utwgMock);
assert(str_contains($prompts['user'], 'Контекст #100 в 2026-09-20 23:45:00'), "User prompt must contain lookback parent context");
assert(str_contains($prompts['user'], 'Same here bro.'), "User prompt must contain yesterday post");
assert(str_contains($prompts['user'], 'Still no job.'), "User prompt must contain utwg post");
assert(str_contains($prompts['system'], 'сатирический и язвительный летописец'), "System prompt must have imageboard persona");
echo "OK!\n";

// Test 3: Telegram Publisher splitting logic
echo "3. Тест разбивки сообщений Telegram при превышении лимита 4096 символов... ";
$reflector = new ReflectionClass(TelegramPublisher::class);
$splitMethod = $reflector->getMethod('splitMessage');
$splitMethod->setAccessible(true);
$publisher = new TelegramPublisher('dummy_token', '@dummy_chat');

$longText = str_repeat("Анон пошел на собеседование в галеру.\n", 150);
$chunks = $splitMethod->invoke($publisher, $longText);

assert(count($chunks) > 1, "Should split into multiple chunks");
foreach ($chunks as $chunk) {
    assert(mb_strlen($chunk) <= 4096, "Each chunk must not exceed 4096 chars");
}
echo "OK!\n";

echo "\n🎉 Все тесты компонентов успешно пройдены!\n";
