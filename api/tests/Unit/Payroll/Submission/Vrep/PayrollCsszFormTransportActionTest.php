<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Vrep;

use MyInvoice\Action\Payroll\PayrollCsszFormTransportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Vrep\CsszFormVrepTransportService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PayrollCsszFormTransportActionTest extends TestCase
{
    public function testBearerTokenCannotTriggerTheTransport(): void
    {
        $transport = $this->createMock(CsszFormVrepTransportService::class);
        $transport->expects(self::never())->method('send');

        $response = $this->action($transport)->send(
            $this->request('bearer')->withHeader('Idempotency-Key', 'must-not-run'),
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    public function testSendIsScopedToOneSubmissionAndOneClick(): void
    {
        $transport = $this->createMock(CsszFormVrepTransportService::class);
        $transport->expects(self::once())
            ->method('send')
            ->with(11, 'test', 42, 'accountant-click-1', 9)
            ->willReturn(['agenda_code' => 'HZUPN', 'form' => 'HZUPN20']);

        $response = $this->action($transport)->send(
            $this->request('session')
                ->withHeader('Idempotency-Key', 'accountant-click-1')
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HZUPN20', $this->json($response)['form']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testPollReportsManualReviewOfAnUndocumentedProtocol(): void
    {
        $transport = $this->createMock(CsszFormVrepTransportService::class);
        $transport->expects(self::once())
            ->method('poll')
            ->with(11, 'test', 7)
            ->willReturn(new JmhzDispatchOutcome(['id' => 7, 'status' => 'completed'], null, null, true));

        $response = $this->action($transport)->poll(
            $this->request('session')->withParsedBody(['environment' => 'test']),
            new Response(),
            ['attemptId' => '7'],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['manual_review']);
        self::assertTrue($body['settled']);
    }

    private function action(CsszFormVrepTransportService $transport): PayrollCsszFormTransportAction
    {
        $access = $this->createStub(PayrollModuleAccess::class);
        $access->method('isEnabled')->willReturn(true);
        $states = $this->createStub(PayrollModuleStateRepository::class);
        $states->method('get')->willReturn(['status' => 'active']);

        return new PayrollCsszFormTransportAction($transport, $access, new PayrollProductionGate($states));
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/cssz-form-transport/42')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
