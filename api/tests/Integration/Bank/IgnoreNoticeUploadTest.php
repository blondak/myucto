<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/** Skutečný upload nad vlastní syntetickou firmou, po testu se všechny fixture odstraní. */
final class IgnoreNoticeUploadTest extends TestCase
{
    public function testManualIgnoreThenGpcPreviewConfirmAndDuplicate(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('Vyžaduje testovací MariaDB.');
        $container = Bootstrap::buildApp()->getContainer();
        $db = $container->get(Connection::class);
        try { $pdo = $db->pdo(); } catch (\PDOException) { $this->markTestSkipped('Lokální DB není dostupná.'); }
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vatId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, auto_send_reminders)
                VALUES ('Synthetic ignore transfer', 'Testovaci 1', 'Testov', '00000', ?, 'ignore@example.invalid', 0, ?, 0)")
                ->execute([$countryId, $vatId]);
            $sid = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
                VALUES (?, 'CZK', 'Synthetic ignore transfer', 'Kc', 'Koruna', 'Crown', '1000000005', '0100')")->execute([$sid]);
            $currencyId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $sid]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $pdo->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, 'Synthetic ignore transfer', 'admin')")
            ->execute(['ignore-' . bin2hex(random_bytes(8)) . '@example.invalid', str_repeat('x', 60)]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
            VALUES (?, 'email_notice', 'Synthetic notices', ?, '1000000005', '0100', 'CZK', '2026-09-01', 5)")
            ->execute([$sid, hash('sha256', random_bytes(16))]);
        $sourceId = (int) $pdo->lastInsertId();
        $rows = json_decode(file_get_contents($root . '/api/tests/Fixtures/BankIgnoreTransfer/notices.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $pdo->prepare("INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank, counterparty_name)
                VALUES (?, 'email_notice', '2026-09-15', ?, 'CZK', ?, ?, ?, ?)")
                ->execute([$sourceId, $row['amount'], $row['vs'], $row['account'], $row['account'] ? '0100' : null, $row['name']]);
            $txId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_email_processed_messages (supplier_id, fallback_hash, message_id, sender, subject, status, bank_statement_id, bank_transaction_id)
                VALUES (?, ?, ?, 'ignore@example.invalid', 'Synthetic notice', 'processed_success', ?, ?)")
                ->execute([$sid, hash('sha256', random_bytes(16)), bin2hex(random_bytes(16)), $sourceId, $txId]);
        }
        $action = $container->get(BankStatementAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/bank-statements/upload')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid);
        $tmp = tempnam(sys_get_temp_dir(), 'bank-ignore-smoke-');
        // Unikátní neinterpretovaný řádek brání kolizi s ručním testem stejného GPC.
        $content = file_get_contents($root . '/api/tests/Fixtures/BankIgnoreTransfer/statement.gpc') . '999TEST-' . bin2hex(random_bytes(8)) . "\r\n";
        file_put_contents($tmp, $content);
        try {
            $ids = $pdo->query('SELECT id FROM bank_transactions WHERE statement_id = ' . $sourceId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            self::assertCount(5, $ids);
            foreach ($ids as $id) {
                $response = $action->ignore($request->withParsedBody(['note' => 'Synthetic audit note']), new Response(), ['id' => (int) $id]);
                self::assertSame(200, $response->getStatusCode());
            }
            $upload = function (array $body) use ($action, $request, $tmp, $content): array {
                $file = new UploadedFile($tmp, 'synthetic.gpc', 'text/plain', strlen($content));
                $response = $action->upload($request->withUploadedFiles(['file' => $file])->withParsedBody($body), new Response());
                return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
            };
            [$status, $preview] = $upload(['account_id' => $currencyId]);
            self::assertSame(409, $status, json_encode($preview));
            self::assertCount(2, $preview['error']['candidates']);
            self::assertSame([0, 1], array_column($preview['error']['candidates'], 'index'));
            [$status, $result] = $upload(['account_id' => $currencyId, 'ignore_decision' => json_encode(['fingerprint' => $preview['error']['fingerprint'], 'selected' => [0]])]);
            self::assertSame(200, $status, json_encode($result));
            self::assertSame(1, $result['ignored_transferred']);
            self::assertSame(0, $result['matched']);
            self::assertSame(6, $result['transactions']);
            $count = $pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . (int) $result['statement_id'] . " AND match_status = 'ignored'")->fetchColumn();
            self::assertSame(1, (int) $count);
            [$status, $duplicate] = $upload(['account_id' => $currencyId]);
            self::assertSame(200, $status);
            self::assertTrue($duplicate['duplicate']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            unlink($tmp);
            foreach (['bank_notice_ignore_transfers', 'bank_email_processed_messages', 'bank_transaction_imports', 'bank_api_evidence_months', 'bank_api_months'] as $table) {
                $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id = ?')->execute([$sid]);
            }
            $pdo->prepare('DELETE bt FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM bank_statements WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM supplier_bank_accounts WHERE supplier_id = ?')->execute([$sid]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$currencyId]);
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
}
