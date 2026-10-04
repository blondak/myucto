<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use MyInvoice\Service\Import\OllamaChatRequest;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfPageRasterizerInterface;
use MyInvoice\Service\Import\PdfTotalExtractor;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class OllamaClientTest extends TestCase
{
    private const BASE = 'http://ollama.lan:11434';
    private const MODEL = 'vision-model:7b';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];
    /** @var list<array{sql:string, params:array<mixed>}> */
    private array $writes = [];
    private int $rasterizeCalls = 0;

    private static ?string $textPdf = null;

    private static function textPdf(): string
    {
        if (self::$textPdf === null) {
            $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
            $mpdf->WriteHTML('<p>Faktura FV-2026-001, variabilní symbol 2026001, celkem 2 420,00 Kč</p>');
            self::$textPdf = (string) $mpdf->Output('', Destination::STRING_RETURN);
        }
        return self::$textPdf;
    }

    private static function scanPdf(): string
    {
        return "%PDF-1.4\nnení textová vrstva";
    }

    /** @return array<string,mixed> */
    private static function golden(): array
    {
        return ['vendor' => ['company_name' => 'ACME s.r.o.', 'ic' => '12345678'], 'total_with_vat' => 2420];
    }

    /** @param list<string> $caps */
    private static function show(array $caps): Response
    {
        return new Response(200, [], (string) json_encode(['capabilities' => $caps]));
    }

    /** @param array<string,mixed> $message */
    private static function chat(array $message, string $model = self::MODEL): Response
    {
        return new Response(200, [], (string) json_encode([
            'model' => $model, 'message' => ['role' => 'assistant'] + $message, 'done' => true,
            'prompt_eval_count' => 1200, 'eval_count' => 85,
        ]));
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<string,mixed>|null  $row  null = výchozí nastavená firma
     * @param list<string>              $ips
     * @param list<string>              $pages
     */
    private function client(
        array $responses,
        ?array $row = null,
        string $effort = 'default',
        array $ips = ['192.168.1.50'],
        array $pages = ['PNG1'],
        string $decryptedKey = '',
        ?callable $resolver = null,
        ?bool $curl = null,
        ?callable $handler = null,
        ?\Closure $clock = null,
        ?\Closure $onRasterize = null,
    ): OllamaClient {
        $row ??= ['ollama_base_url' => self::BASE, 'ollama_default_model' => self::MODEL, 'ollama_api_key_enc' => null];
        $stack = HandlerStack::create($handler ?? new MockHandler($responses));
        $this->history = [];
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($row, $effort) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params = []) use ($sql) {
                if (str_starts_with(ltrim($sql), 'UPDATE')) {
                    $this->writes[] = ['sql' => $sql, 'params' => $params];
                }
                return true;
            });
            if (str_contains($sql, 'ollama_base_url')) {
                $stmt->method('fetch')->willReturn($row);
            } elseif (str_contains($sql, 'ai_effort')) {
                $stmt->method('fetchColumn')->willReturn($effort);
            } else {
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn($decryptedKey);
        $crypto->method('encrypt')->willReturnCallback(static fn (string $v): string => 'ENC(' . $v . ')');

        $this->rasterizeCalls = 0;
        $rasterizer = new class ($pages, $this, $onRasterize) implements PdfPageRasterizerInterface {
            /** @param list<string> $pages */
            public function __construct(private array $pages, private OllamaClientTest $test, private ?\Closure $onRasterize) {}
            public function rasterize(string $pdfBytes, int $maxPages): array
            {
                $this->test->countRasterize();
                if ($this->onRasterize !== null) {
                    ($this->onRasterize)();
                }
                return $this->pages;
            }
        };

        return new OllamaClient(
            $conn, $crypto, new NullLogger(),
            new OllamaEndpointGuard($resolver ?? static fn (string $h): array => $ips, ''),
            $rasterizer,
            new PdfTotalExtractor(new PdfIsdocExtractor()),
            $http,
            $curl,
            $clock,
        );
    }

    public function countRasterize(): void
    {
        $this->rasterizeCalls++;
    }

    /** @return array<string,mixed> */
    private function payload(int $i): array
    {
        return (array) json_decode((string) $this->history[$i]['request']->getBody(), true);
    }

    private function counterWrites(): int
    {
        return count(array_filter($this->writes, static fn (array $w): bool => str_contains($w['sql'], 'ollama_extractions_count')));
    }

    // ── extrakce ─────────────────────────────────────────────────────────────

    public function testExtractInvoice_hybridPayload(): void
    {
        $c = $this->client([
            self::show(['completion', 'vision', 'thinking']),
            self::chat(['content' => (string) json_encode(self::golden())]),
        ], effort: 'fast');

        $r = $c->extractInvoice(1, self::textPdf());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(self::golden(), $r['data']);
        self::assertSame(['input_tokens' => 1200, 'output_tokens' => 85], $r['usage']);
        self::assertCount(2, $this->history);
        self::assertSame(self::BASE . '/api/show', (string) $this->history[0]['request']->getUri());
        self::assertSame(self::BASE . '/api/chat', (string) $this->history[1]['request']->getUri());
        $p = $this->payload(1);
        self::assertSame(self::MODEL, $p['model']);
        self::assertFalse($p['stream']);
        self::assertFalse($p['think'], 'fast = think:false u modelu s capability thinking');
        self::assertSame(OllamaChatRequest::NUM_CTX, $p['options']['num_ctx']);
        self::assertSame(InvoiceExtractionPrompt::invoiceJsonSchema(), $p['format']);
        self::assertSame([base64_encode('PNG1')], $p['messages'][1]['images']);
        self::assertStringContainsString(OllamaChatRequest::TEXT_HEADER, $p['messages'][1]['content']);
        self::assertStringContainsString('2026001', $p['messages'][1]['content']);
        self::assertSame(1, $this->counterWrites());
    }

    public function testNumCtxFromEnvReachesBothChatPaths(): void
    {
        putenv(OllamaClient::ENV_NUM_CTX . '=16384');
        try {
            $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
            self::assertTrue($c->extractInvoice(1, self::textPdf())['ok']);
            self::assertSame(16384, $this->payload(1)['options']['num_ctx']);

            $c = $this->client([self::show(['completion']), self::chat(['content' => '{"account":"518"}'])]);
            self::assertTrue($c->completeJson(1, self::MODEL, 'S', 'U', ['type' => 'object'], 100)['ok']);
            self::assertSame(16384, $this->payload(1)['options']['num_ctx']);
        } finally {
            putenv(OllamaClient::ENV_NUM_CTX);
        }
    }

    public function testNumCtxIsClampedAndFallsBackToDefault(): void
    {
        try {
            putenv(OllamaClient::ENV_NUM_CTX . '=1000');
            self::assertSame(OllamaClient::MIN_NUM_CTX, OllamaClient::numCtx());
            putenv(OllamaClient::ENV_NUM_CTX . '=999999999');
            self::assertSame(OllamaClient::MAX_NUM_CTX, OllamaClient::numCtx());
            putenv(OllamaClient::ENV_NUM_CTX . '=nesmysl');
            self::assertSame(OllamaChatRequest::NUM_CTX, OllamaClient::numCtx());
            putenv(OllamaClient::ENV_NUM_CTX);
            self::assertSame(OllamaChatRequest::NUM_CTX, OllamaClient::numCtx());
        } finally {
            putenv(OllamaClient::ENV_NUM_CTX);
        }
    }

    public function testNonVisionModel_textOnlyAndNoThink(): void
    {
        $c = $this->client([self::show(['completion']), self::chat(['content' => '{"total_with_vat":1}'])], effort: 'accurate');
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertTrue($r['ok']);
        self::assertSame(0, $this->rasterizeCalls, 'model bez vision se nerasterizuje');
        $p = $this->payload(1);
        self::assertArrayNotHasKey('images', $p['messages'][1]);
        self::assertArrayNotHasKey('think', $p, 'think jen pro model s capability thinking');
    }

    public function testOllamaWithoutCapabilities_sendsImagesWithoutThink(): void
    {
        $c = $this->client([new Response(200, [], '{"details":{}}'), self::chat(['content' => '{"a":1}'])], effort: 'accurate');
        self::assertTrue($c->extractInvoice(1, self::scanPdf())['ok']);
        $p = $this->payload(1);
        self::assertSame([base64_encode('PNG1')], $p['messages'][1]['images']);
        self::assertArrayNotHasKey('think', $p);
    }

    public function testNoImagesAndNoTextFailsBeforeChat(): void
    {
        $c = $this->client([self::show(['completion', 'vision'])], pages: []);
        $r = $c->extractInvoice(1, self::scanPdf());
        self::assertFalse($r['ok']);
        self::assertSame('ollama_no_input', $r['code']);
        self::assertStringContainsString('(ollama_no_input)', $r['error']);
        self::assertCount(1, $this->history, 'na /api/chat se nesmí jít');
    }

    public function testModelMissing(): void
    {
        $c = $this->client([new Response(404, [], '{"error":"model \'vision-model:7b\' not found"}')]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertSame('ollama_model_missing', $r['code']);
        self::assertSame(0, $this->counterWrites());
    }

    public function testRedirectIsNotFollowed(): void
    {
        $c = $this->client([
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], '{"capabilities":["vision"]}'),
        ]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertFalse($r['ok']);
        self::assertSame('ollama_not_ollama', $r['code']);
        self::assertCount(1, $this->history, 'přesměrování se nesmí následovat');
    }

    public function testTimeout(): void
    {
        $c = $this->client([new ConnectException('cURL error 28: Operation timed out', new Request('POST', self::BASE), null, ['errno' => 28])]);
        self::assertSame('ollama_timeout', $c->extractInvoice(1, self::textPdf())['code']);
    }

    /**
     * cURL hlásí vypršení PŘIPOJENÍ stejným kódem 28 jako vypršení odpovědi. Nedostupná
     * adresa (firewall, vypnutý stroj) ale není pomalý model — rada „pomůže GPU / vyšší
     * timeout" by uživatele poslala špatným směrem.
     */
    public function testConnectTimeoutIsUnreachableNotSlowModel(): void
    {
        $c = $this->client([new ConnectException('cURL error 28: Connection timed out after 5001 milliseconds', new Request('POST', self::BASE), null, ['errno' => 28])]);
        self::assertSame('ollama_unreachable', $c->extractInvoice(1, self::textPdf())['code']);

        $c = $this->client([new ConnectException('cURL error 28: Resolving timed out after 5000 milliseconds', new Request('GET', self::BASE), null, ['errno' => 28])]);
        self::assertSame('ollama_unreachable', $c->listModels(self::BASE)['code']);

        $c = $this->client([new ConnectException('cURL error 28: Operation timed out after 110001 milliseconds with 0 bytes received', new Request('POST', self::BASE), null, ['errno' => 28])]);
        self::assertSame('ollama_timeout', $c->extractInvoice(1, self::textPdf())['code'], 'vypršení odpovědi zůstává timeout');
    }

    public function testUnreachable(): void
    {
        $c = $this->client([new ConnectException('cURL error 7: Failed to connect', new Request('POST', self::BASE), null, ['errno' => 7])]);
        self::assertSame('ollama_unreachable', $c->extractInvoice(1, self::textPdf())['code']);
    }

    public function testHttpErrorBodyNeverLeaks(): void
    {
        $c = $this->client([self::show(['vision']), new Response(500, [], '{"error":"SECRET-INTERNAL-TOKEN"}')]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertSame('ollama_http_error', $r['code']);
        self::assertStringNotContainsString('SECRET-INTERNAL-TOKEN', (string) json_encode($r));
    }

    public function testNotOllamaShape(): void
    {
        $c = $this->client([self::show(['vision']), new Response(200, [], '{"foo":1}')]);
        self::assertSame('ollama_not_ollama', $c->extractInvoice(1, self::textPdf())['code']);
    }

    public function testFencedJsonFromThinkingModel(): void
    {
        $c = $this->client([
            self::show(['vision', 'thinking']),
            self::chat(['thinking' => 'Nejdřív najdu dodavatele…', 'content' => "```json\n" . json_encode(self::golden()) . "\n```"]),
        ]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertTrue($r['ok']);
        self::assertSame(self::golden(), $r['data']);
    }

    public function testInvalidJsonIsReported(): void
    {
        $c = $this->client([self::show(['vision']), self::chat(['content' => 'tohle není JSON'])]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertFalse($r['ok']);
        self::assertStringContainsString('invalid JSON', $r['error']);
        self::assertSame(0, $this->counterWrites());
    }

    public function testNotConfigured(): void
    {
        $c = $this->client([], row: ['ollama_base_url' => self::BASE, 'ollama_default_model' => null, 'ollama_api_key_enc' => null]);
        self::assertSame('provider_not_configured', $c->extractInvoice(1, self::textPdf())['code']);
        self::assertCount(0, $this->history);
    }

    public function testBlockedEndpointMakesNoRequest(): void
    {
        $c = $this->client([], ips: ['169.254.169.254']);
        self::assertSame('ollama_endpoint_blocked', $c->extractInvoice(1, self::textPdf())['code']);
        self::assertCount(0, $this->history);
    }

    public function testApiKeySentAsBearerOnlyWhenSet(): void
    {
        $withKey = $this->client(
            [self::show(['vision']), self::chat(['content' => '{"a":1}'])],
            row: ['ollama_base_url' => self::BASE, 'ollama_default_model' => self::MODEL, 'ollama_api_key_enc' => 'ENC'],
            decryptedKey: 'tok-123',
        );
        $withKey->extractInvoice(1, self::textPdf());
        self::assertSame('Bearer tok-123', $this->history[1]['request']->getHeaderLine('Authorization'));

        $without = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
        $without->extractInvoice(1, self::textPdf());
        self::assertFalse($this->history[1]['request']->hasHeader('Authorization'));
    }

    public function testOtherExtractions(): void
    {
        $total = $this->client([self::show(['vision']), self::chat(['content' => '{"total_with_vat": 2420.5}'])]);
        $r = $total->extractPdfTotal(1, self::textPdf());
        self::assertTrue($r['ok']);
        self::assertSame(2420.5, $r['total']);
        self::assertSame('json', $this->payload(1)['format']);

        $pay = $this->client([self::show(['vision']), self::chat(['content' => '{"bank_account":"1000000005/0100","iban":null,"variable_symbol":"2026001"}'])]);
        $r = $pay->extractPaymentAccount(1, self::textPdf());
        self::assertSame('1000000005/0100', $r['bank_account']);
        self::assertNull($r['iban']);

        $fuel = $this->client([self::show(['vision']), self::chat(['content' => '{"transactions":[{"liters":40}]}'])]);
        $r = $fuel->extractFuelTransactions(1, self::textPdf());
        self::assertSame([['liters' => 40]], $r['transactions']);
    }

    public function testInvalidUtf8InRequestBodyMapsToCodeInsteadOfThrowing(): void
    {
        // Neplatné bajty v názvu modelu (tělo /api/show) — Guzzle json_encode by vyhodil InvalidArgumentException.
        $c = $this->client([]);
        $r = $c->extractInvoice(1, self::textPdf(), "model\xff");
        self::assertFalse($r['ok']);
        self::assertSame('ollama_request_invalid', $r['code']);
    }

    // ── modely, test spojení, capabilities ──────────────────────────────────

    public function testListModels(): void
    {
        $c = $this->client([
            new Response(200, [], (string) json_encode(['models' => [
                ['name' => 'vision-model:7b', 'size' => 5_000_000_000],
                ['name' => 'text-model:3b', 'size' => 2_000_000_000],
            ]])),
            self::show(['completion', 'vision', 'thinking']),
            self::show(['completion']),
        ]);
        $r = $c->listModels(self::BASE);
        self::assertTrue($r['ok']);
        self::assertSame([
            ['name' => 'text-model:3b', 'size' => 2_000_000_000, 'vision' => false, 'thinking' => false],
            ['name' => 'vision-model:7b', 'size' => 5_000_000_000, 'vision' => true, 'thinking' => true],
        ], $r['models']);
    }

    public function testListModels_notOllama(): void
    {
        $c = $this->client([new Response(200, [], '<html>router login</html>')]);
        $r = $c->listModels(self::BASE);
        self::assertFalse($r['ok']);
        self::assertSame('ollama_not_ollama', $r['code']);
    }

    public function testTestConnection(): void
    {
        $ok = $this->client([new Response(200, [], '{"models":[{"name":"vision-model:7b","size":1}]}'), self::show(['vision'])]);
        self::assertSame(['ok' => true, 'model' => self::MODEL, 'vision' => true], $ok->testConnection(1));

        $missing = $this->client([new Response(200, [], '{"models":[{"name":"text-model:3b","size":1}]}'), self::show([])]);
        $r = $missing->testConnection(1);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('ollama_model_missing', $r['error']);
    }

    public function testCapabilities_regionFromResolvedIp(): void
    {
        $local = $this->client([], ips: ['192.168.1.50'])->capabilities(1);
        self::assertSame('eu', $local->dataRegion);
        self::assertSame([self::MODEL], $local->models);
        self::assertSame('us', $this->client([], ips: ['203.0.113.7'])->capabilities(1)->dataRegion);
        self::assertSame('us', $this->client([], ips: [])->capabilities(1)->dataRegion, 'nerezolvovatelný host = us');
        self::assertNull($this->client([])->strongerModel(1, self::MODEL));
    }

    public function testCompleteJson_textOnlyNoThinking(): void
    {
        $c = $this->client([self::show(['completion', 'thinking']), self::chat(['content' => '{"account":"518"}'])]);
        $schema = ['type' => 'object', 'properties' => ['account' => ['type' => 'string']]];
        $r = $c->completeJson(1, self::MODEL, 'SYS', 'USER', $schema, 500);
        self::assertSame(['ok' => true, 'data' => ['account' => '518'], 'model' => self::MODEL, 'usage' => ['input_tokens' => 1200, 'output_tokens' => 85]], $r);
        $p = $this->payload(1);
        self::assertFalse($p['think'], 'kontace jsou krátká klasifikace — bez přemýšlení');
        self::assertSame(500, $p['options']['num_predict']);
        self::assertSame($schema, $p['format']);
        self::assertArrayNotHasKey('images', $p['messages'][1]);
    }

    public function testCompleteJson_returnsMachineCodes(): void
    {
        $c = $this->client([], ips: ['169.254.169.254']);
        self::assertSame(['ok' => false, 'error' => 'ollama_endpoint_blocked'], $c->completeJson(1, self::MODEL, 'S', 'U', [], 100));
    }

    // ── credentials ─────────────────────────────────────────────────────────

    public function testSetCredentials_normalizesUrlAndKeySemantics(): void
    {
        $c = $this->client([]);
        $this->writes = [];
        $c->setCredentials(1, 'HTTP://Ollama.LAN:11434/', self::MODEL);
        $c->setCredentials(1, self::BASE, self::MODEL, 'tok-1');
        $c->setCredentials(1, self::BASE, self::MODEL, '');

        self::assertSame(['http://ollama.lan:11434', self::MODEL, 1], $this->writes[0]['params']);
        self::assertStringNotContainsString('ollama_api_key_enc', $this->writes[0]['sql'], 'null = klíč nechat');
        self::assertSame([self::BASE, self::MODEL, 'ENC(tok-1)', 1], $this->writes[1]['params']);
        self::assertSame([self::BASE, self::MODEL, null, 1], $this->writes[2]['params']);
    }

    public function testSameBaseUrl(): void
    {
        $c = $this->client([]);
        self::assertTrue($c->sameBaseUrl('http://ollama.lan:11434/', 'HTTP://OLLAMA.lan:11434'));
        self::assertFalse($c->sameBaseUrl('http://ollama.lan:11434', 'http://jiny.lan:11434'));
        self::assertFalse($c->sameBaseUrl('http://ollama.lan:11434', 'nesmysl'));
    }

    // ── fix round 1 ─────────────────────────────────────────────────────────

    /** @return array<mixed> */
    private function curlOpts(int $i): array
    {
        return $this->history[$i]['options']['curl'] ?? [];
    }

    public function testEveryRequestIsPinnedToVerifiedIp(): void
    {
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
        $c->extractInvoice(1, self::textPdf());
        foreach ([0, 1] as $i) {
            self::assertSame(['ollama.lan:11434:192.168.1.50'], $this->curlOpts($i)[\CURLOPT_RESOLVE] ?? null, "request #$i");
        }
        self::assertFalse($this->history[1]['options']['allow_redirects']);

        $c = $this->client([new Response(200, [], '{"models":[]}')]);
        $c->listModels(self::BASE);
        self::assertSame(['ollama.lan:11434:192.168.1.50'], $this->curlOpts(0)[\CURLOPT_RESOLVE] ?? null, '/api/tags');
    }

    /** Proxy z prostředí (http_proxy, HTTPS_PROXY, ALL_PROXY) by obešla pinning IP i kontrolu regionu. */
    public function testProxyFromEnvironmentIsDisabledOnEveryRequest(): void
    {
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
        $c->extractInvoice(1, self::textPdf());
        foreach ([0, 1] as $i) {
            // Guzzle pak pevně nastaví CURLOPT_PROXY i CURLOPT_NOPROXY na '' a libcurl proměnné
            // prostředí nečte. CURLOPT_NOPROXY v `curl` Guzzle odmítá (konflikt s `proxy`).
            self::assertSame('', $this->history[$i]['options']['proxy'] ?? null, "proxy #$i");
            self::assertArrayNotHasKey(\CURLOPT_NOPROXY, $this->curlOpts($i), "CURLOPT_NOPROXY #$i");
        }
    }

    /** Skutečný CurlHandler musí volby requestu přijmout — jinak každý request skončí jako ollama_request_invalid. */
    public function testRealCurlHandlerAcceptsRequestOptions(): void
    {
        if (!\function_exists('curl_exec')) {
            self::markTestSkipped('PHP rozšíření curl není k dispozici.');
        }
        $c = new OllamaClient(
            $this->createMock(Connection::class), $this->createMock(SecretEncryption::class), new NullLogger(),
            new OllamaEndpointGuard(static fn (string $h): array => [], ''),
            new class implements PdfPageRasterizerInterface {
                public function rasterize(string $pdfBytes, int $maxPages): array { return []; }
            },
            new PdfTotalExtractor(new PdfIsdocExtractor()),
        );
        // Port 9 (discard) na loopbacku nikdo neposlouchá — spojení se odmítne hned.
        self::assertSame('ollama_unreachable', $c->listModels('http://127.0.0.1:9')['code']);
    }

    public function testIpLiteralHasNoResolveOption(): void
    {
        $c = $this->client(
            [self::show(['vision']), self::chat(['content' => '{"a":1}'])],
            row: ['ollama_base_url' => 'http://192.168.1.50:11434', 'ollama_default_model' => self::MODEL, 'ollama_api_key_enc' => null],
        );
        self::assertTrue($c->extractInvoice(1, self::textPdf())['ok']);
        self::assertArrayNotHasKey(\CURLOPT_RESOLVE, $this->curlOpts(1));
    }

    public function testResolutionIsMemoisedSoRegionAndRequestSeeTheSameIp(): void
    {
        $calls = 0;
        $resolver = static function (string $h) use (&$calls): array {
            return $calls++ === 0 ? ['10.0.0.5'] : ['203.0.113.7'];
        };
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])], resolver: $resolver);

        self::assertSame('eu', $c->capabilities(1)->dataRegion);
        self::assertTrue($c->extractInvoice(1, self::textPdf())['ok']);
        self::assertSame(['ollama.lan:11434:10.0.0.5'], $this->curlOpts(1)[\CURLOPT_RESOLVE] ?? null);
        self::assertSame(1, $calls, 'DNS se rozlišuje jednou');
    }

    public function testFailedResolutionIsNotCached(): void
    {
        $calls = 0;
        $resolver = static function (string $h) use (&$calls): array {
            return $calls++ === 0 ? [] : ['10.0.0.5'];
        };
        $c = $this->client([], resolver: $resolver);
        self::assertSame('us', $c->capabilities(1)->dataRegion);
        self::assertSame('eu', $c->capabilities(1)->dataRegion);
    }

    public function testMissingCurlFailsClosedWithoutRequest(): void
    {
        $c = $this->client([self::show(['vision'])], curl: false);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertSame('ollama_curl_missing', $r['code'], 'chybějící ext-curl není „Ollama neodpovídá"');
        self::assertStringContainsString('curl', $r['error']);
        self::assertCount(0, $this->history);
        self::assertSame('ollama_curl_missing', $this->client([], curl: false)->listModels(self::BASE)['code']);
    }

    public function testInvalidJsonErrorIsCutOnCharacterBoundary(): void
    {
        // 199 ASCII + "ř" (2 B) přes hranici 200 B
        $text = str_repeat('a', 199) . 'řřř';
        $c = $this->client([self::show(['vision']), self::chat(['content' => $text])]);
        $r = $c->extractInvoice(1, self::textPdf());
        self::assertFalse($r['ok']);
        self::assertNotFalse(json_encode($r, JSON_THROW_ON_ERROR));
        self::assertTrue(mb_check_encoding($r['error'], 'UTF-8'));
    }

    public function testReflectedModelIsValidated(): void
    {
        $long = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'], str_repeat('x', 129))]);
        self::assertSame(self::MODEL, $long->extractInvoice(1, self::textPdf())['model']);

        $nonString = $this->client([self::show(['vision']), new Response(200, [], (string) json_encode([
            'model' => ['evil'], 'message' => ['content' => '{"a":1}'],
        ]))]);
        self::assertSame(self::MODEL, $nonString->extractInvoice(1, self::textPdf())['model']);
    }

    public function testListModels_failingShowDegradesOnlyThatModel(): void
    {
        $c = $this->client([
            new Response(200, [], (string) json_encode(['models' => [['name' => 'a:1', 'size' => 1], ['name' => 'b:1', 'size' => 2]]])),
            new Response(500, [], 'x'),
            self::show(['vision']),
        ]);
        $r = $c->listModels(self::BASE);
        self::assertTrue($r['ok']);
        self::assertSame([
            ['name' => 'a:1', 'size' => 1, 'vision' => null, 'thinking' => null],
            ['name' => 'b:1', 'size' => 2, 'vision' => true, 'thinking' => false],
        ], $r['models']);

        $c = $this->client([
            new Response(200, [], (string) json_encode(['models' => [['name' => 'a:1', 'size' => 1]]])),
            new ConnectException('cURL error 28: timed out', new Request('POST', self::BASE), null, ['errno' => 28]),
        ]);
        self::assertSame('ollama_timeout', $c->listModels(self::BASE)['code']);
    }

    /** Pomalé /api/show nesmí seznam modelů natáhnout na minuty — po 30 s zbylé modely bez příznaků. */
    public function testListModels_showLoopHasTotalDeadline(): void
    {
        $now = 1000.0;
        $slowShow = static function () use (&$now): Response {
            $now += 20;
            return self::show(['vision']);
        };
        $c = $this->client([
            new Response(200, [], (string) json_encode(['models' => [['name' => 'a:1', 'size' => 1], ['name' => 'b:1', 'size' => 1], ['name' => 'c:1', 'size' => 1]]])),
            $slowShow, $slowShow, $slowShow,
        ], clock: static function () use (&$now): float { return $now; });
        $r = $c->listModels(self::BASE);
        self::assertTrue($r['ok']);
        self::assertSame([
            ['name' => 'a:1', 'size' => 1, 'vision' => true, 'thinking' => false],
            ['name' => 'b:1', 'size' => 1, 'vision' => true, 'thinking' => false],
            ['name' => 'c:1', 'size' => 1, 'vision' => null, 'thinking' => null],
        ], $r['models']);
        self::assertCount(3, $this->history, '/api/tags + dvě /api/show, třetí už po termínu neodejde');
        self::assertLessThanOrEqual(10, $this->history[2]['options']['timeout'], 'druhé /api/show dostane jen zbytek rozpočtu');
    }

    public function testListModels_capsModelCount(): void
    {
        $models = [];
        for ($i = 0; $i < 150; $i++) {
            $models[] = ['name' => sprintf('m%03d:1', $i), 'size' => 1];
        }
        $responses = [new Response(200, [], (string) json_encode(['models' => $models]))];
        for ($i = 0; $i < 100; $i++) {
            $responses[] = self::show(['completion']);
        }
        $r = $this->client($responses)->listModels(self::BASE);
        self::assertTrue($r['ok']);
        self::assertCount(100, $r['models']);
        self::assertCount(101, $this->history);
    }

    public function testOversizedBodyIsRejected(): void
    {
        $big = '{"models":[],"pad":"' . str_repeat('a', 8 * 1024 * 1024) . '"}';
        $r = $this->client([new Response(200, [], $big)])->listModels(self::BASE);
        self::assertSame('ollama_response_too_large', $r['code']);
    }

    public function testTransferCapOptionsAreSetOnEveryRequest(): void
    {
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
        $c->extractInvoice(1, self::textPdf());
        foreach ([0, 1] as $i) {
            self::assertIsCallable($this->history[$i]['options']['on_headers'] ?? null, "on_headers #$i");
            self::assertIsCallable($this->history[$i]['options']['progress'] ?? null, "progress #$i");
        }
    }

    public function testDecodeContentDisabledAndCappedSinkOnEveryRequest(): void
    {
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])]);
        $c->extractInvoice(1, self::textPdf());
        foreach ([0, 1] as $i) {
            self::assertFalse($this->history[$i]['options']['decode_content'] ?? null, "decode_content #$i");
            self::assertInstanceOf(\MyInvoice\Service\Import\CappedSinkStream::class, $this->history[$i]['options']['sink'] ?? null, "sink #$i");
        }
    }

    public function testCappedSink(): void
    {
        $over = false;
        $sink = new \MyInvoice\Service\Import\CappedSinkStream(10, function () use (&$over): void { $over = true; });
        self::assertSame(6, $sink->write('abcdef'));
        self::assertFalse($over);
        self::assertSame(4, $sink->write('ghij'));
        self::assertFalse($over, 'přesně na stropu je ještě v pořádku');
        self::assertSame(0, $sink->write('k'));
        self::assertTrue($over);
        $sink->rewind();
        self::assertSame('abcdefghij', (string) $sink);
    }

    public function testHugeContentLengthAbortsInOnHeaders(): void
    {
        $c = $this->client([new Response(200, ['Content-Length' => (string) (9 * 1024 * 1024)], '{"models":[]}')]);
        // MockHandler volá on_headers stejně jako cURL handler po přijetí hlaviček.
        self::assertSame('ollama_response_too_large', $c->listModels(self::BASE)['code']);
    }

    public function testProgressCallbackAbortsTransferWhenCapExceeded(): void
    {
        $handler = static function (\Psr\Http\Message\RequestInterface $req, array $o) {
            // jako CurlFactory: průběžné hlášení stažených bajtů
            $o['progress'](0, 1024, 0, 0);
            $o['progress'](0, 9 * 1024 * 1024, 0, 0);
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], '{"models":[]}'));
        };
        $c = $this->client([], handler: $handler);
        self::assertSame('ollama_response_too_large', $c->listModels(self::BASE)['code']);
    }

    public function testProgressBelowCapDoesNotAbort(): void
    {
        $handler = static function (\Psr\Http\Message\RequestInterface $req, array $o) {
            $o['progress'](2048, 1024, 0, 0);
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], '{"models":[]}'));
        };
        self::assertTrue($this->client([], handler: $handler)->listModels(self::BASE)['ok']);
    }

    public function testUnexpectedThrowableNeverEscapes(): void
    {
        $r = $this->client([new \RuntimeException('boom SECRET')])->extractInvoice(1, self::textPdf());
        self::assertSame('ollama_unreachable', $r['code']);
        self::assertStringNotContainsString('SECRET', (string) json_encode($r));
    }

    // ── časový rozpočet ─────────────────────────────────────────────────────

    /** Rasterizace (a DNS, /api/show) se odečítá z téhož limitu — jinak by synchronní import skončil 504. */
    public function testChatGetsRemainderOfOneTimeBudget(): void
    {
        $now = 1000.0;
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])],
            clock: static function () use (&$now): float { return $now; },
            onRasterize: static function () use (&$now): void { $now += 60; });
        self::assertTrue($c->extractInvoice(1, self::textPdf())['ok']);
        self::assertSame(OllamaClient::DEFAULT_TIMEOUT - 60, $this->history[1]['options']['timeout']);
    }

    public function testChatTimeoutHasFloorWhenBudgetIsSpent(): void
    {
        $now = 1000.0;
        $c = $this->client([self::show(['vision']), self::chat(['content' => '{"a":1}'])],
            clock: static function () use (&$now): float { return $now; },
            onRasterize: static function () use (&$now): void { $now += 500; });
        $c->extractInvoice(1, self::textPdf());
        self::assertSame(5, $this->history[1]['options']['timeout']);
    }

    public function testCompleteJsonCountsShowAgainstBudget(): void
    {
        $now = 1000.0;
        $c = $this->client([
            static function () use (&$now): Response { $now += 30; return self::show(['completion']); },
            self::chat(['content' => '{"account":"518"}']),
        ], clock: static function () use (&$now): float { return $now; });
        self::assertTrue($c->completeJson(1, self::MODEL, 'S', 'U', ['type' => 'object'], 100)['ok']);
        self::assertSame(OllamaClient::DEFAULT_TIMEOUT - 30, $this->history[1]['options']['timeout']);
    }
}
