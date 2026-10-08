<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use DG\BypassFinals;
use MyInvoice\Action\Admin\Import\MyuctoMigrationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MyuctoMigrationActionTest extends TestCase
{
    public function testAnonymousAndBearerRequestsCannotUpload(): void
    {
        BypassFinals::enable();
        $workflow = $this->createMock(MyuctoImportWorkflow::class);
        $workflow->expects(self::never())->method('init');
        $action = new MyuctoMigrationAction($workflow);
        foreach ([null, 'bearer'] as $method) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/chunked')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
            self::assertSame(403, $action->initChunked($request, (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }

    public function testImportPermissionAloneDoesNotGrantGraphWrites(): void
    {
        BypassFinals::enable();
        $workflow = $this->createMock(MyuctoImportWorkflow::class);
        $workflow->expects(self::never())->method('init');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/chunked')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic role', 'staff', true, ['utilities.import' => 2]));
        self::assertSame(403, (new MyuctoMigrationAction($workflow))->initChunked($request, (new ResponseFactory())->createResponse())->getStatusCode());
    }

    public function testSupplierAndActorComeFromTrustedRequestContext(): void
    {
        BypassFinals::enable();
        $workflow = $this->createMock(MyuctoImportWorkflow::class);
        $workflow->expects(self::once())->method('run')->with(4, 1, str_repeat('a', 16), 'synthetic', 'synthetic-password', false, false)->willReturn(['report' => ['dry_run' => true]]);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/token/run')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 4)
            ->withParsedBody(['supplier_id' => 99, 'actor_id' => 99, 'mode' => 'dry_run', 'source' => 'synthetic', 'password' => 'synthetic-password']);
        self::assertSame(200, (new MyuctoMigrationAction($workflow))->run($request, (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 16)])->getStatusCode());
    }
}
