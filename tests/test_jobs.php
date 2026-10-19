<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Ai\AiClientInterface;
use App\Jobs\ArbeitnowFetcher;
use App\Jobs\DouFetcher;
use App\Jobs\JobAnalyzer;
use App\Jobs\JobPromptBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

echo "🧪 Запуск тестов модуля Jobs (DOU.ua & Arbeitnow & AI Filtering)...\n\n";

// ---------------------------------------------------------
// Test 1: DouFetcher title metadata parsing & salary
// ---------------------------------------------------------
echo "1. Тест парсинга метаданных заголовка и зарплаты в DouFetcher... ";

$fetcher = new DouFetcher();

$parsed1 = $fetcher->parseTitleMeta("Senior PHP Software Engineer (Payments) в TrueLabel, Лісабон (Португалія), віддалено");
assert($parsed1['title'] === 'Senior PHP Software Engineer (Payments)', "Title mismatch");
assert($parsed1['company'] === 'TrueLabel', "Company mismatch");
assert($parsed1['is_remote'] === true, "Remote mismatch");
assert($parsed1['is_military'] === false, "Military mismatch");

$parsed2 = $fetcher->parseTitleMeta("Backend Developer (PHP, Laravel) в 13 бригада оперативного призначення НГУ «Хартія», $560–1700");
assert($parsed2['title'] === 'Backend Developer (PHP, Laravel)', "Title mismatch");
assert(str_contains($parsed2['company'], '13 бригада'), "Company mismatch");
assert($parsed2['salary'] === '$560–1700', "Salary mismatch: " . $parsed2['salary']);
assert($parsed2['is_military'] === true, "Must detect military vacancy");

$parsed3 = $fetcher->parseTitleMeta("Drupal developer в DevBranch, $1000–5200, Луцьк");
assert($parsed3['title'] === 'Drupal developer', "Title mismatch");
assert($parsed3['salary'] === '$1000–5200', "Salary mismatch");

echo "OK!\n";

// ---------------------------------------------------------
// Test 2: DouFetcher keyword & military matching
// ---------------------------------------------------------
echo "2. Тест соответствия стеку (PHP/Symfony/Drupal/Magento) и военным позициям... ";

assert($fetcher->matchesTechStack("Senior Symfony Developer", "Experience with Symfony 7 and PHP 8.3") === true);
assert($fetcher->matchesTechStack("Magento 2 Lead", "Adobe Commerce platform") === true);
assert($fetcher->matchesTechStack("Drupal 10 Engineer", "Custom Drupal modules") === true);
assert($fetcher->matchesTechStack("Backend developer в Азов", "Розробка сервісів на PHP та Laravel") === true);
assert($fetcher->isMilitaryVacancy("12-та бригада спеціального призначення НГУ «Азов»") === true);
assert($fetcher->isMilitaryVacancy("DefenseTech AI Project") === true);
assert($fetcher->isMilitaryVacancy("Acme Corp E-Commerce") === false);

echo "OK!\n";

// ---------------------------------------------------------
// Test 3: DouFetcher RSS Mock XML parsing and date filtering
// ---------------------------------------------------------
echo "3. Тест парсинга RSS-ленты DOU с мок-ответом и фильтрацией даты... ";

$sampleRssXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0">
  <channel>
    <title>Вакансії в категорії PHP на DOU.ua</title>
    <item>
      <title>Senior Symfony Developer в TechCo, віддалено</title>
      <link>https://jobs.dou.ua/companies/techco/vacancies/1001/?utm_source=jobsrss</link>
      <description>&lt;p&gt;We need a senior Symfony 7 specialist.&lt;/p&gt;</description>
      <pubDate>Mon, 19 Oct 2026 10:00:00 +0300</pubDate>
    </item>
    <item>
      <title>Junior Java Engineer в OldCo, Київ</title>
      <link>https://jobs.dou.ua/companies/oldco/vacancies/1002/?utm_source=jobsrss</link>
      <description>&lt;p&gt;Java Spring Boot position.&lt;/p&gt;</description>
      <pubDate>Mon, 19 Oct 2026 11:00:00 +0300</pubDate>
    </item>
    <item>
      <title>Drupal Specialist в WebStudio, $2000–3000, віддалено</title>
      <link>https://jobs.dou.ua/companies/webstudio/vacancies/1003/?utm_source=jobsrss</link>
      <description>&lt;p&gt;Drupal 10 development.&lt;/p&gt;</description>
      <pubDate>Sun, 18 Oct 2026 15:00:00 +0300</pubDate>
    </item>
  </channel>
</rss>
XML;

$mockHandler = new MockHandler([
    new Response(200, [], $sampleRssXml),
    new Response(200, [], $sampleRssXml),
    new Response(200, [], $sampleRssXml),
    new Response(200, [], $sampleRssXml),
]);
$mockClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

$fetcherWithMock = new DouFetcher($mockClient);
$jobsOct19 = $fetcherWithMock->fetchJobsForDate('2026-10-19', 'Europe/Kyiv');

assert(count($jobsOct19) === 1, "Only 1 matching PHP/Symfony job on 2026-10-19 expected. Got: " . count($jobsOct19));
assert($jobsOct19[0]['source'] === 'DOU.ua', "Source mismatch");
assert($jobsOct19[0]['title'] === 'Senior Symfony Developer', "Title mismatch");
assert($jobsOct19[0]['url'] === 'https://jobs.dou.ua/companies/techco/vacancies/1001/', "Clean URL mismatch: " . $jobsOct19[0]['url']);

echo "OK!\n";

// ---------------------------------------------------------
// Test 4: ArbeitnowFetcher mock JSON parsing
// ---------------------------------------------------------
echo "4. Тест парсинга ArbeitnowFetcher с мок-JSON... ";

$targetTs = (new DateTimeImmutable('2026-10-19 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
$olderTs = (new DateTimeImmutable('2026-10-18 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
$sampleArbeitJson = json_encode([
    'data' => [
        [
            'slug' => 'senior-php-developer-berlin',
            'title' => 'Senior PHP Engineer (Symfony)',
            'company_name' => 'EuroFintech',
            'location' => 'Berlin, Germany',
            'remote' => true,
            'url' => 'https://example.com/careers/senior-php-developer',
            'created_at' => $targetTs,
            'tags' => ['PHP', 'Symfony', 'PostgreSQL'],
            'description' => '<p>We are looking for a Senior PHP Developer with Symfony expertise. English required.</p>',
        ],
        [
            'slug' => 'python-engineer-munich',
            'title' => 'Python Backend Developer',
            'company_name' => 'MunichAI',
            'location' => 'Munich',
            'remote' => true,
            'url' => 'https://example.com/careers/python-engineer-munich',
            'created_at' => $olderTs,
            'tags' => ['Python', 'Django'],
            'description' => '<p>Python developer role.</p>',
        ]
    ]
], JSON_THROW_ON_ERROR);

$mockHandlerArbeit = new MockHandler([
    new Response(200, [], $sampleArbeitJson),
]);
$mockClientArbeit = new Client(['handler' => HandlerStack::create($mockHandlerArbeit)]);

$arbeitFetcher = new ArbeitnowFetcher($mockClientArbeit);
$arbeitJobs = $arbeitFetcher->fetchJobsForDate('2026-10-19', 'UTC');

assert(count($arbeitJobs) === 1, "Only 1 matching PHP/Symfony job expected from Arbeitnow. Got: " . count($arbeitJobs));
assert($arbeitJobs[0]['source'] === 'Arbeitnow', "Source mismatch");
assert($arbeitJobs[0]['title'] === 'Senior PHP Engineer (Symfony)', "Title mismatch");

echo "OK!\n";

// ---------------------------------------------------------
// Test 5: JobPromptBuilder & JobAnalyzer parsing combined AI response
// ---------------------------------------------------------
echo "5. Тест объединения кандидатов DOU + Arbeitnow и валидации ИИ... ";

$combinedCandidates = array_merge($jobsOct19, $arbeitJobs);
assert(count($combinedCandidates) === 2, "Expected 2 combined candidates");

$promptBuilder = new JobPromptBuilder();
$prompts = $promptBuilder->buildPrompts('2026-10-19', $combinedCandidates);

assert(str_contains($prompts['system'], 'DOU.ua'), "Prompt must mention DOU.ua");
assert(str_contains($prompts['system'], 'Arbeitnow'), "Prompt must mention Arbeitnow");
assert(str_contains($prompts['system'], 'украинском'), "Prompt must allow Ukrainian for DOU");
assert(str_contains($prompts['system'], 'ТОЛЬКО описания на английском языке'), "Prompt must require English for Arbeitnow");

$dummyAiClient = new class implements AiClientInterface {
    public function generateDigest(string $systemPrompt, string $userPrompt): string
    {
        return json_encode([
            'has_valid_jobs' => true,
            'valid_count' => 2,
            'summary' => 'Найдено 2 релевантные вакансии (1 с DOU.ua и 1 с Arbeitnow).',
            'post_text' => "💼 *Свежие вакансии: PHP / Symfony / Drupal / Magento*\n📅 *Дата публикации:* 2026-10-19 | 🌍 *DOU.ua & Arbeitnow (Remote / Hybrid / Военные)*\n\n1️⃣ *Senior Symfony Developer* — **TechCo**\n• 🏷 *Направление:* Symfony\n• 📍 *Формат / Локация:* Віддалено (Remote)\n• 🛠 *Стек:* PHP 8.3, Symfony 7, PostgreSQL\n• 💰 *Зарплата:* Не указана\n• 📝 *Суть:* Разработка архитектуры и микросервисов.\n• 🌐 *Источник:* DOU.ua\n🔗 [Откликнуться на DOU](https://jobs.dou.ua/companies/techco/vacancies/1001/)\n\n---\n\n2️⃣ *Senior PHP Engineer (Symfony)* — **EuroFintech**\n• 🏷 *Направление:* Symfony\n• 📍 *Формат / Локация:* Remote (EU)\n• 🛠 *Стек:* PHP 8.x, Symfony, PostgreSQL\n• 💰 *Зарплата:* Не указана\n• 📝 *Суть:* Разработка финтех-сервисов на Symfony в международной команде.\n• 🌐 *Источник:* Arbeitnow\n🔗 [Откликнуться на сайте компании](https://arbeitnow.com/jobs/companies/eurofintech/senior-php-developer-berlin)",
        ], JSON_THROW_ON_ERROR);
    }
};

$analyzer = new JobAnalyzer($dummyAiClient, $promptBuilder);
$result = $analyzer->analyze('2026-10-19', $combinedCandidates);

assert($result['has_valid_jobs'] === true, "Must have valid jobs");
assert($result['valid_count'] === 2, "Must have count 2");
assert(str_contains($result['post_text'], 'Senior Symfony Developer'), "Post text must contain DOU job");
assert(str_contains($result['post_text'], 'Senior PHP Engineer (Symfony)'), "Post text must contain Arbeitnow job");

echo "OK!\n";

echo "\n🎉 Все тесты Jobs успешно пройдены!\n";
