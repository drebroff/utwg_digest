<?php

declare(strict_types=1);

namespace App\Translation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class DeepLClient
{
    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    private string $apiKey;
    private string $endpoint;
    private Client $httpClient;

    public function __construct(string $apiKey, ?string $endpoint = null, ?Client $client = null)
    {
        $cleanKey = trim($apiKey);
        if ($cleanKey === '') {
            throw new RuntimeException('DEEPL_API_KEY is required for DeepLClient.');
        }

        $this->apiKey = $cleanKey;
        $this->endpoint = $endpoint ?? $this->resolveEndpoint($cleanKey);
        $this->httpClient = $client ?? new Client([
            'timeout' => 60.0,
        ]);
    }

    /**
     * Translates text into target language using DeepL API v2.
     *
     * @param string $text Content to translate
     * @param string $targetLang Target language code (e.g. 'RU')
     * @param string|null $sourceLang Source language code (e.g. 'EN')
     * @return string Translated text
     * @throws RuntimeException
     */
    public function translate(string $text, string $targetLang = 'RU', ?string $sourceLang = 'EN'): string
    {
        if (trim($text) === '') {
            return '';
        }

        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLang),
            'preserve_formatting' => true,
        ];

        if (!empty($sourceLang)) {
            $payload['source_lang'] = strtoupper($sourceLang);
        }

        try {
            $response = $this->httpClient->post($this->endpoint, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'User-Agent' => '4chan-Digest-Bot/1.0',
                ],
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $translated = $body['translations'][0]['text'] ?? '';
            if ($translated === '') {
                throw new RuntimeException('Empty translation received from DeepL API.');
            }

            return trim($translated);
        } catch (GuzzleException $e) {
            throw new RuntimeException('DeepL API request failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse DeepL API response: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    private function resolveEndpoint(string $key): string
    {
        return str_ends_with(strtolower($key), ':fx') ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
    }
}
