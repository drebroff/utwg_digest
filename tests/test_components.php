<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\PromptBuilder;
use App\Chan\CatalogParser;
use App\Chan\ThreadParser;
use App\Telegram\TelegramPublisher;
use App\Translation\DeepLClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

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
assert(str_contains($prompts['user'], 'I got laid off yesterday.'), "User prompt must contain lookback parent context");
assert(str_contains($prompts['user'], 'Same here bro.'), "User prompt must contain yesterday post");
assert(str_contains($prompts['user'], 'Still no job.'), "User prompt must contain utwg post");
assert(str_contains($prompts['system'], 'objective tech analyst'), "System prompt must have objective analyst persona");
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

// Test 4: DeepLClient endpoint auto-detection and translation parsing
echo "4. Тест DeepLClient (автоопределение эндпоинта Free/Pro и парсинг перевода)... ";
$freeClient = new DeepLClient('sample-key-1234:fx');
assert($freeClient->getEndpoint() === 'https://api-free.deepl.com/v2/translate', "Free key ending with :fx must resolve to Free endpoint");

$proClient = new DeepLClient('sample-pro-key-5678');
assert($proClient->getEndpoint() === 'https://api.deepl.com/v2/translate', "Pro key without :fx must resolve to Pro endpoint");

$mock = new MockHandler([
    new Response(200, [], json_encode([
        'translations' => [
            ['detected_source_language' => 'EN', 'text' => 'Доброе утро, погромисты!']
        ]
    ]))
]);
$handlerStack = HandlerStack::create($mock);
$mockGuzzle = new Client(['handler' => $handlerStack]);
$clientWithMock = new DeepLClient('test-key:fx', null, $mockGuzzle);
$translated = $clientWithMock->translate('Good morning, code monkeys!', 'RU', 'EN');
assert($translated === 'Доброе утро, погромисты!', "Translated text must match mock response");
echo "OK!\n";

// Test 5: DrupalPromptBuilder formatting & prompts
echo "5. Тест DrupalPromptBuilder (форматирование сообщений и промпта)... ";
$dpBuilder = new \App\Drupal\DrupalPromptBuilder();
$sampleMessages = [
    [
        'id' => 10,
        'date' => '14:20:00',
        'sender_username' => '@drupal_dev',
        'sender_name' => 'Иван Иванов',
        'text' => 'Ребята, кто сейчас ищет Drupal 10 разработчика на парт-тайм?',
        'reply_to_msg_id' => null,
    ],
    [
        'id' => 11,
        'date' => '14:22:15',
        'sender_username' => null,
        'sender_name' => 'Алексей',
        'text' => 'Мы ищем мидла на Symfony/Drupal, ставка от 2000$.',
        'reply_to_msg_id' => 10,
    ],
];
$prompts = $dpBuilder->buildPrompts('2026-09-22', 'drupal_rus', $sampleMessages);
assert(str_contains($prompts['system'], 'аналитик русскоязычных IT-сообществ'), "System prompt must have analyst persona");
assert(str_contains($prompts['user'], '@drupal_dev'), "User prompt must contain username @drupal_dev");
assert(str_contains($prompts['user'], 'Алексей (ответ на #10)'), "User prompt must contain sender name without username and reply id");
assert(str_contains($prompts['user'], '2000$'), "User prompt must contain message text");
echo "OK!\n";

// Test 6: DrupalJobMarketAnalyzer (парсинг ответа ИИ: позитивный, негативный и в markdown-блоке)
echo "6. Тест DrupalJobMarketAnalyzer (парсинг вердиктов ИИ)... ";

// 6.1 Позитивный сценарий с markdown-оберткой ```json ... ```
$mockAiPositive = new class implements \App\Ai\AiClientInterface {
    public function generateDigest(string $systemPrompt, string $userPrompt): string {
        return "```json\n" . json_encode([
            'has_job_discussion' => true,
            'topics' => ['поиск работы', 'вакансия'],
            'participants' => ['@drupal_dev', 'Алексей'],
            'summary' => 'Обсуждали поиск разработчика на проект со ставкой от 2000$',
            'post_text' => "🔥 **Бонус: сегодня в Drupal-сообществе (@drupal_rus)** искали работу и предлагали вакансии...",
        ]) . "\n```";
    }
};

$analyzerPositive = new \App\Drupal\DrupalJobMarketAnalyzer($mockAiPositive, $dpBuilder);
$resultPositive = $analyzerPositive->analyze('2026-09-22', 'drupal_rus', $sampleMessages);

assert($resultPositive['has_job_discussion'] === true, "Must detect job discussion");
assert($resultPositive['topics'] === ['поиск работы', 'вакансия'], "Topics must match");
assert($resultPositive['participants'] === ['@drupal_dev', 'Алексей'], "Participants must match");
assert(str_contains($resultPositive['post_text'], 'Бонус: сегодня в Drupal-сообществе'), "Post text must be populated");

// 6.2 Негативный сценарий (только технические обсуждения)
$mockAiNegative = new class implements \App\Ai\AiClientInterface {
    public function generateDigest(string $systemPrompt, string $userPrompt): string {
        return json_encode([
            'has_job_discussion' => false,
            'reason' => 'Участники обсуждали исключительно кэширование и views в Drupal 10.',
        ]);
    }
};

$analyzerNegative = new \App\Drupal\DrupalJobMarketAnalyzer($mockAiNegative, $dpBuilder);
$resultNegative = $analyzerNegative->analyze('2026-09-22', 'drupal_rus', $sampleMessages);

assert($resultNegative['has_job_discussion'] === false, "Must detect NO job discussion");
assert(str_contains($resultNegative['reason'], 'кэширование'), "Reason must be preserved");

// 6.3 Пустой список сообщений (без вызова ИИ)
$resultEmpty = $analyzerNegative->analyze('2026-09-22', 'drupal_rus', []);
assert($resultEmpty['has_job_discussion'] === false, "Empty messages must immediately return false");
echo "OK!\n";

echo "\n🎉 Все тесты компонентов успешно пройдены!\n";

