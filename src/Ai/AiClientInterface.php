<?php

declare(strict_types=1);

namespace App\Ai;

interface AiClientInterface
{
    /**
     * Generate digest text given system instructions and prompt context.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @return string
     * @throws \RuntimeException
     */
    public function generateDigest(string $systemPrompt, string $userPrompt): string;
}
