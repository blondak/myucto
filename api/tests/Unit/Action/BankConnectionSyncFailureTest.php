<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\Settings\BankConnectionAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectionService;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Ruční načtení pohybů nesmí skončit holou 500 bez kódu: UI pak neumí říct
 * nic lepšího než „Request failed with status code 500“.
 */
#[AllowMockObjectsWithoutExpectations]
final class BankConnectionSyncFailureTest extends TestCase
{
    public function testUnexpectedExceptionOutsideBankOperationReturnsStructuredError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'bank_connection_sync_crashed',
            self::callback(static fn (array $context): bool => $context['exception'] === \PDOException::class
                && $context['message'] === 'synthetic lock failure'),
        );
        $response = $this->action(new \PDOException('synthetic lock failure'), $logger)
            ->sync($this->request(), new Response(), ['currencyId' => 11]);

        $body = $this->body($response, 500);
        self::assertSame('bank_sync_failed', $body['error']['code']);
        self::assertStringContainsString('nepodařilo dokončit', $body['error']['message']);
    }

    public function testPendingKbStatementIsRetryableConflict(): void
    {
        $response = $this->action(new BankConnectorOperationException('kb_plus_statement_pending'))
            ->sync($this->request(), new Response(), ['currencyId' => 11]);

        self::assertSame('kb_plus_statement_pending', $this->body($response, 409)['error']['code']);
    }

    public function testSyncFailureLogsItsCauseWithoutChangingResponse(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'bank_connection_operation_failed',
            self::callback(static fn (array $context): bool => $context['code'] === 'bank_sync_failed'
                && $context['cause']['exception'] === \RuntimeException::class),
        );
        $cause = new \RuntimeException('KB+ výpis KM: neplatný záznam 075.');
        $response = $this->action(new BankConnectorOperationException('bank_sync_failed', [], $cause), $logger)
            ->sync($this->request(), new Response(), ['currencyId' => 11]);

        self::assertSame('bank_sync_failed', $this->body($response, 502)['error']['code']);
    }

    private function action(\Throwable $failure, ?LoggerInterface $logger = null): BankConnectionAction
    {
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willThrowException($failure);
        $service = new BankConnectionService(
            $this->createMock(BankConnectionRepository::class),
            $this->createMock(BankConnectorRegistry::class),
            $calls,
            $this->createMock(SecretEncryption::class),
            $this->createMock(GpcParser::class),
            $this->createMock(StatementImporter::class),
        );
        return new BankConnectionAction(
            $service,
            $logger ?? $this->createMock(LoggerInterface::class),
            $this->createMock(ActivityLogger::class),
        );
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/bank-connections/11/sync')
            ->withParsedBody([])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(
                10,
                'Testovací role',
                'staff',
                true,
                ['settings.bank_accounts' => AccessLevel::WRITE->value],
            ));
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body;
    }
}
