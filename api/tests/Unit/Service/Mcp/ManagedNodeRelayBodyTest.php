<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\ManagedNodeRelay;
use MyInvoice\Service\Mcp\McpFileLimits;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * Node v reléovém mostu je nedůvěryhodný: binární tělo smí poslat jen jako
 * multipart nahrání, v platném base64 a do stropu {@see McpFileLimits}.
 */
final class ManagedNodeRelayBodyTest extends TestCase
{
    private const CALL = ['operation' => 'call', 'name' => 'upload_document', 'token' => 'mi_pat_x',
        'boundSupplierId' => 3, 'arguments' => []];

    public function testMultipartUploadPassesWithApprovedTokenAndCompany(): void
    {
        $request = $this->relay()->apiRequest($this->message(), self::CALL);
        self::assertSame(base64_encode('--b--'), $request['bodyBase64']);
        self::assertSame('', $request['body']);
        self::assertSame('Bearer mi_pat_x', $request['headers']['Authorization']);
        self::assertSame('3', $request['headers']['X-Supplier-Id']);
    }

    public function testJsonCallsAreUnchanged(): void
    {
        $request = $this->relay()->apiRequest([
            'url' => 'https://example.test/api/v1/invoices', 'method' => 'GET', 'body' => '',
            'headers' => ['Accept' => 'application/json'],
        ], self::CALL);
        self::assertArrayNotHasKey('bodyBase64', $request);
    }

    public function testBinaryBodyOnlyForMultipartWrites(): void
    {
        $cases = [
            'JSON s binárním tělem' => $this->message(['headers' => ['Content-Type' => 'application/json']]),
            'GET s tělem' => $this->message(['method' => 'GET']),
            'DELETE s tělem' => $this->message(['method' => 'DELETE']),
            'multipart jako text' => ['url' => 'https://example.test/api/v1/documents', 'method' => 'POST',
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'], 'body' => '--b--'],
            'neplatné base64' => $this->message(['bodyBase64' => 'ab*=']),
            'base64 není text' => $this->message(['bodyBase64' => ['x']]),
        ];
        foreach ($cases as $label => $message) {
            try {
                $this->relay()->apiRequest($message, self::CALL);
                self::fail($label . ': mělo selhat.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode(), $label);
            }
        }
    }

    public function testOversizedBodyIsRejectedBeforeReachingApi(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(413);
        $this->relay()->apiRequest($this->message([
            'bodyBase64' => base64_encode(str_repeat('x', McpFileLimits::MAX_BODY_BYTES + 1)),
        ]), self::CALL);
    }

    public function testCompanyRulesStillApplyToUploads(): void
    {
        $call = ['boundSupplierId' => null, 'lockedSupplierId' => 7, 'arguments' => ['supplier_id' => 8]] + self::CALL;
        self::assertNull($this->relay()->apiRequest($this->message(), $call));
    }

    private function message(array $override = []): array
    {
        return $override + [
            'url' => 'https://example.test/api/v1/documents', 'method' => 'POST', 'body' => '',
            'bodyBase64' => base64_encode('--b--'),
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b', 'Authorization' => 'Bearer podvrh'],
        ];
    }

    private function relay(): ManagedNodeRelay
    {
        $config = new Config(['app' => ['managed' => true, 'url' => 'https://example.test']]);
        $db = new Connection($config);
        return new ManagedNodeRelay(new HostedMcp(
            $db,
            new TenantUrlResolver($config, new SupplierDomainRepository($db, EntityCache::disabled())),
            new ManagedModeGuard($config),
        ), $config);
    }
}
