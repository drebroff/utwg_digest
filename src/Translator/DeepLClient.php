<?php

declare(strict_types=1);

namespace App\Translator;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class DeepLClient
{
    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    private Client $httpClient;
    private string $apiKey;
    private string $endpoint;

    public function __construct(string $apiKey, bool $isPro = false, ?Client $client = null)
    {
        if (empty($apiKey)) {
            throw new RuntimeException('DEEPL_API_KEY is required for DeepLClient.');
        }

        $this->apiKey = $apiKey;
        // Free keys usually end with ':fx'
        $this->endpoint = ($isPro || !str_ends_with($apiKey, ':fx')) && $isPro
            ? self::PRO_ENDPOINT
            : self::FREE_ENDPOINT;

        $this->httpClient = $client ?? new Client(['timeout' => 30.0]);
    }

    /**
     * Translate text to target language (default: RU).
     *
     * @param string $text
     * @param string $targetLang
     * @param string|null $sourceLang
     * @return string
     * @throws RuntimeException
     */
    public function translate(string $text, string $targetLang = 'RU', ?string $sourceLang = 'EN'): string
    {
        if (empty(trim($text))) {
            return '';
        }

        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLang),
        ];

        if ($sourceLang !== null) {
            $payload['source_lang'] = strtoupper($sourceLang);
        }

        try {
            $response = $this->httpClient->post($this->endpoint, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $translated = $body['translations'][0]['text'] ?? '';
            if (empty($translated)) {
                throw new RuntimeException('Empty translation received from DeepL.');
            }

            return $translated;
        } catch (GuzzleException $e) {
            throw new RuntimeException('DeepL API request failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse DeepL API response: ' . $e->getMessage(), 0, $e);
        }
    }
}
