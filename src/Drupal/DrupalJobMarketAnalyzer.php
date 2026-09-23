<?php

declare(strict_types=1);

namespace App\Drupal;

use App\Ai\AiClientInterface;
use JsonException;
use RuntimeException;

class DrupalJobMarketAnalyzer
{
    private AiClientInterface $aiClient;
    private DrupalPromptBuilder $promptBuilder;

    public function __construct(AiClientInterface $aiClient, ?DrupalPromptBuilder $promptBuilder = null)
    {
        $this->aiClient = $aiClient;
        $this->promptBuilder = $promptBuilder ?? new DrupalPromptBuilder();
    }

    /**
     * Analyze daily chat messages using AI to detect job/market discussions.
     *
     * @param string $targetDate YYYY-MM-DD
     * @param string $chatUsername e.g. 'drupal_rus'
     * @param array<int, array{
     *     id: int,
     *     date: string,
     *     sender_username: ?string,
     *     sender_name: string,
     *     text: string,
     *     reply_to_msg_id: ?int
     * }> $messages
     * @return array{
     *     has_job_discussion: bool,
     *     topics: array<string>,
     *     participants: array<string>,
     *     summary: string,
     *     post_text: string,
     *     reason?: string,
     *     raw_ai_response?: string
     * }
     */
    public function analyze(string $targetDate, string $chatUsername, array $messages): array
    {
        if (empty($messages)) {
            return [
                'has_job_discussion' => false,
                'topics' => [],
                'participants' => [],
                'summary' => 'В чате не было сообщений за указанный период.',
                'post_text' => '',
                'reason' => 'Сообщений за дату нет.',
            ];
        }

        $prompts = $this->promptBuilder->buildPrompts($targetDate, $chatUsername, $messages);

        $rawResponse = $this->aiClient->generateDigest($prompts['system'], $prompts['user']);

        return $this->parseAiResponse($rawResponse);
    }

    /**
     * Parse and validate AI JSON response.
     *
     * @param string $rawResponse
     * @return array{
     *     has_job_discussion: bool,
     *     topics: array<string>,
     *     participants: array<string>,
     *     summary: string,
     *     post_text: string,
     *     reason?: string,
     *     raw_ai_response?: string
     * }
     */
    public function parseAiResponse(string $rawResponse): array
    {
        $clean = trim($rawResponse);

        // Strip markdown code fences (```json ... ``` or ``` ...)
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        } elseif (preg_match('/\{[\s\S]*\}/', $clean, $matches)) {
            $clean = trim($matches[0]);
        }

        try {
            $data = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Не удалось распарсить JSON-ответ от ИИ: " . $e->getMessage() . "\nОтвет ИИ:\n" . $rawResponse,
                0,
                $e
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException("Ответ от ИИ не является JSON-объектом. Получено: " . $rawResponse);
        }

        $hasJobDiscussion = filter_var($data['has_job_discussion'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'has_job_discussion' => $hasJobDiscussion,
            'topics' => is_array($data['topics'] ?? null) ? $data['topics'] : [],
            'participants' => is_array($data['participants'] ?? null) ? $data['participants'] : [],
            'summary' => (string)($data['summary'] ?? ($data['reason'] ?? '')),
            'post_text' => (string)($data['post_text'] ?? ''),
            'reason' => (string)($data['reason'] ?? ''),
            'raw_ai_response' => $rawResponse,
        ];
    }
}
