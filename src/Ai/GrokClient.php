<?php

declare(strict_types=1);

namespace App\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class GrokClient implements AiClientInterface
{
    private const XAI_ENDPOINT = 'https://api.x.ai/v1/chat/completions';

    private Client $httpClient;
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'grok-beta', ?Client $client = null)
    {
        if (empty($apiKey)) {
            throw new RuntimeException('GROK_API_KEY (xAI) is required for GrokClient.');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->httpClient = $client ?? new Client([
            'timeout' => 90.0,
        ]);
    }

    public function generateDigest(string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->httpClient->post(self::XAI_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 2048,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $text = $body['choices'][0]['message']['content'] ?? '';
            if (empty($text)) {
                throw new RuntimeException('Empty response received from xAI Grok API.');
            }

            return trim($text);
        } catch (GuzzleException $e) {
            throw new RuntimeException('xAI Grok API request failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse xAI Grok API response: ' . $e->getMessage(), 0, $e);
        }
    }
}
