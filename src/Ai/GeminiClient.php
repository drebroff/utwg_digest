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

    public function __construct(string $apiKey, string $model = 'gemini-2.0-flash', ?Client $client = null)
    {
        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is required for Gemini client.');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->httpClient = $client ?? new Client([
            'timeout' => 60.0,
        ]);
    }

    public function generateDigest(string $systemPrompt, string $userPrompt): string
    {
        $endpoint = sprintf(self::GEMINI_ENDPOINT_TEMPLATE, urlencode($this->model), urlencode($this->apiKey));

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
                        'temperature' => 0.7,
                        'maxOutputTokens' => 2048,
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (empty($text)) {
                throw new RuntimeException('Empty response received from Gemini API.');
            }

            return trim($text);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gemini API request failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse Gemini API response: ' . $e->getMessage(), 0, $e);
        }
    }
}
