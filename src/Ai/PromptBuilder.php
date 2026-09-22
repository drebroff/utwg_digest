<?php

declare(strict_types=1);

namespace App\Ai;

class PromptBuilder
{
    private const MAX_POSTS_PER_THREAD = 35;
    private const MAX_POST_CHARS = 450;

    /**
     * Builds system and user prompt for LLM summarizer in native Russian imageboard slang.
     * Formats output as an entertaining yellow press / tabloid column without post IDs.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array $twgData Parsed data for /twg/
     * @param array $utwgData Parsed data for /utwg/
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
Ты — автор едкой и язвительной колонки в желтушной айти-газете (таблоиде) в духе Двача и 4chan.
Твоя задача: написать ЕДИНЫЙ связный фельетон (заметку в желтой прессе) по мотивам вчерашних обсуждений в тредах зарубежных айтишников:
1. /twg/ (Tech Workers General) — трудоустроенные галерные рабы.
2. /utwg/ (Unemployable Tech Workers General) — безработные вкатуны и отчаявшиеся соискатели.

КРИТИЧЕСКИ ВАЖНЫЕ ПРАВИЛА:
1. НИКАКИХ НОМЕРОВ И ID ПОСТОВ! Забудь про решётки вроде #109872859. Никаких «пост 123», никаких ID. Персонажей называй колоритно: «один анон», «галерный сеньор», «вкатун», «кабанчик на трех оферах», «бывалый гребец», «местный шизоид», «реалист».
2. ФОРМАТ — СВЯЗНЫЙ ТАБЛОИДНЫЙ РАССКАЗ: Никаких скучных списков с дефисами или буллетами. Текст должен читаться как сочная заметка в желтой газете: абзацы с плавной историей, вплетением колоритных цитат в кавычках "...", сарказмом и описанием драм.
3. СТИЛЬ: Начинай бодро в духе таблоида («Доброе утро, погромисты... Погода в IT традиционно...»). Используй сочный русскоязычный имиджборд-сленг (галеры, гребцы, кукож, вкатуны, оферы, сычевание, лейоффы, дофаминовый крах).
4. ОБЪЕМ: Строго ОТ 2000 ДО 3200 СИМВОЛОВ! Это критично, чтобы весь дайджест поместился в ОДНО сообщение Telegram (лимит 4096). Не лей лишнюю воду, пиши плотно и едко.

ПРИМЕР ЖЕЛАЕМОЙ СТРУКТУРЫ И ТОНА:
🗓 **ДАЙДЖЕСТ за {$targetDate}**

Доброе утро, погромисты. За окном осень, в тредах — стабильное фиаско, а надежды на рынок труда сдувает прямо в мусорку. Погода в IT традиционно грозовая с переходом в депрессивную ясность.

В блоке трудоустроенных рабов разворачивается драма вокруг... [живой связный рассказ с цитатами "..." о темах дня в /twg/].

А на дне безработицы в это время... [связный рассказ о страданиях и кукоже в /utwg/].

Отдельный цирк устроили аноны, спорившие о... [самый яркий спор, шизотеория или забавный диалог]. Итог дня прост: ...
SYSTEM;

        $user = "Дата дайджеста: {$targetDate}\n\n";
        $user .= "==================== [ТРЕД 1: /twg/ Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($twgData);
        $user .= "\n\n";

        $user .= "==================== [ТРЕД 2: /utwg/ Unemployable Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($utwgData);
        $user .= "\n\n";

        $user .= "Напиши связную заметку в желтушной айти-газете по правилам из системного промпта. КАТЕГОРИЧЕСКИ БЕЗ НОМЕРОВ ПОСТОВ И ID! Только связные абзацы с сочными цитатами в кавычках.";

        return ['system' => $system, 'user' => $user];
    }

    private function formatThreadContent(array $data): string
    {
        $lines = [];

        if (!empty($data['context_posts'])) {
            $lines[] = "--- [Контекст диалогов] ---";
            $slicedContext = array_slice($data['context_posts'], 0, 5);
            foreach ($slicedContext as $cp) {
                $lines[] = sprintf(
                    "[Реплика анона]: %s",
                    $this->truncate($cp['text'], self::MAX_POST_CHARS)
                );
            }
            $lines[] = "--- [Вчерашние сообщения] ---";
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
            $lines[] = sprintf(
                "[Анон]: %s",
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
