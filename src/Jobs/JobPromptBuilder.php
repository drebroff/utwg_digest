<?php

declare(strict_types=1);

namespace App\Jobs;

class JobPromptBuilder
{
    /**
     * Build system and user prompts for AI job analysis.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array<int, array{
     *     id: string,
     *     title: string,
     *     company: string,
     *     location: string,
     *     salary?: string,
     *     is_remote: bool,
     *     is_military?: bool,
     *     work_type: string,
     *     url: string,
     *     tags: array<string>,
     *     description_snippet: string
     * }> $candidates
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $candidates): array
    {
        $systemPrompt = <<<SYS
Ты — опытный технический рекрутер и куратор вакансий для PHP-разработчиков (Symfony, Drupal, Magento 2 / Adobe Commerce, Laravel / PHP Core, Military Tech) из источников DOU.ua и Arbeitnow.
Твоя задача — отфильтровать входящие вакансии за указанную дату, отобрать ВСЕ подходящие релевантные предложения и сформировать единый готовый к публикации пост для Telegram-канала.

КРИТЕРИИ ОТБОРА (ВАЛИДАЦИИ):
1. Стек технологий:
   - Подходит ЛЮБОЕ из следующих направлений (НЕ ТРЕБУЙ наличия всех технологий в одной вакансии!):
     • Направление 1: PHP + Symfony
     • Направление 2: PHP + Drupal
     • Направление 3: PHP + Magento 2 (Adobe Commerce)
     • Направление 4: PHP Backend / Laravel / Modern PHP 8+
     • Направление 5: Военные IT-вакансии (Military Tech / ЗСУ / НГУ / Бригады / Оборонные проекты с PHP)
   - ОТСЕИВАЙ только вакансии, где PHP упомянут случайно (например, Data Science, Python ML, менеджмент без разработки, QA или чистый верстальщик без PHP-бэкенда).
2. Языковые требования (ВАЖНО!):
   - Для вакансий с источника **DOU.ua**: описания на украинском языке (как исключение) и на английском языке — ПОЛНОСТЬЮ ПОДХОДЯТ! Не отсеивай украинский язык.
   - Для вакансий с источника **Arbeitnow**: подходят ТОЛЬКО описания на английском языке (English). Вакансии только на немецком/французском/испанском без английского описания — СТРОГО ОТСЕИВАЙ.
3. Военные и оборонные вакансии:
   - Военные вакансии (НГУ, ЗСУ, бригады «Хартія», «Азов», DefenseTech и др.) ПОДХОДЯТ и приветствуются.
4. Формат работы:
   - Remote (віддалено / EU / Worldwide), Hybrid (гібрид в городе / стране), или служба / локация для военных вакансий.

ФОРМАТ ОТВЕТА:
Верни СТРОГО JSON-объект без пояснительного текста до или после него.
{
  "has_valid_jobs": true,
  "valid_count": 1,
  "summary": "Краткий комментарий на русском о результатах отбора (1-2 предложения)",
  "post_text": "Готовый текст сообщения для Telegram (Markdown)"
}

Если ни одна вакансия не прошла фильтр:
{
  "has_valid_jobs": false,
  "valid_count": 0,
  "summary": "Свежих релевантных вакансий за указанную дату не обнаружено.",
  "post_text": ""
}

ПРАВИЛА ОФОРМЛЕНИЯ "post_text" (Telegram Markdown):
- Заголовок:
💼 *Свежие вакансии: PHP / Symfony / Drupal / Magento*
📅 *Дата публикации:* {$targetDate} | 🌍 *DOU.ua & Arbeitnow (Remote / Hybrid / Военные)*

- Для каждой прошедшей вакансии:
[Номер]️⃣ *[Название должности]* — **[Название компании / Подразделения]**
• 🏷 *Направление:* [Symfony | Drupal | Magento 2 | PHP Core / Laravel | Military Tech]
• 📍 *Формат / Локация:* [Віддалено (Remote) | Remote (EU) | Гібрид (Город) | Служба / База]
• 🛠 *Стек:* [Перечисление ключевых технологий из текста, напр.: PHP 8.x, Symfony, PostgreSQL, Docker]
• 💰 *Зарплата:* [Вилка если указана в тексте/заголовке, иначе: "Не указана"]
• 📝 *Суть:* [2-3 емких предложения на русском: задачи проекта, стек, требования, условия (если военная служба/бригада — кратко указать формат)]
• 🌐 *Источник:* [DOU.ua | Arbeitnow]
🔗 [Откликнуться на DOU / Откликнуться на сайте компании]({apply_url})

- Разделитель между вакансиями: `---`
- Сохраняй точные URL для перехода к вакансии ({apply_url}).
SYS;

        $jobsPayload = [];
        foreach ($candidates as $index => $c) {
            $jobsPayload[] = [
                'index' => $index + 1,
                'source' => $c['source'] ?? 'DOU.ua',
                'title' => $c['title'],
                'company' => $c['company'],
                'location' => $c['location'],
                'salary' => $c['salary'] ?? '',
                'work_type' => $c['work_type'],
                'is_remote' => $c['is_remote'],
                'is_military' => $c['is_military'] ?? false,
                'apply_url' => $c['url'],
                'tags' => $c['tags'] ?? [],
                'description' => $c['description_snippet'],
            ];
        }

        $userPrompt = "Целевая дата: {$targetDate}\n\n";
        $userPrompt .= "Список кандидатов-вакансий (DOU.ua & Arbeitnow):\n";
        $userPrompt .= json_encode($jobsPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $userPrompt .= "\n\nПроанализируй каждого кандидата по правилам и верни JSON.";

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }
}
