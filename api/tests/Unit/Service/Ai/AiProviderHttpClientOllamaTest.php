<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Service\Ai\AiWorker;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizer;
use MyInvoice\Service\Import\PdfTotalExtractor;
use MyInvoice\Service\Import\ResidencyPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class AiProviderHttpClientOllamaTest extends TestCase
{
    /**
     * @param list<mixed> $queue
     * @param array<int, array<string,mixed>> $history
     */
    private function client(array $queue, array &$history = []): AiProviderHttpClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $ollamaHttp = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'ai_eu_residency_required')) {
                $s->method('fetch')->willReturn(['ai_provider' => 'ollama', 'ai_eu_residency_required' => 1]);
            } elseif (str_contains($sql, 'ollama_base_url')) {
                $s->method('fetch')->willReturn(['ollama_base_url' => 'http://127.0.0.1:11434', 'ollama_default_model' => 'vision-model:7b', 'ollama_api_key_enc' => null]);
            } else {
                $s->method('fetch')->willReturn(false);
                $s->method('fetchColumn')->willReturn('{}');
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $this->createMock(SecretEncryption::class);
        $logger = new NullLogger();
        $ollama = new OllamaClient($conn, $crypto, $logger, new OllamaEndpointGuard(null, ''),
            new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor()), $ollamaHttp, true);
        $registry = new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger), new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger), new GeminiClient($conn, $crypto, $logger), $ollama,
        );
        return new AiProviderHttpClient($conn, $registry, new ResidencyPolicy(), new AiDpaGate($conn, $ollama), $logger);
    }

    private static function workerTreatsAsTransient(string $error): bool
    {
        $worker = (new \ReflectionClass(AiWorker::class))->newInstanceWithoutConstructor();
        return (bool) (new \ReflectionMethod(AiWorker::class, 'transient'))->invoke($worker, $error);
    }

    public function testCompleteJsonRoutesToOllama(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"capabilities":["completion"]}'),
            new Response(200, [], '{"model":"vision-model:7b","message":{"content":"{\"account\":\"518\"}"},"prompt_eval_count":10,"eval_count":3}'),
        ], $history);

        $r = $client->completeJson(1, 'SYS', 'USER', ['type' => 'object'], null, 300);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(['account' => '518'], $r['data']);
        self::assertSame('ollama', $r['provider']);
        self::assertSame('eu', $r['region'], 'EU-required tenant projde jen díky lokální adrese');
        self::assertSame('http://127.0.0.1:11434/api/chat', (string) $history[1]['request']->getUri());
    }

    /** Uspaná GPU stanice: job se má zopakovat, ne trvale selhat na prvním pokusu. */
    public function testUnreachableOllamaIsTransientForWorker(): void
    {
        $r = $this->client([
            new ConnectException('Failed to connect to 127.0.0.1 port 11434', new Request('POST', 'http://127.0.0.1:11434/api/show')),
        ])->completeJson(1, 'SYS', 'USER', ['type' => 'object'], null, 300);

        self::assertFalse($r['ok']);
        self::assertSame('provider_transport_error', $r['error']);
        self::assertTrue(self::workerTreatsAsTransient($r['error']));
    }

    public function testOllamaTimeoutIsTransientForWorker(): void
    {
        $r = $this->client([
            new Response(200, [], '{"capabilities":["completion"]}'),
            new ConnectException('Operation timed out after 110000 milliseconds', new Request('POST', 'http://127.0.0.1:11434/api/chat'), null, ['errno' => 28]),
        ])->completeJson(1, 'SYS', 'USER', ['type' => 'object'], null, 300);

        self::assertSame('provider_transport_error', $r['error']);
        self::assertTrue(self::workerTreatsAsTransient($r['error']));
    }

    public function testOllamaServerErrorIsTransientButClientErrorIsNot(): void
    {
        $r = $this->client([
            new Response(200, [], '{"capabilities":["completion"]}'),
            new Response(503, [], '{"error":"model is loading"}'),
        ])->completeJson(1, 'SYS', 'USER', ['type' => 'object'], null, 300);
        self::assertSame('provider_http_503', $r['error']);
        self::assertTrue(self::workerTreatsAsTransient($r['error']));

        $r = $this->client([
            new Response(200, [], '{"capabilities":["completion"]}'),
            new Response(400, [], '{"error":"invalid format"}'),
        ])->completeJson(1, 'SYS', 'USER', ['type' => 'object'], null, 300);
        self::assertSame('provider_http_400', $r['error']);
        self::assertFalse(self::workerTreatsAsTransient($r['error']));
    }
}
