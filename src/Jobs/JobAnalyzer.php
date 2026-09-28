<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\AiClientInterface;
use JsonException;
use RuntimeException;

class JobAnalyzer
{
    private AiClientInterface $aiClient;
    private JobPromptBuilder $promptBuilder;

    public function __construct(AiClientInterface $aiClient, ?JobPromptBuilder $promptBuilder = null)
    {
        $this->aiClient = $aiClient;
        $this->promptBuilder = $promptBuilder ?? new JobPromptBuilder();
    }

    /**
     * Analyze candidate vacancies using AI to filter and build Telegram digest.
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
     * @return array{
     *     has_valid_jobs: bool,
     *     valid_count: int,
     *     summary: string,
     *     post_text: string,
     *     raw_ai_response?: string
     * }
     */
    public function analyze(string $targetDate, array $candidates): array
    {
        if (empty($candidates)) {
            return [
                'has_valid_jobs' => false,
                'valid_count' => 0,
                'summary' => 'Кандидатов для анализа не найдено.',
                'post_text' => '',
            ];
        }

        $prompts = $this->promptBuilder->buildPrompts($targetDate, $candidates);
        $rawResponse = $this->aiClient->generateDigest($prompts['system'], $prompts['user']);

        return $this->parseAiResponse($rawResponse);
    }

    /**
     * Parse and validate AI JSON response.
     *
     * @param string $rawResponse
     * @return array{
     *     has_valid_jobs: bool,
     *     valid_count: int,
     *     summary: string,
     *     post_text: string,
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

        $hasValidJobs = filter_var($data['has_valid_jobs'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validCount = (int)($data['valid_count'] ?? ($hasValidJobs ? 1 : 0));
        $summary = (string)($data['summary'] ?? '');
        $postText = (string)($data['post_text'] ?? '');

        return [
            'has_valid_jobs' => $hasValidJobs,
            'valid_count' => $validCount,
            'summary' => $summary,
            'post_text' => $postText,
            'raw_ai_response' => $rawResponse,
        ];
    }
}
