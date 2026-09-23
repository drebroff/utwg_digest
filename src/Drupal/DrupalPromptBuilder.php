<?php

declare(strict_types=1);

namespace App\Drupal;

class DrupalPromptBuilder
{
    private const MAX_MESSAGE_CHARS = 500;
    private const MAX_MESSAGES_FOR_AI = 200;

    /**
     * Build system and user prompt for Grok/AI to analyze Drupal community chat.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param string $chatUsername e.g. 'drupal_rus'
     * @param array<int, array{
     *     id: int,
     *     date: string,
     *     timestamp?: int,
     *     sender_username: ?string,
     *     sender_name: string,
     *     text: string,
     *     reply_to_msg_id: ?int
     * }> $messages
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, string $chatUsername, array $messages): array
    {
        $system = <<<SYSTEM
Ты — объективный аналитик русскоязычных IT-сообществ и рынка труда разработчиков.
Твоя задача — внимательно изучить сообщения за день из Telegram-чата сообщества Drupal (@{$chatUsername}) и определить, касалось ли общение темы работы и рынка труда.

КАТЕГОРИИ РЕЛЕВАНТНЫХ ТЕМ:
1. Ситуация на рынке труда (ставки, зарплаты, кризис найма, сокращения, востребованность Drupal/PHP/Symfony, обсуждение галерей, клиентов, заказчиков).
2. Поиск работы (кто-то ищет работу, проект, подработку, выкладывает резюме, просит порекомендовать).
3. Предложение работы / вакансии (кто-то ищет разработчика, предлагает субподряд, публикует вакансию или заказ на доработку).

ЧТО СТРОГО ИГНОРИРОВАТЬ:
- Сугубо технические вопросы по коду (ошибки Composer, настройка Twig шаблонов, миграции, кэш, баги модулей, Docker и т.д.).
- Обычный бытовой флуд, не связанный с карьерой, наймом, деньгами и рынком труда.

ФОРМАТ ОТВЕТА (СТРОГО JSON):
Ты обязан вернуть ТОЛЬКО валидный JSON-объект без пояснительного текста и без markdown-блоков вокруг.

Если обсуждений на тему работы и рынка труда НЕ БЫЛО:
{
  "has_job_discussion": false,
  "reason": "Краткое пояснение, о чем общались в чате без тем найма/работы."
}

Если обсуждения БЫЛИ:
{
  "has_job_discussion": true,
  "topics": ["рынок труда", "поиск работы", "предложение работы"],
  "participants": ["@user1", "@user2"],
  "summary": "Краткое резюме сути дискуссии",
  "post_text": "🔥 **Бонус: сегодня в Drupal-сообществе (@{$chatUsername})** [обсуждали ситуацию на рынке труда / искали работу / предлагали работу]\\n\\n👥 **Участники:** @user1, @user2\\n\\n📌 **Суть обсуждения:**\\n• [Тезис 1]\\n• [Тезис 2]"
}

ТРЕБОВАНИЯ К post_text:
- Язык: русский.
- Стиль: живой, информативный, уважительный к сообществу.
- Указывать ники реальных авторов сообщений (через @username или Имя, если юзернейма нет).
- Четко и емко передавать аргументы сторон или суть вакансии/предложения.
SYSTEM;

        $user = "Дата анализа: {$targetDate}\n";
        $user .= "Чат: @{$chatUsername}\n";
        $user .= "Количество сообщений за день: " . count($messages) . "\n\n";
        $user .= "==================== [СООБЩЕНИЯ ИЗ ЧАТА ЗА СУТКИ] ====================\n";
        $user .= $this->formatMessages($messages);
        $user .= "\n====================================================================\n\n";
        $user .= "Проанализируй диалоги и верни строгий JSON-ответ по инструкции.";

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Format messages chronologically for LLM context.
     *
     * @param array<int, array{
     *     id: int,
     *     date: string,
     *     sender_username: ?string,
     *     sender_name: string,
     *     text: string,
     *     reply_to_msg_id: ?int
     * }> $messages
     * @return string
     */
    public function formatMessages(array $messages): string
    {
        if (empty($messages)) {
            return "(Сообщений за указанный период нет)";
        }

        // Limit to prevent exceeding LLM context if chat had thousands of messages
        $limited = array_slice($messages, 0, self::MAX_MESSAGES_FOR_AI);
        $lines = [];

        foreach ($limited as $msg) {
            $sender = !empty($msg['sender_username'])
                ? $msg['sender_username']
                : ($msg['sender_name'] ?: 'Участник');

            $replyInfo = !empty($msg['reply_to_msg_id'])
                ? " (ответ на #{$msg['reply_to_msg_id']})"
                : "";

            $cleanText = $this->truncate((string)$msg['text'], self::MAX_MESSAGE_CHARS);

            $lines[] = sprintf(
                "[%s] #%d %s%s: %s",
                $msg['date'] ?? '',
                $msg['id'] ?? 0,
                $sender,
                $replyInfo,
                $cleanText
            );
        }

        if (count($messages) > self::MAX_MESSAGES_FOR_AI) {
            $lines[] = sprintf(
                "... [еще %d сообщений усечено для оптимизации контекста]",
                count($messages) - self::MAX_MESSAGES_FOR_AI
            );
        }

        return implode("\n", $lines);
    }

    private function truncate(string $text, int $maxChars): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if (mb_strlen($clean) <= $maxChars) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxChars) . '...';
    }
}
