<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizer;
use MyInvoice\Service\Import\PdfTotalExtractor;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class AiDpaGateOllamaTest extends TestCase
{
    /** @param list<string> $ips */
    private function gate(array $ips, string $confirmations = '{}'): AiDpaGate
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($confirmations) {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'ai_dpa_confirmations')) {
                $s->method('fetchColumn')->willReturn($confirmations);
            } else {
                $s->method('fetch')->willReturn(['ollama_base_url' => 'http://ollama.lan:11434', 'ollama_default_model' => 'vision-model:7b']);
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $logger = new NullLogger();
        $ollama = new OllamaClient($conn, $this->createMock(SecretEncryption::class), $logger,
            new OllamaEndpointGuard(static fn (): array => $ips, ''),
            new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor()));
        return new AiDpaGate($conn, $ollama);
    }

    public function testLocalOllamaIsExemptAndConfirmed(): void
    {
        $gate = $this->gate(['192.168.1.50']);
        self::assertTrue($gate->isExempt(1, 'ollama'));
        self::assertTrue($gate->isConfirmed(1, 'ollama'));
    }

    public function testRemoteOllamaNeedsConfirmation(): void
    {
        $gate = $this->gate(['203.0.113.7']);
        self::assertFalse($gate->isExempt(1, 'ollama'));
        self::assertFalse($gate->isConfirmed(1, 'ollama'));
        self::assertTrue($this->gate(['203.0.113.7'], '{"ollama":{"confirmed_at":"2026-10-03T10:00:00+00:00"}}')->isConfirmed(1, 'ollama'));
    }

    public function testCloudProvidersAreNeverExempt(): void
    {
        $gate = $this->gate(['192.168.1.50']);
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini'] as $p) {
            self::assertFalse($gate->isExempt(1, $p), $p);
        }
        self::assertArrayHasKey('ollama', $gate->confirmations(1));
    }

    /** Volitelný parametr by PHP-DI autowiring nevyplnil a výjimka by v aplikaci tiše zmizela. */
    public function testOllamaClientIsRequiredConstructorDependency(): void
    {
        $param = (new \ReflectionMethod(AiDpaGate::class, '__construct'))->getParameters()[1];
        self::assertSame('ollama', $param->getName());
        self::assertFalse($param->isOptional());
        self::assertFalse($param->allowsNull());
    }
}
