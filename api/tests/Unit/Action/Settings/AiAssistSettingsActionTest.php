<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\AiAssistSettingsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiJobService;
use MyInvoice\Service\Ai\AiKillSwitchService;
use MyInvoice\Service\Ai\EmbeddingGatewayInterface;
use MyInvoice\Service\Ai\KnnSuggester;
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
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Výjimka z DPA pro lokální Ollamu se počítá přes DNS a databázi. Když selže, nastavení
 * AI asistence nesmí spadnout na 500 — platí fail-closed: DPA je potřeba.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiAssistSettingsActionTest extends TestCase
{
    /** @var list<string> */
    private array $sqlLog = [];

    private function action(): AiAssistSettingsAction
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $this->sqlLog[] = $sql;
            $s = $this->createMock(PDOStatement::class);
            if (str_contains($sql, 'ollama_base_url')) {
                // Selhání výpočtu výjimky (OllamaClient::capabilities).
                $s->method('execute')->willThrowException(new \PDOException('simulated failure'));
                return $s;
            }
            $s->method('execute')->willReturn(true);
            if (str_contains($sql, 'FOR UPDATE')) {
                $s->method('fetch')->willReturn([
                    'ai_provider' => 'ollama', 'ai_assist_enabled' => 0, 'ai_assist_scope' => 'bank_tx',
                    'ai_pseudo_salt' => null, 'ai_dpa_confirmations' => null,
                ]);
            } elseif (str_contains($sql, 'ai_data_region')) {
                $s->method('fetch')->willReturn([
                    'ai_assist_enabled' => 0, 'ai_assist_scope' => 'bank_tx', 'ai_provider' => 'ollama', 'ai_data_region' => 'eu',
                ]);
            } elseif (str_contains($sql, 'ai_dpa_confirmations')) {
                $s->method('fetchColumn')->willReturn('{}');
            } elseif (str_contains($sql, 'ai_provider')) {
                $s->method('fetch')->willReturn(['ai_provider' => 'ollama']);
                $s->method('fetchColumn')->willReturn('ollama');
            } else {
                $s->method('fetch')->willReturn(false);
                $s->method('fetchColumn')->willReturn(0);
                $s->method('fetchAll')->willReturn([]);
            }
            return $s;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $this->createMock(SecretEncryption::class);
        $logger = new NullLogger();
        $ollama = new OllamaClient($conn, $crypto, $logger, new OllamaEndpointGuard(static fn (): array => ['127.0.0.1'], ''),
            new PdfPageRasterizer($logger, false, ''), new PdfTotalExtractor(new PdfIsdocExtractor()));
        $registry = new LlmProviderRegistry(
            new AnthropicClient($conn, $crypto, $logger), new AzureOpenAiClient($conn, $crypto, $logger),
            new OpenAiClient($conn, $crypto, $logger), new GeminiClient($conn, $crypto, $logger), $ollama,
        );
        $embeddings = $this->createMock(EmbeddingGatewayInterface::class);
        $embeddings->method('isAvailable')->willReturn(false);

        return new AiAssistSettingsAction($conn, $registry, $embeddings, new KnnSuggester($conn),
            new AiDpaGate($conn, $ollama), new AiKillSwitchService($conn, new ActivityLogger($conn)), new AiJobService($conn));
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/settings/ai-assist')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'admin']);
    }

    public function testFailingExemptionCheckRequiresDpaInsteadOf500(): void
    {
        $response = $this->action()->put($this->request('PUT', ['enabled' => true]), new Response());

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('dpa_required', (string) $response->getBody());
    }

    public function testExemptionIsCheckedOutsideTheRowLock(): void
    {
        $this->action()->put($this->request('PUT', ['enabled' => true]), new Response());

        $lock = null;
        $exempt = null;
        foreach ($this->sqlLog as $i => $sql) {
            if ($lock === null && str_contains($sql, 'FOR UPDATE')) {
                $lock = $i;
            }
            if ($exempt === null && str_contains($sql, 'ollama_base_url')) {
                $exempt = $i;
            }
        }
        self::assertNotNull($lock);
        self::assertNotNull($exempt);
        self::assertLessThan($lock, $exempt, 'DNS/DB dotaz výjimky nesmí běžet uvnitř SELECT … FOR UPDATE');
    }

    public function testFailingExemptionCheckReportsNotExemptInState(): void
    {
        $response = $this->action()->get($this->request('GET'), new Response());

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        $data = $json['data'] ?? $json;
        self::assertFalse($data['dpa_exempt']);
    }

    /** Každé pole odpovědi musí být popsané ve schématu AiAssistSettings v openapi.yaml. */
    public function testStateFieldsAreDocumentedInOpenApi(): void
    {
        $data = json_decode((string) $this->action()->get($this->request('GET'), new Response())->getBody(), true);
        $lines = preg_split('/\R/', (string) file_get_contents(dirname(__DIR__, 5) . '/api/openapi.yaml')) ?: [];
        $documented = [];
        $inSchema = false;
        foreach ($lines as $line) {
            if (rtrim($line) === '    AiAssistSettings:') {
                $inSchema = true;
                continue;
            }
            if ($inSchema && preg_match('/^    \S/', $line) === 1) {
                break;
            }
            if ($inSchema && preg_match('/^        (\w+):/', $line, $m) === 1) {
                $documented[] = $m[1];
            }
        }
        self::assertNotEmpty($documented, 'Schéma AiAssistSettings se ve spec nenašlo.');
        self::assertSame([], array_values(array_diff(array_keys($data), $documented)));
    }
}
