<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankNoticeMutationConcurrencyTest extends TestCase
{
    public static function mutationPaths(): iterable
    {
        yield 'reference writer' => [false];
        yield 'bank API action' => [true];
    }

    #[DataProvider('mutationPaths')]
    public function testExistingRepeatableReadSnapshotCannotHideCommittedConfirmation(bool $viaAction): void
    {
        $writer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $confirmer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $pdo = $writer->pdo();
        $other = $confirmer->pdo();
        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $supplierId, 'Synthetic test tenant is required.');
        $statementId = $noticeId = 0;
        try {
            $ref = 'synthetic-snapshot-' . bin2hex(random_bytes(8));
            $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,source,statement_date) VALUES (?,'synthetic-snapshot.eml',?,'1000000005','0100','CZK','email_notice','2099-06-15')")
                ->execute([$supplierId, hash('sha256', $ref)]);
            $statementId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency) VALUES (?,'email_notice',?,'2099-06-15',200,'CZK')")
                ->execute([$statementId, $ref]);
            $noticeId = (int) $pdo->lastInsertId();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            // Čtenář začal dřív, než jiný request avízo potvrdil.
            $snapshot = $pdo->prepare('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=?');
            $snapshot->execute([$noticeId]);
            self::assertSame(0, (int) $snapshot->fetchColumn());
            $other->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state) VALUES (?,?,?,?,'confirmed')")
                ->execute([$supplierId, $noticeId, $ref, hash('sha256', $ref)]);
            if ($viaAction) {
                $container = Bootstrap::buildContainer();
                $container->set(Connection::class, $writer);
                $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
                    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $other->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'admin']);
                $response = $container->get(BankStatementAction::class)->ignore($request, new Response(), ['id' => $noticeId]);
                self::assertSame(409, $response->getStatusCode());
                self::assertSame('unmatched', $other->query('SELECT match_status FROM bank_transactions WHERE id=' . $noticeId)->fetchColumn());
                return;
            }
            $called = false;
            try {
                (new BankNoticeMutationGuard($writer))->mutate($supplierId, $noticeId, static function () use (&$called): void { $called = true; });
                self::fail('Committed confirmation must block a writer with an older snapshot.');
            } catch (BankNoticeConfirmationException $exception) {
                self::assertContains($exception->errorCode, ['notice_read_only', 'bank_busy']);
                self::assertFalse($called);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($noticeId > 0) {
                $other->prepare('DELETE FROM bank_notice_confirmations WHERE notice_transaction_id=?')->execute([$noticeId]);
                $other->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$noticeId]);
            }
            if ($statementId > 0) $other->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$statementId]);
            $writer->close();
            $confirmer->close();
        }
    }
}
