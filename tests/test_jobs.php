<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\AiClientInterface;
use App\Jobs\ArbeitnowFetcher;
use App\Jobs\JobAnalyzer;
use App\Jobs\JobPromptBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

echo "🧪 Запуск тестов модуля Jobs (Arbeitnow & AI Filtering)...\n\n";

// ---------------------------------------------------------
// Test 1: ArbeitnowFetcher keyword and work type matching
// ---------------------------------------------------------
echo "1. Тест распознавания стека и формата работы в ArbeitnowFetcher... ";

$fetcher = new ArbeitnowFetcher();

$job1 = [
    'title' => 'Senior Symfony Developer',
    'tags' => ['PHP', 'Symfony', 'PostgreSQL'],
    'description' => '<p>We are seeking a senior backend engineer to build modern microservices in Symfony 7.</p>',
    'remote' => true,
];
assert($fetcher->matchesTechStack($job1) === true, "Must match Symfony in title/tags");
assert($fetcher->determineWorkType($job1) === 'Remote', "Must determine Remote");

$job2 = [
    'title' => 'E-Commerce Engineer (Magento 2 / Adobe Commerce)',
    'tags' => ['PHP', 'Magento'],
    'description' => 'Hybrid role in Berlin. You will maintain our B2B shop.',
    'remote' => false,
    'location' => 'Berlin',
];
assert($fetcher->matchesTechStack($job2) === true, "Must match Magento 2");
assert($fetcher->determineWorkType($job2) === 'Hybrid', "Must determine Hybrid from description");

$job3 = [
    'title' => 'Lead Drupal 10 Architect',
    'tags' => ['Drupal', 'CMS'],
    'description' => 'Fully remote anywhere in the EU. PHP 8.3 & custom Drupal modules.',
    'remote' => false,
];
assert($fetcher->matchesTechStack($job3) === true, "Must match Drupal");
assert($fetcher->determineWorkType($job3) === 'Remote', "Must determine Remote from 'Fully remote'");

$job4 = [
    'title' => 'Warehouse Lead Specialist (PHP certificate needed for internal portal)',
    'tags' => ['Logistics'],
    'description' => 'Office presence required every day at warehouse. Strictly on-site.',
    'remote' => false,
    'location' => 'Hamburg',
];
assert($fetcher->determineWorkType($job4) === null, "Strictly on-site must be rejected (return null)");

echo "OK!\n";

// ---------------------------------------------------------
// Test 2: URL Redirect resolution for company careers ATS
// ---------------------------------------------------------
echo "2. Тест резолвинга ссылки на страницу карьеры компании (/apply redirect)... ";

$mockHandler = new MockHandler([
    new Response(302, [
        'Location' => 'https://careers.example.com/jobs/12345?gh_jid=999&utm_source=arbeitnow.com&ref=arbeitnow.com'
    ]),
]);
$mockClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

$fetcherWithMock = new ArbeitnowFetcher($mockClient);
$resolved = $fetcherWithMock->resolveDirectCareersUrl('https://www.arbeitnow.com/jobs/companies/example/senior-developer-12345');

assert($resolved === 'https://careers.example.com/jobs/12345?gh_jid=999', "Must extract direct careers URL and strip tracking params. Got: " . $resolved);
echo "OK!\n";

// ---------------------------------------------------------
// Test 3: JobAnalyzer parsing AI response
// ---------------------------------------------------------
echo "3. Тест парсинга ответа от ИИ в JobAnalyzer... ";

$dummyAiClient = new class implements AiClientInterface {
    public function generateDigest(string $systemPrompt, string $userPrompt): string
    {
        return json_encode([
            'has_valid_jobs' => true,
            'valid_count' => 1,
            'summary' => 'Найдена 1 вакансия Symfony Developer в Берлине (Remote).',
            'post_text' => "💼 *Свежие вакансии: PHP / Symfony / Drupal / Magento*\n\n1️⃣ *Senior Symfony Developer* — **Acme Corp**\n• 📍 *Формат:* Remote (EU)\n• 🛠 *Стек:* PHP 8.3, Symfony 7, PostgreSQL\n• 💰 *Зарплата:* €75,000 / year\n• 📝 *Суть:* Разработка новой платформы. Английский B2+.\n🔗 [Откликнуться на сайте компании](https://careers.example.com/jobs/12345)",
        ], JSON_THROW_ON_ERROR);
    }
};

$analyzer = new JobAnalyzer($dummyAiClient);
$result = $analyzer->analyze('2026-09-27', [
    [
        'id' => 'job_1',
        'title' => 'Senior Symfony Developer',
        'company' => 'Acme Corp',
        'location' => 'Berlin',
        'is_remote' => true,
        'work_type' => 'Remote',
        'url' => 'https://careers.example.com/jobs/12345',
        'tags' => ['PHP', 'Symfony'],
        'description_snippet' => 'We are hiring a senior Symfony engineer...',
    ]
]);

assert($result['has_valid_jobs'] === true, "Must have valid jobs");
assert($result['valid_count'] === 1, "Must have count 1");
assert(str_contains($result['post_text'], 'Senior Symfony Developer'), "Post text must contain job title");
assert(str_contains($result['post_text'], 'https://careers.example.com/jobs/12345'), "Post text must contain apply URL");

echo "OK!\n";

echo "\n🎉 Все тесты Jobs успешно пройдены!\n";
