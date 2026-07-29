<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Codebooks;

use MyInvoice\Action\Accounting\Codebooks\ChartOfAccountsImportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService;
use MyInvoice\Service\Accounting\Codebooks\CodebookImportException;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Integrační testy Excel přenosu účtové osnovy (Epic F5 §7.1). Vše v transakci
 * s rollbackem v tearDown. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ChartOfAccountsTransferTest extends TestCase
{
    private Connection $db;
    private ChartOfAccountsRepository $accounts;
    private ChartOfAccountsImportService $service;
    private CodebookXlsxExporter $exporter;
    private ChartOfAccountsImportAction $action;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->accounts = $c->get(ChartOfAccountsRepository::class);
            $this->service  = $c->get(ChartOfAccountsImportService::class);
            $this->exporter = $c->get(CodebookXlsxExporter::class);
            $this->action   = $c->get(ChartOfAccountsImportAction::class);
            $seeder         = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testExportImportRoundtrip(): void
    {
        $accounts = $this->accounts->listForTenant($this->supplierId, true);
        $out = $this->exporter->chartOfAccounts($accounts);

        $report = $this->service->import($this->supplierId, $this->userId, $out['bytes'], 'ucetni-osnova.xlsx', true);

        self::assertTrue($report['ok']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['failed']);
        self::assertGreaterThan(0, $report['skipped']);
        self::assertSame(count($accounts), $report['skipped']);
    }

    public function testImportMix(): void
    {
        $matrix = [
            ['ucet', 'nazev', 'typ', 'nadrizeny_ucet', 'aktivni'],
            ['T900', 'Nový syntetický', 'expense', '', '1'],       // create synthetic
            ['T900001', 'Nová analytika', '', 'T900', '1'],        // create analytic (parent z téhož souboru)
            ['211', 'Pokladna PŘEJMENOVÁNO', '', '', '1'],         // rename existing
            ['213', 'Ceniny', '', '', '0'],                        // deactivate existing
            ['311', 'Odběratelé', 'liability', '', '1'],           // type change → error
        ];
        $bytes = $this->xlsx($matrix);

        // Ostrý běh: obsahuje error → nesmí zapsat NIC.
        $report = $this->service->import($this->supplierId, $this->userId, $bytes, 'mix.xlsx', false);

        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        self::assertSame(2, $report['created']);
        self::assertSame(2, $report['updated']);

        $byLine = [];
        foreach ($report['rows'] as $r) {
            $byLine[$r['key']] = $r['status'];
        }
        self::assertSame('create', $byLine['T900']);
        self::assertSame('create', $byLine['T900001']);
        self::assertSame('update', $byLine['211']);
        self::assertSame('update', $byLine['213']);
        self::assertSame('error', $byLine['311']);

        // All-or-nothing: DB beze změny.
        self::assertNull($this->accounts->findByCode($this->supplierId, 'T900'));
        self::assertSame('Pokladna', $this->accounts->findByCode($this->supplierId, '211')['name']);
    }

    public function testDeactivateReferencedAccountWarns(): void
    {
        // 602 je credit aktivní globální kontace invoice.services.issued → referencovaný.
        $matrix = [
            ['ucet', 'aktivni'],
            ['602', '0'],
        ];
        $report = $this->service->import($this->supplierId, $this->userId, $this->xlsx($matrix), 'deakt.xlsx', true);

        self::assertTrue($report['ok']);
        self::assertSame('update', $report['rows'][0]['status']);
        self::assertArrayHasKey('message', $report['rows'][0]);
        self::assertNotSame('', (string) $report['rows'][0]['message']);
    }

    public function testTenantIsolation(): void
    {
        $pdo = $this->db->pdo();
        $otherId = $this->duplicateSupplier($pdo);
        $this->accounts->insert($otherId, [
            'account_code' => '211', 'name' => 'Pokladna FIRMA B',
            'account_type' => 'asset', 'normal_side' => 'debit', 'is_synthetic' => true, 'is_active' => true,
        ]);

        $matrix = [['ucet', 'nazev'], ['211', 'Pokladna FIRMA A ZMĚNA']];
        $report = $this->service->import($this->supplierId, $this->userId, $this->xlsx($matrix), 'iso.xlsx', false);
        self::assertTrue($report['ok']);

        // Firma B zůstává nedotčená.
        self::assertSame('Pokladna FIRMA B', $this->accounts->findByCode($otherId, '211')['name']);
        // Firma A se změnila.
        self::assertSame('Pokladna FIRMA A ZMĚNA', $this->accounts->findByCode($this->supplierId, '211')['name']);
    }

    public function testFormulaCellImportedAsText(): void
    {
        // Buňka názvu = formule; reader musí uložit doslovný text (nikdy getCalculatedValue).
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', 'ucet');
        $sheet->setCellValue('B1', 'nazev');
        $sheet->setCellValue('A2', '211');
        $sheet->setCellValue('B2', '=1+1');   // formule
        $bytes = $this->save($ss);

        $report = $this->service->import($this->supplierId, $this->userId, $bytes, 'formula.xlsx', false);
        self::assertTrue($report['ok']);
        self::assertSame('=1+1', $this->accounts->findByCode($this->supplierId, '211')['name']);
    }

    public function testTooManyRows422(): void
    {
        $lines = ['ucet;nazev;typ'];
        for ($i = 1; $i <= 2001; $i++) {
            $lines[] = 'X' . $i . ';Účet ' . $i . ';expense';
        }
        $csv = implode("\n", $lines);

        try {
            $this->service->import($this->supplierId, $this->userId, $csv, 'big.csv', true);
            self::fail('Očekávána CodebookImportException too_many_rows.');
        } catch (CodebookImportException $e) {
            self::assertSame('too_many_rows', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testBadMime415(): void
    {
        // .xlsx přípona, ale obsah není zip → bad_file 415 (MIME sniff v akci).
        $res = $this->importViaAction('accountant', 'toto rozhodně není xlsx', 'osnova.xlsx');
        self::assertSame(415, $res['status']);
        self::assertSame('bad_file', $res['body']['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<list<string>> $matrix */
    private function xlsx(array $matrix): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        foreach ($matrix as $r => $cols) {
            foreach ($cols as $c => $val) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        return $this->save($ss);
    }

    private function save(Spreadsheet $ss): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbtest_') . '.xlsx';
        $this->tmpFiles[] = $tmp;
        (new XlsxWriter($ss))->save($tmp);
        return (string) file_get_contents($tmp);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function importViaAction(string $role, string $content, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbup_');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        $uf = new UploadedFile($tmp, $filename, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/accounts/import')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withUploadedFiles(['file' => $uf])
            ->withParsedBody(['dry_run' => '1']);

        $resp = $this->action->import($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function duplicateSupplier(\PDO $pdo): int
    {
        $row = $pdo->query('SELECT * FROM supplier WHERE id = ' . $this->supplierId)->fetch(\PDO::FETCH_ASSOC);
        unset($row['id']);
        $cols = array_keys($row);
        $place = implode(', ', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO supplier (' . implode(', ', $cols) . ') VALUES (' . $place . ')')
            ->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    }
}
