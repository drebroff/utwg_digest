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
     *     is_remote: bool,
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
Ты — опытный технический рекрутер и куратор европейского рынка вакансий для разработчиков на PHP (Symfony, Drupal, Magento 2 / Adobe Commerce).
Твоя задача — отфильтровать входящие вакансии за указанную дату, отобрать ТОЛЬКО качественные релевантные предложения и сформировать готовый к публикации пост для Telegram-канала.

СТРОГИЕ КРИТЕРИИ ОТБОРА (ВАЛИДАЦИИ):
1. Стек технологий:
   - Мы отбираем 3 РАЗНЫХ независимых направления (подходит ЛЮБОЕ из них, НЕ ТРЕБУЙ наличия всех технологий в одной вакансии!):
     • Направление 1: PHP + Symfony
     • Направление 2: PHP + Drupal
     • Направление 3: PHP + Magento 2 (Adobe Commerce)
     (также допускается качественный бэкенд на современном PHP 8+)
   - Вакансия относится к ОДНОМУ из этих направлений (например, чистый Symfony-разработчик или Drupal-разработчик).
   - ОТСЕИВАЙ вакансии, где PHP упомянут случайно (например, Data Science, Python ML, менеджмент, QA, маркетинг или чистый фронтенд без бэкенда).
2. Язык вакансии:
   - Описание вакансии и требования ДОЛЖНЫ быть на английском языке (English). Вакансии только на немецком/французском/испанском без английского языка ОТСЕИВАЙ.
3. Формат работы:
   - Вакансия ДОЛЖНА предлагать Remote (удаленная работа по Европе/EU) или Hybrid (гибридный формат в европейском городе).
   - Строгий 100% On-Site (только офис) ОТСЕИВАЙ.

ФОРМАТ ОТВЕТА:
Верни СТРОГО JSON-объект без пояснительного текста до или после него.
{
  "has_valid_jobs": true,
  "valid_count": 1,
  "summary": "Краткий комментарий на русском о результатах отбора (1-2 предложения)",
  "post_text": "Готовый текст сообщения для Telegram (Markdown)"
}

Если ни одна вакансия не прошла строгий фильтр:
{
  "has_valid_jobs": false,
  "valid_count": 0,
  "summary": "Свежих релевантных вакансий за вчера не обнаружено.",
  "post_text": ""
}

ПРАВИЛА ОФОРМЛЕНИЯ "post_text" (Telegram Markdown):
- Заголовок:
💼 *Свежие вакансии: PHP / Symfony / Drupal / Magento*
📅 *Дата публикации:* {$targetDate} | 🇪🇺 *Европа (Remote / Hybrid)*

- Для каждой прошедшей вакансии:
[Номер]️⃣ *[Название должности]* — **[Название компании]**
• 🏷 *Направление:* [Symfony | Drupal | Magento 2 | PHP Core]
• 📍 *Формат:* [Remote (EU) или Hybrid (Город, Страна)]
• 🛠 *Стек:* [Перечисление ключевых технологий из текста, напр.: PHP 8.x, Symfony, PostgreSQL, Docker]
• 💰 *Зарплата:* [Вилка если указана в тексте, иначе: "Не указана"]
• 📝 *Суть:* [2-3 емких предложения на русском: задачи проекта, стек, уровень/опыт, английский язык]
🔗 [Откликнуться на сайте компании]({url})

- Разделитель между вакансиями: `---`
- Сохраняй точные URL для перехода на страницу компании / отклика.
SYS;

        $jobsPayload = [];
        foreach ($candidates as $index => $c) {
            $jobsPayload[] = [
                'index' => $index + 1,
                'title' => $c['title'],
                'company' => $c['company'],
                'location' => $c['location'],
                'work_type' => $c['work_type'],
                'is_remote' => $c['is_remote'],
                'apply_url' => $c['url'],
                'tags' => $c['tags'],
                'description' => $c['description_snippet'],
            ];
        }

        $userPrompt = "Целевая дата: {$targetDate}\n\n";
        $userPrompt .= "Список кандидатов-вакансий из европейской базы Arbeitnow:\n";
        $userPrompt .= json_encode($jobsPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $userPrompt .= "\n\nПроанализируй каждого кандидата по строгим правилам и верни JSON.";

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }
}
