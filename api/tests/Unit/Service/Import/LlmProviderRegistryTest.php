<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizer;
use MyInvoice\Service\Import\PdfTotalExtractor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `LlmProviderRegistry::PROVIDERS` je jediný seznam providerů — allowlisty v akcích
 * i DPA brána z něj čtou. Test hlídá, že každý provider ze seznamu jde resolvovat
 * a že seznam sedí na DB ENUM `supplier.ai_provider` v otisku schématu.
 */
#[AllowMockObjectsWithoutExpectations]
final class LlmProviderRegistryTest extends TestCase
{
    private function registry(): LlmProviderRegistry
    {
        $conn   = $this->createMock(Connection::class);
        $crypto = $this->createMock(SecretEncryption::class);
        $logger = new NullLogger();
        return new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger),
            new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger),
            new GeminiClient($conn, $crypto, $logger),
            new OllamaClient($conn, $crypto, $logger, new OllamaEndpointGuard(static fn (): array => [], ''),
                new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor())),
        );
    }

    public function testEveryListedProviderResolves(): void
    {
        $registry = $this->registry();
        foreach (LlmProviderRegistry::PROVIDERS as $provider) {
            self::assertInstanceOf(LlmGatewayInterface::class, $registry->resolve($provider), $provider);
        }
        self::assertInstanceOf(OllamaClient::class, $registry->resolve('ollama'));
    }

    public function testUnknownProviderIsNotConfigured(): void
    {
        $this->expectExceptionMessage('provider_not_configured');
        $this->registry()->resolve('bogus');
    }

    public function testProvidersMatchDbEnum(): void
    {
        $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 5) . '/db/schema.snapshot.json'), true);
        $definition = (string) $snapshot['tables']['supplier']['columns']['ai_provider'];
        self::assertSame(1, preg_match("/^enum\\((.*)\\)/", $definition, $m), $definition);
        $enum = array_map(static fn (string $v): string => trim($v, "'"), explode(',', $m[1]));
        self::assertSame(LlmProviderRegistry::PROVIDERS, $enum);
    }
}
