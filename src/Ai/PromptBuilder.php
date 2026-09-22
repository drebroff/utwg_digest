<?php

declare(strict_types=1);

namespace App\Ai;

class PromptBuilder
{
    private const MAX_POSTS_PER_THREAD = 35;
    private const MAX_POST_CHARS = 140;

    /**
     * Builds system and user prompt for LLM summarizer in native Russian imageboard slang.
     * Automatically compacts content to stay strictly under Groq's 8000 TPM limit.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array $twgData Parsed data for /twg/
     * @param array $utwgData Parsed data for /utwg/
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
Ты — сатирический летописец с имиджборд (в духе /g/ 4chan и /dev/ Двача).
Твоя задача: написать ЕДИНЫЙ саркастичный дайджест на сочном русском языке по двум тредам зарубежных айтишников за вчерашний день:
1. /twg/ (Tech Workers General) — трудоустроенные (галеры, оверэмплоймент, страх увольнений, выгорание).
2. /utwg/ (Unemployable Tech Workers General) — безработные (сотни безответных откликов, нытье что работы нет, сычевание, нищета, литкод, индусы).

ТРЕБОВАНИЯ:
1. Язык: Живой русский сленг имиджборд (аноны, вкатуны, сеньоры-помидоры, галеры, гребцы, кукож, оферы, сычевание, лейоффы, дофаминовый крах).
2. Выделяй конкретные споры и разногласия между анонами.
3. Форматирование: Telegram Markdown (жирный **текст**, цитаты > текст).
4. Объем: строго ДО 3200 символов (уместиться в одно сообщение Telegram).

СТРУКТУРА:
🗓 **Хроники айти-дна: /twg/ & /utwg/ за {$targetDate}**

💥 **Градус дурки дня**: 2-3 едких предложения.
💼 **/twg/ (У галеры есть весло)**: ключевые темы работающих.
🥫 **/utwg/ (Свободная касса и пустота)**: сводка из бездны безработицы.
⚔️ **Срачи и шизотеории дня**: самые яркие споры.
🏁 **Итог**: короткий философский вывод.
SYSTEM;

        $user = "Дата дайджеста: {$targetDate}\n\n";
        $user .= "==================== [ТРЕД 1: /twg/ Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($twgData);
        $user .= "\n\n";

        $user .= "==================== [ТРЕД 2: /utwg/ Unemployable Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($utwgData);
        $user .= "\n\n";

        $user .= "Сделай художественную выжимку на сочном русском сленге до 3200 символов!";

        return ['system' => $system, 'user' => $user];
    }

    private function formatThreadContent(array $data): string
    {
        $lines = [];

        if (!empty($data['context_posts'])) {
            $lines[] = "--- [Контекст от предыдущих дней] ---";
            $slicedContext = array_slice($data['context_posts'], 0, 5);
            foreach ($slicedContext as $cp) {
                $lines[] = sprintf(
                    "[Контекст #%d]: %s",
                    $cp['no'],
                    $this->truncate($cp['text'], self::MAX_POST_CHARS)
                );
            }
            $lines[] = "--- [Посты за вчера] ---";
        }

        $posts = $data['posts'] ?? [];

        // Filter out spam / 1-word posts (< 10 chars)
        $meaningful = array_values(array_filter($posts, function ($p) {
            return mb_strlen(trim((string)$p['text'])) >= 10;
        }));

        // Sort by quote count (dialogue relevance)
        usort($meaningful, function ($a, $b) {
            return count($b['quotes'] ?? []) <=> count($a['quotes'] ?? []);
        });

        $topPosts = array_slice($meaningful, 0, self::MAX_POSTS_PER_THREAD);

        foreach ($topPosts as $post) {
            $quotesStr = !empty($post['quotes']) ? ' (Ответ на: >>' . implode(', >>', $post['quotes']) . ')' : '';
            $lines[] = sprintf(
                "#%d%s: %s",
                $post['no'],
                $quotesStr,
                $this->truncate((string)$post['text'], self::MAX_POST_CHARS)
            );
        }

        return implode("\n", $lines);
    }

    private function truncate(string $text, int $maxChars): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($text));
        if (mb_strlen($clean) <= $maxChars) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxChars) . '...';
    }
}
