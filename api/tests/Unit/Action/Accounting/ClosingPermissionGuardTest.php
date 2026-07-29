<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Accounting;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit testy oprávnění uzávěrkových akcí (EP-14) — čistá trait bez DB.
 *
 * Sjednocení defense-in-depth s RoutePermissionMap: celá rodina
 * /periods/{id}/(closing|close|open-next|revert) běží na 'accounting.periods.close'
 * ({@see AccountingActionSupport::requireClose}), změna stavu období (/status,
 * schválení/reopen) na 'accounting.periods.manage' ({@see requireAdmin}). Práva se
 * testují ODDĚLENĚ — role s jedním právem nesmí projít guardem druhého.
 */
final class ClosingPermissionGuardTest extends TestCase
{
    /** Anonymní nositel traitu s veřejnými obálkami nad protected guardy. */
    private function guards(): object
    {
        return new class {
            use AccountingActionSupport;

            public function close(ServerRequestInterface $r, ResponseInterface $resp): bool
            {
                return $this->requireClose($r, $resp, $err);
            }

            public function manage(ServerRequestInterface $r, ResponseInterface $resp): bool
            {
                return $this->requireAdmin($r, $resp, $err);
            }

            public function write(ServerRequestInterface $r, ResponseInterface $resp): bool
            {
                return $this->requireWrite($r, $resp, $err);
            }
        };
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions, string $type = 'staff'): ServerRequestInterface
    {
        $role = $type === 'superadmin'
            ? new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin')
            : new EffectiveRole(2, 'Role', $type, true, $permissions);
        return (new ServerRequestFactory())->createServerRequest('POST', '/x')
            ->withAttribute('auth.effective_role', $role);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    public function testCloseRightGatesOnlyClosingWorkflow(): void
    {
        $g = $this->guards();
        $req = $this->request(['accounting.periods.close' => 2]);
        self::assertTrue($g->close($req, $this->response()), 'periods.close smí uzávěrkový workflow.');
        self::assertFalse($g->manage($req, $this->response()), 'periods.close NESMÍ měnit stav období.');
        self::assertFalse($g->write($req, $this->response()), 'periods.close není obecný účetní zápis.');
    }

    public function testManageRightGatesOnlyPeriodStatus(): void
    {
        $g = $this->guards();
        $req = $this->request(['accounting.periods.manage' => 2]);
        self::assertTrue($g->manage($req, $this->response()), 'periods.manage smí měnit stav období.');
        self::assertFalse($g->close($req, $this->response()), 'periods.manage NESMÍ uzávěrkový workflow.');
    }

    public function testGenericAccountingWriteDoesNotUnlockClosing(): void
    {
        $g = $this->guards();
        $req = $this->request(['accounting' => 2]);
        self::assertTrue($g->write($req, $this->response()));
        self::assertFalse($g->close($req, $this->response()), 'Obecné právo accounting nestačí na uzávěrku.');
        self::assertFalse($g->manage($req, $this->response()), 'Obecné právo accounting nestačí na stav období.');
    }

    public function testSuperadminPassesAllGuards(): void
    {
        $g = $this->guards();
        $req = $this->request([], 'superadmin');
        self::assertTrue($g->close($req, $this->response()));
        self::assertTrue($g->manage($req, $this->response()));
        self::assertTrue($g->write($req, $this->response()));
    }
}
