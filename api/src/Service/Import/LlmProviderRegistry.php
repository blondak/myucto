<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * F7 §3.4 / §13 — mapuje `supplier.ai_provider` string → konkrétní klient
 * implementující {@see LlmGatewayInterface}.
 *
 * Provider set v1 = anthropic + azure_openai + openai + gemini (všechny zapojené).
 * Neznámý / prázdný provider → `provider_not_configured`.
 */
final class LlmProviderRegistry
{
    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly AzureOpenAiClient $azureOpenai,
        private readonly OpenAiClient $openai,
        private readonly GeminiClient $gemini,
    ) {}

    /**
     * @throws \RuntimeException 'provider_not_configured' pro neznámý provider
     */
    public function resolve(string $provider): LlmGatewayInterface
    {
        return match ($provider) {
            'anthropic'    => $this->anthropic,
            'azure_openai' => $this->azureOpenai,
            'openai'       => $this->openai,
            'gemini'       => $this->gemini,
            default        => throw new \RuntimeException('provider_not_configured'),
        };
    }
}
