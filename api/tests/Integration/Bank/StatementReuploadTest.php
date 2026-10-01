<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Oficiální výpis banky nahraný dodatečně k pohybům, které už přišly přímým napojením.
 * Nic nového nesmí vzniknout, výpis se jen přiloží k existující evidenci.
 *
 * Syntetická data: vlastní účet 1000000005/0100, protiúčet 123456789/0300, rok 2049.
 */
#[Group('integration')]
final class StatementReuploadTest extends TestCase
{
    private const ACCOUNT = '1000000005';

    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $userId = 0;
    /** @var list<int> */
    private array $currencyIds = [];
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $this->cleanup();
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) @unlink($file);
        }
        $this->db->close();
    }

    /**
     * GPC ve „vnitřním formátu" čísel účtů (KB, MONETA). Dřív skončilo 409
     * `wrong_supplier_account`; a kdyby účet prošel, protiúčet ve vnitřním tvaru
     * by nesouhlasil s pohybem z napojení a tatáž platba by se založila znovu.
     */
    public function testInternalAccountFormatGpcLinksExistingMovementInsteadOfCreatingIt(): void
    {
        $currencyId = $this->registerCurrency();
        $apiStatementId = $this->insertStatement('bank_api', '2049-09-10');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank,
                 description, bank_ref, match_status)
             VALUES (?, '2049-09-10', 925.18, 'CZK', '5550045', '123456789', '0300', 'PRICHOZI TEST', 'API-REF-1', 'ignored')"
        )->execute([$apiStatementId]);
        $existingId = (int) $pdo->lastInsertId();

        [$response, $body] = $this->upload($this->internalFormatGpc());

        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        self::assertSame(0, $body['transactions'] ?? null, 'Nový pohyb nesmí vzniknout.');
        self::assertSame(1, $body['skipped_duplicates'] ?? null);
        self::assertSame(1, $this->transactionCount(), 'Na účtu zůstává jediný pohyb.');
        $link = $pdo->prepare('SELECT bank_transaction_id FROM bank_transaction_imports WHERE statement_id = ?');
        $link->execute([(int) ($body['evidence_statement_id'] ?? $body['statement_id'])]);
        self::assertSame($existingId, (int) $link->fetchColumn(), 'Výpis odkazuje na pohyb z napojení.');
        self::assertGreaterThan(0, $currencyId);
    }

    /**
     * Měsíční výpis z napojení nese datum posledního pohybu. U účtu, kde se ve druhé
     * půlce měsíce nic nehnulo, leží víc než deset dní před koncem období PDF;
     * PDF se přesto musí přiložit, ne naimportovat jako druhý výpis.
     */
    public function testPdfAttachesToMonthlyStatementDatedByItsLastMovement(): void
    {
        $this->registerCurrency();
        $statementId = $this->insertStatement('bank_api', '2049-09-15');
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, ?, ?, 'CZK')");
        $insert->execute([$statementId, '2049-09-10', 925.18]);
        $insert->execute([$statementId, '2049-09-15', -13.33]);

        $find = new \ReflectionMethod(BankStatementAction::class, 'findStatementForPdfAttachment');
        $target = $find->invoke($this->action, $this->supplierId, [
            'header' => ['statement_date' => '2049-09-30', 'statement_number' => ''],
            'transactions' => [
                ['posted_at' => '2049-09-10', 'amount' => 925.18],
                ['posted_at' => '2049-09-15', 'amount' => -13.33],
            ],
        ], null, self::ACCOUNT);

        self::assertSame($statementId, $target);
    }

    private function registerCurrency(): int
    {
        $existing = $this->db->pdo()->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND account_number = ? AND code = 'CZK'");
        $existing->execute([$this->supplierId, self::ACCOUNT]);
        $ids = $existing->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        $this->db->pdo()->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code, iban)
             VALUES (?, 'CZK', 'TEST reupload', 'Kč', 'CZK', 'CZK', 2, 0, 0, ?, '0100', NULL)"
        )->execute([$this->supplierId, self::ACCOUNT]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function insertStatement(string $source, string $date): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_statements (source, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, '0100', 'CZK', ?)"
        )->execute([$source, 'TEST-reupload.' . $source, hash('sha256', uniqid('reupload', true)), $this->supplierId, self::ACCOUNT, $date]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Ediční tvar (16 číslic) → vnitřní formát banky. */
    private static function internal(string $account): string
    {
        $edition = str_pad($account, 16, '0', STR_PAD_LEFT);
        $map = [10, 11, 12, 13, 14, 15, 4, 5, 6, 7, 8, 3, 9, 1, 2, 0];
        $internal = array_fill(0, 16, '0');
        foreach ($map as $k => $position) {
            $internal[$position] = $edition[$k];
        }
        return implode('', $internal);
    }

    private function internalFormatGpc(): string
    {
        $own = self::internal(self::ACCOUNT);
        $header = '074' . $own . str_pad('TEST UCET', 20) . '      '
            . str_pad('0', 14, '0') . '+'
            . str_pad('92518', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0') . '+'
            . str_pad('92518', 14, '0', STR_PAD_LEFT) . '+'
            . '001' . '100949';
        $tx = '075' . $own . self::internal('123456789')
            . str_pad('', 13, '0')
            . str_pad('92518', 12, '0', STR_PAD_LEFT) . '2'
            . str_pad('5550045', 10, '0', STR_PAD_LEFT) . '00' . '0300' . '0000'
            . str_pad('', 10, '0') . '000000'
            . str_pad('TEST', 20) . '01001' . '100949';
        return $header . "\r\n" . $tx . "\r\n";
    }

    /** @return array{0: Response, 1: array<string,mixed>} */
    private function upload(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gpcreupload');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        $file = new UploadedFile($tmp, 'vypis.gpc', 'text/plain', strlen($content), UPLOAD_ERR_OK);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(function (string $name, $default = null) {
            if ($name === SupplierScopeMiddleware::ATTR_CURRENT_ID) return $this->supplierId;
            if ($name === AuthMiddleware::ATTR_USER) return ['id' => $this->userId, 'role' => 'admin'];
            return $default;
        });
        $request->method('getUploadedFiles')->willReturn(['file' => $file]);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getServerParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');
        $response = $this->action->upload($request, new Response());
        return [$response, json_decode((string) $response->getBody(), true) ?: []];
    }

    private function transactionCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = ? AND bs.account_number IN (?, ?)'
        );
        $stmt->execute([$this->supplierId, self::ACCOUNT, str_pad(self::ACCOUNT, 16, '0', STR_PAD_LEFT)]);
        return (int) $stmt->fetchColumn();
    }

    /** Uklidí vše, co testy na syntetickém účtu v roce 2049 založily (i po předchozím pádu). */
    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $ids = $pdo->prepare(
            "SELECT id FROM bank_statements WHERE supplier_id = ? AND statement_date >= '2049-01-01'
                AND account_number IN (?, ?, ?)"
        );
        $ids->execute([$this->supplierId, self::ACCOUNT, str_pad(self::ACCOUNT, 16, '0', STR_PAD_LEFT), self::internal(self::ACCOUNT)]);
        $statementIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($statementIds === []) {
            return;
        }
        $in = implode(',', $statementIds);
        $pdo->exec("DELETE FROM bank_api_evidence_months WHERE evidence_statement_id IN ($in) OR monthly_statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_api_months WHERE statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_transaction_imports WHERE statement_id IN ($in) OR original_statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_transactions WHERE statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_statements WHERE id IN ($in)");
    }
}
