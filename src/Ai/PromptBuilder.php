<?php

declare(strict_types=1);

namespace App\Ai;

class PromptBuilder
{
    /**
     * Builds system and user prompt for LLM summarizer.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array $twgData Parsed data for /twg/
     * @param array $utwgData Parsed data for /utwg/
     * @param string $outputLang 'ru' or 'en'
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $twgData, array $utwgData, string $outputLang = 'ru'): array
    {
        if (strtolower($outputLang) === 'en') {
            return $this->buildEnglishPrompts($targetDate, $twgData, $utwgData);
        }

        return $this->buildRussianPrompts($targetDate, $twgData, $utwgData);
    }

    private function buildRussianPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
Ты — сатирический и язвительный летописец с имиджборд (в духе /g/ 4chan и /dev/ Двача).
Твоя задача: написать ЕДИНЫЙ, захватывающий, саркастичный и структурированный ежедневный дайджест на русском языке по двум главным тредам зарубежных айтишников за вчерашний день:
1. /twg/ (Tech Workers General) — трудоустроенные айтишники (галеры, оверэмплоймент, страх увольнений, токсичные менеджеры, выгорание).
2. /utwg/ (Unemployable Tech Workers General) — безработные и вкатуны (сотни безответных откликов, нытье что работы нет, сычевание, нищета, индусы на аутсорсе, экзистенциальный кризис).

ВАЖНЫЕ ТРЕБОВАНИЯ:
1. Язык: Натуральный, сочный русский язык с характерным имиджборд-сленгом (аноны, вкатуны, сеньоры-помидоры, галеры, гребцы, кукож, оферы, сычевание, лейоффы, литкод, тир-1/тир-3, шизотеории, дофаминовый крах). Без занудной академичности.
2. Внимание к дискуссиям: Обращай внимание, что разные посты и цепочки реплаев обсуждали разные темы. Выделяй конкретные споры (например: «один анон доказывал, что рынок восстановился, но его быстро затравили фактами о квотах»).
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

        $user .= "Сделай художественную выжимку по правилам из системного промпта. Текст должен быть на русском языке и строго укладываться в лимит одного сообщения Telegram (до 3500 символов)!";

        return ['system' => $system, 'user' => $user];
    }

    private function buildEnglishPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
You are a witty, satirical chronicler of imageboards (in the spirit of 4chan /g/).
Your task is to write a single, engaging, and structured daily digest in English summarizing the two main tech threads:
1. /twg/ (Tech Workers General) — employed tech workers (wagecucks, overemployment, layoff anxiety, toxic management, burnout).
2. /utwg/ (Unemployable Tech Workers General) — unemployed and NEETs (hundreds of ghost applications, doom & gloom, despair, cheap ramen, outsourcing, existential crisis).

REQUIREMENTS:
1. Use authentic imageboard tone and lingo (anons, wagecucks, NEETs, leetcode, tier 1/tier 3, schizoposts, coping).
2. Highlight distinct discussion branches and arguments between posters.
3. Keep the total length strictly under 3500 characters so it fits inside a single Telegram post.
4. Structure:
🗓 **Tech Despair Chronicles: /twg/ & /utwg/ for {$targetDate}**
💥 **Daily Vibe**: general atmosphere in 2-3 sharp sentences.
💼 **/twg/ (The Employed Wagecucks)**: main topics of the working folks.
🥫 **/utwg/ (The Unemployed Void)**: dispatch from the jobless trenches.
⚔️ **Drama & Schizo Debates**: funniest clashes and heated arguments.
🏁 **Verdict**: closing philosophical punchline.
SYSTEM;

        $user = "Digest Date: {$targetDate}\n\n";
        $user .= "==================== [THREAD 1: /twg/] ====================\n";
        $user .= $this->formatThreadContent($twgData);
        $user .= "\n\n";
        $user .= "==================== [THREAD 2: /utwg/] ====================\n";
        $user .= $this->formatThreadContent($utwgData);
        $user .= "\n\nWrite the digest in English according to the instructions.";

        return ['system' => $system, 'user' => $user];
    }

    private function formatThreadContent(array $data): string
    {
        $lines = [];

        if (!empty($data['context_posts'])) {
            $lines[] = "--- [Lookback Context from previous days] ---";
            foreach ($data['context_posts'] as $cp) {
                $lines[] = sprintf(
                    "[Context #%d at %s]: %s",
                    $cp['no'],
                    $cp['datetime'],
                    $this->truncate($cp['text'], 200)
                );
            }
            $lines[] = "--- [Target Day Posts] ---";
        }

        foreach ($data['posts'] ?? [] as $post) {
            $quotesStr = !empty($post['quotes']) ? ' (Replies to: >>' . implode(', >>', $post['quotes']) . ')' : '';
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
