<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * F7 §3.4 / §13 — mapuje `supplier.ai_provider` string → konkrétní klient
 * implementující {@see LlmGatewayInterface}.
 *
 * Provider set = {@see PROVIDERS}.
 * Neznámý / prázdný provider → `provider_not_configured`.
 */
final class LlmProviderRegistry
{
    /**
     * Jediný seznam podporovaných providerů. Allowlisty v akcích (výběr providera,
     * credentials, DPA) z něj čtou; pořadí odpovídá DB ENUM `supplier.ai_provider`
     * (hlídá LlmProviderRegistryTest).
     */
    public const PROVIDERS = ['anthropic', 'azure_openai', 'openai', 'gemini', 'ollama'];

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly AzureOpenAiClient $azureOpenai,
        private readonly OpenAiClient $openai,
        private readonly GeminiClient $gemini,
        private readonly OllamaClient $ollama,
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
            'ollama'       => $this->ollama,
            default        => throw new \RuntimeException('provider_not_configured'),
        };
    }
}
