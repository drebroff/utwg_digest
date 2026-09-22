<?php

declare(strict_types=1);

namespace App\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class GeminiClient implements AiClientInterface
{
    private const GEMINI_ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

    private Client $httpClient;
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-3.6-flash', ?Client $client = null)
    {
        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is required for Gemini client.');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->httpClient = $client ?? new Client([
            'timeout' => 120.0,
        ]);
    }

    public function generateDigest(string $systemPrompt, string $userPrompt): string
    {
        // Список моделей для попытки: сначала указанная, затем запасные пулы
        $candidateModels = array_values(array_unique([
            $this->model,
            'gemini-3.6-flash',
            'gemini-3.5-flash',
            'gemini-3-flash-preview',
        ]));

        $lastException = null;

        foreach ($candidateModels as $currentModel) {
            $endpoint = sprintf(self::GEMINI_ENDPOINT_TEMPLATE, urlencode($currentModel), urlencode($this->apiKey));
            $maxRetries = 3;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $response = $this->httpClient->post($endpoint, [
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                        'json' => [
                            'system_instruction' => [
                                'parts' => [
                                    ['text' => $systemPrompt],
                                ],
                            ],
                            'contents' => [
                                [
                                    'parts' => [
                                        ['text' => $userPrompt],
                                    ],
                                ],
                            ],
                            'generationConfig' => [
                                'temperature' => 0.75,
                                'maxOutputTokens' => 2500,
                                'thinkingConfig' => [
                                    'thinkingLevel' => 'minimal',
                                ],
                            ],
                        ],
                    ]);

                    $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    if (empty($text)) {
                        throw new RuntimeException("Empty response received from Gemini API ($currentModel).");
                    }

                    return trim($text);
                } catch (GuzzleException $e) {
                    $lastException = $e;
                    $statusCode = 0;
                    if (method_exists($e, 'hasResponse') && $e->hasResponse()) {
                        $statusCode = $e->getResponse()->getStatusCode();
                    } elseif ($e->getCode() > 0) {
                        $statusCode = (int)$e->getCode();
                    }

                    // 503: перегрузка модели на Free Tier, 429: рейт-лимит
                    if ($statusCode === 503 || $statusCode === 429) {
                        $waitSec = $attempt * 5;
                        echo "⚠️ Gemini API ($currentModel) вернул {$statusCode} (высокая нагрузка). Ожидание {$waitSec} сек (попытка {$attempt}/{$maxRetries})...\n";
                        sleep($waitSec);
                        continue;
                    }

                    // Если модель не существует или 404 — сразу переходим к следующей модели в цепочке
                    if ($statusCode === 404) {
                        echo "ℹ️ Модель $currentModel недоступна (404), переключаемся на следующую модель...\n";
                        break;
                    }

                    // Любая другая непредвиденная ошибка клиента
                    throw new RuntimeException("Gemini API ($currentModel) request failed: " . $e->getMessage(), 0, $e);
                } catch (\JsonException $e) {
                    throw new RuntimeException("Failed to parse Gemini API response: " . $e->getMessage(), 0, $e);
                }
            }

            echo "⚠️ Модель $currentModel исчерпала попытки. Переключаемся на резервную модель...\n";
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . ($lastException ? $lastException->getMessage() : 'unknown'));
    }
}
