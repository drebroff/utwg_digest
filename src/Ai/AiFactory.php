<?php

declare(strict_types=1);

namespace App\Ai;

use App\Config;
use RuntimeException;

class AiFactory
{
    public static function create(?Config $config = null): AiClientInterface
    {
        $config = $config ?? Config::getInstance();
        $provider = strtolower((string)$config->get('ai_provider', 'grok'));

        // xAI Grok
        if ($provider === 'grok' || $provider === 'xai') {
            $apiKey = (string)$config->get('grok_api_key', '');
            $model = (string)$config->get('grok_model', 'grok-beta');
            return new GrokClient($apiKey, $model);
        }

        // Groq Cloud (Llama)
        if ($provider === 'groq') {
            $apiKey = (string)$config->get('groq_api_key', '');
            $model = (string)$config->get('groq_model', 'llama-3.3-70b-versatile');
            return new GroqClient($apiKey, $model);
        }

        // Google Gemini
        if ($provider === 'gemini') {
            $apiKey = (string)$config->get('gemini_api_key', '');
            $model = (string)$config->get('gemini_model', 'gemini-2.0-flash');
            return new GeminiClient($apiKey, $model);
        }

        throw new RuntimeException("Unsupported AI provider: '{$provider}'. Supported: 'grok' (xAI), 'groq', 'gemini'.");
    }
}
