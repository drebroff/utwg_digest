<?php

declare(strict_types=1);

namespace App\Ai;

class PromptBuilder
{
    /**
     * Builds system and user prompt for LLM summarizer in native Russian imageboard slang.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array $twgData Parsed data for /twg/
     * @param array $utwgData Parsed data for /utwg/
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
Ты — сатирический и язвительный летописец с имиджборд (в духе /g/ 4chan и /dev/ Двача).
Твоя задача: написать ЕДИНЫЙ, захватывающий, саркастичный и структурированный ежедневный дайджест на русском языке по двум главным тредам зарубежных айтишников за вчерашний день:
1. /twg/ (Tech Workers General) — трудоустроенные айтишники (галеры, оверэмплоймент, страх увольнений, токсичные менеджеры, выгорание).
2. /utwg/ (Unemployable Tech Workers General) — безработные и вкатуны (сотни безответных откликов, нытье что работы нет, сычевание, нищета, индусы на аутсорсе, экзистенциальный кризис).

ВАЖНЫЕ ТРЕБОВАНИЯ:
1. Язык: Натуральный, сочный русский язык с характерным имиджборд-сленгом (аноны, вкатуны, сеньоры-помидоры, галеры, гребцы, кукож, оферы, сычевание, лейоффы, литкод, тир-1/тир-3, шизотеории, дофаминовый крах). Без занудной академичности.
2. Внимание к дискуссиям: Обращай внимание, что разные посты и цепочки реплаев обсуждали разные темы. Выделяй конкретные споры и разногласия между анонами.
3. Учитывай контекст: В данных есть пометки [Контекст от предыдущего дня], используй их, чтобы точно понимать суть дискуссий.
4. Жесткий лимит размера: Текст дайджеста должен быть информативным, но строго ПОМЕЩАТЬСЯ В ОДНО СООБЩЕНИЕ TELEGRAM (не более 3500 символов суммарно с разметкой).
5. Форматирование: Используй Telegram Markdown (жирный шрифт **текст**, курсив *текст*, цитаты > текст).

РЕКОМЕНДУЕМАЯ СТРУКТУРА ДАЙДЖЕСТА:
🗓 **Хроники айти-дна: /twg/ & /utwg/ за {$targetDate}**

💥 **Градус дурки дня**: 2-3 едких предложения об общем настроении суток.
💼 **/twg/ (У галеры есть весло)**: ключевые темы тех, кто пока работает.
🥫 **/utwg/ (Свободная касса и пустота)**: сводка из бездны безработицы и нытья.
⚔️ **Срачи и шизотеории дня**: самые яркие столкновения мнений и цитаты.
🏁 **Итог**: короткий философский вывод анонимуса.
SYSTEM;

        $user = "Дата дайджеста: {$targetDate}\n\n";
        $user .= "==================== [ТРЕД 1: /twg/ Tech Workers General] ====================\n";
        $user .= "Всего постов за день: " . count($twgData['posts'] ?? []) . "\n";
        $user .= $this->formatThreadContent($twgData);
        $user .= "\n\n";

        $user .= "==================== [ТРЕД 2: /utwg/ Unemployable Tech Workers General] ====================\n";
        $user .= "Всего постов за день: " . count($utwgData['posts'] ?? []) . "\n";
        $user .= $this->formatThreadContent($utwgData);
        $user .= "\n\n";

        $user .= "Сделай художественную выжимку по правилам из системного промпта. Текст должен быть на сочном русском языке в сленге имиджборд и строго укладываться в лимит одного сообщения Telegram (до 3500 символов)!";

        return ['system' => $system, 'user' => $user];
    }

    private function formatThreadContent(array $data): string
    {
        $lines = [];

        if (!empty($data['context_posts'])) {
            $lines[] = "--- [Контекст от предыдущих дней для цепочек ответов] ---";
            foreach ($data['context_posts'] as $cp) {
                $lines[] = sprintf(
                    "[Контекст #%d в %s]: %s",
                    $cp['no'],
                    $cp['datetime'],
                    $this->truncate($cp['text'], 200)
                );
            }
            $lines[] = "--- [Посты непосредственно за вчера] ---";
        }

        foreach ($data['posts'] ?? [] as $post) {
            $quotesStr = !empty($post['quotes']) ? ' (Ответ на: >>' . implode(', >>', $post['quotes']) . ')' : '';
            $lines[] = sprintf(
                "#%d [%s]%s: %s",
                $post['no'],
                $post['datetime'],
                $quotesStr,
                $this->truncate($post['text'], 400)
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
