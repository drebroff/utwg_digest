<?php

declare(strict_types=1);

namespace App\Ai;

class PromptBuilder
{
    private const MAX_POSTS_PER_THREAD = 35;
    private const MAX_POST_CHARS = 450;

    /**
     * Builds system and user prompt for LLM to produce a dry, structured factual analytical digest.
     * Extracts meaningful discussions with an overview of sentiments and trends without post IDs.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param array $twgData Parsed data for /twg/
     * @param array $utwgData Parsed data for /utwg/
     * @return array{system: string, user: string}
     */
    public function buildPrompts(string $targetDate, array $twgData, array $utwgData): array
    {
        $system = <<<SYSTEM
You are an objective tech analyst. Your task is to provide a clear, dry, structured, and factual summary of yesterday's meaningful discussions in two 4chan /g/ threads:
1. /twg/ (Tech Workers General) — employed tech workers discussing workplaces, management, code, career progression, and compensation.
2. /utwg/ (Unemployable Tech Workers General) — job seekers and unemployed individuals discussing hiring market conditions, interviews, resumes, and career pivots.

CRITICAL RULES:
1. TONE — DRY, OBJECTIVE & FACTUAL: No tabloid sensationalism, no yellow-press theatrics, no sarcasm, and no editorial bias. Focus strictly on substantive arguments, concrete topics, and factual discussion points.
2. NO POST NUMBERS OR POST IDs: Absolutely no post numbers like #109872859, no "post 123", no IDs. Refer to participants neutrally: "one developer", "a senior engineer", "a job applicant", "several participants", "one commenter".
3. STRUCTURE:
   - Start with a clean header: 🗓 **DAILY DIGEST for {$targetDate}**
   - Introduce the main meaningful discussions identified (typically 3 to 4 core topics across the threads).
   - Detail each topic:
     **1. [Topic Title]**
     Summary of what was discussed, the conflicting viewpoints, arguments, and notable observations.
     **2. [Topic Title]**
     ...
     **3. [Topic Title]**
     ...
   - Conclude with a clear summary section:
     **Summary: Overall Sentiment & Key Trends**
     - Workplace / Employee sentiment: ...
     - Job Market / Hiring trends: ...
4. LENGTH: Strictly BETWEEN 1500 AND 2300 CHARACTERS! This is critical because translation into Russian expands the text by 15-20%, and the final translated text MUST fit within a single Telegram message (under 3800 characters). Be concise, dense, and avoid fluff.
5. LANGUAGE: Write completely in ENGLISH.

DESIRED STRUCTURE EXAMPLE:
🗓 **DAILY DIGEST for {$targetDate}**

Across yesterday's /twg/ and /utwg/ threads, several meaningful discussions took place:

**1. [First Discussion Topic]**
Participants discussed... One engineer pointed out that... Others noted that...

**2. [Second Discussion Topic]**
The focus was on... Key arguments included...

**3. [Third Discussion Topic]**
Commenters debated... The consensus appeared to be...

**Summary: Overall Sentiment & Trends**
- **Employed Tech Workers (/twg/):** [Summary of sentiment, concerns, and workplace issues]
- **Job Seekers & Market (/utwg/):** [Summary of hiring dynamics, search difficulties, and emerging trends]
SYSTEM;

        $user = "Digest date: {$targetDate}\n\n";
        $user .= "==================== [THREAD 1: /twg/ Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($twgData);
        $user .= "\n\n";

        $user .= "==================== [THREAD 2: /utwg/ Unemployable Tech Workers General] ====================\n";
        $user .= $this->formatThreadContent($utwgData);
        $user .= "\n\n";

        $user .= "Provide a dry, structured, and factual analytical summary of yesterday's meaningful discussions following the rules in the system prompt. Group by key discussions, followed by an overall sentiment and trends summary. STRICTLY NO POST NUMBERS OR IDs! Write entirely in ENGLISH.";

        return ['system' => $system, 'user' => $user];
    }

    private function formatThreadContent(array $data): string
    {
        $lines = [];

        if (!empty($data['context_posts'])) {
            $lines[] = "--- [Dialogue Context] ---";
            $slicedContext = array_slice($data['context_posts'], 0, 5);
            foreach ($slicedContext as $cp) {
                $lines[] = sprintf(
                    "[Anon reply]: %s",
                    $this->truncate($cp['text'], self::MAX_POST_CHARS)
                );
            }
            $lines[] = "--- [Yesterday's Posts] ---";
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
                "[Anon]: %s",
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
