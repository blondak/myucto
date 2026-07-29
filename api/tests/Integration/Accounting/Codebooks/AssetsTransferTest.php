<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Codebooks;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Codebooks\AssetImportService;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Excel přenosu karet majetku (Epic F5 §7.1). Vše v transakci
 * s rollbackem. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AssetsTransferTest extends TestCase
{
    private Connection $db;
    private AssetRepository $assets;
    private AssetImportService $service;
    private CodebookXlsxExporter $exporter;

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
            $this->assets   = $c->get(AssetRepository::class);
            $this->service  = $c->get(AssetImportService::class);
            $this->exporter = $c->get(CodebookXlsxExporter::class);
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
        $this->insertAsset('INV-RT', ['name' => 'Server']);
        $items = $this->assets->list($this->supplierId, ['per_page' => 200, 'page' => 1])['items'];
        $out = $this->exporter->assets($items);

        $report = $this->service->import($this->supplierId, $this->userId, $out['bytes'], 'majetek.xlsx', true);

        self::assertTrue($report['ok']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['failed']);
        self::assertGreaterThan(0, $report['skipped']);
    }

    public function testCreateIgnoresStatus(): void
    {
        $csv = "inventarni_cislo;nazev;vstupni_cena;datum_porizeni;danova_metoda;stav\n"
             . "INV-NEW;Notebook;30000;01.02.2024;none;in_use\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertTrue($report['ok']);
        self::assertSame('create', $report['rows'][0]['status']);
        $asset = $this->assets->find($this->supplierId, $this->findId('INV-NEW'));
        self::assertSame('draft', $asset['status']); // stav=in_use ze souboru ignorován
    }

    public function testUpdateDraftOk(): void
    {
        $this->insertAsset('INV-D', ['name' => 'Vrtačka']);
        $csv = "inventarni_cislo;nazev\nINV-D;Vrtačka NOVĚ\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertTrue($report['ok']);
        self::assertSame('update', $report['rows'][0]['status']);
        self::assertSame('Vrtačka NOVĚ', $this->assets->find($this->supplierId, $this->findId('INV-D'))['name']);
    }

    public function testUpdateInUseChangedError(): void
    {
        $this->insertAsset('INV-U', ['status' => 'in_use', 'put_into_use_date' => '2024-03-05', 'input_price' => 100000.00]);
        $csv = "inventarni_cislo;vstupni_cena\nINV-U;200000\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame('error', $report['rows'][0]['status']);
        self::assertSame(100000.0, (float) $this->assets->find($this->supplierId, $this->findId('INV-U'))['input_price']);
    }

    public function testUpdateInUseIdenticalSkips(): void
    {
        $this->insertAsset('INV-U2', ['status' => 'in_use', 'put_into_use_date' => '2024-03-05', 'name' => 'Zařízení']);
        $csv = "inventarni_cislo;nazev\nINV-U2;Zařízení\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertTrue($report['ok']);
        self::assertSame('skip', $report['rows'][0]['status']);
    }

    public function testOpeningValuesImported(): void
    {
        $csv = "inventarni_cislo;nazev;vstupni_cena;datum_porizeni;danova_metoda;odpisova_skupina;opravkovy_ucet;ucetni_zivotnost_mesicu;let_odepsano;danove_odepsano;mesicu_ucetne;ucetne_odepsano\n"
             . "INV-OPEN;Historický stroj;500000;01.01.2020;straight;2;082;60;3;180000;36;250000\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertTrue($report['ok'], json_encode($report['rows']));
        self::assertSame('create', $report['rows'][0]['status']);

        $asset = $this->assets->find($this->supplierId, $this->findId('INV-OPEN'));
        self::assertSame(3, (int) $asset['opening_tax_years']);
        self::assertSame(180000.0, (float) $asset['opening_tax_amount']);
        self::assertSame(36, (int) $asset['opening_acc_months']);
        self::assertSame(250000.0, (float) $asset['opening_acc_amount']);
    }

    public function testValidationErrors(): void
    {
        $csv = "inventarni_cislo;nazev;vstupni_cena;datum_porizeni;datum_zarazeni;danova_metoda;odpisova_skupina;opravkovy_ucet\n"
             . "INV-E1;A;0;01.03.2024;;none;;\n"                       // vstupni_cena <= 0
             . "INV-E2;B;100000;01.03.2024;;straight;;082\n"          // chybí odpisová skupina u straight
             . "INV-E3;C;100000;01.05.2024;01.01.2024;none;;\n";      // datum_zarazeni < datum_porizeni
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame(3, $report['failed']);
        foreach ($report['rows'] as $r) {
            self::assertSame('error', $r['status'], (string) ($r['message'] ?? ''));
        }
    }

    public function testOpeningAmountsAboveInputPriceAreRejected(): void
    {
        $csv = "inventarni_cislo;nazev;vstupni_cena;datum_porizeni;danova_metoda;odpisova_skupina;opravkovy_ucet;danove_odepsano;ucetne_odepsano\n"
             . "INV-OPEN-TAX;Daňové oprávky;100000;01.03.2024;straight;2;082;100000.01;0\n"
             . "INV-OPEN-ACC;Účetní oprávky;100000;01.03.2024;straight;2;082;0;100000.01\n";

        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame(2, $report['failed']);
        self::assertSame(['error', 'error'], array_column($report['rows'], 'status'));
    }

    public function testOpeningAccountingAmountRespectsExistingResidualValue(): void
    {
        $this->insertAsset('INV-OPEN-RES', ['input_price' => 100000.00, 'acc_residual_value' => 20000.00]);
        $csv = "inventarni_cislo;ucetne_odepsano\nINV-OPEN-RES;90000\n";

        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame('error', $report['rows'][0]['status']);
        self::assertSame(0.0, (float) $this->assets->find($this->supplierId, $this->findId('INV-OPEN-RES'))['opening_acc_amount']);
    }

    public function testNonDepreciatedAssetCannotImportOpeningAccountingDepreciation(): void
    {
        $csv = "inventarni_cislo;nazev;vstupni_cena;datum_porizeni;danova_metoda;opravkovy_ucet;mesicu_ucetne;ucetne_odepsano\n"
             . "INV-NONDEP;Pozemek;100000;01.03.2024;none;;12;10000\n";

        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'm.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame('error', $report['rows'][0]['status']);
    }

    public function testExportFormulaLikeNameIsString(): void
    {
        $this->insertAsset('INV-FX', ['name' => '=SUM(A1:A2)']);
        $items = $this->assets->list($this->supplierId, ['per_page' => 200, 'page' => 1])['items'];
        $out = $this->exporter->assets($items);

        $tmp = tempnam(sys_get_temp_dir(), 'cbfx_') . '.xlsx';
        $this->tmpFiles[] = $tmp;
        file_put_contents($tmp, $out['bytes']);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $sheet = $reader->load($tmp)->getSheet(0);

        // Najdi řádek s inv INV-FX; nazev je 2. sloupec (B).
        $found = false;
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            if ((string) $sheet->getCell([1, $r])->getValue() === 'INV-FX') {
                $cell = $sheet->getCell([2, $r]);
                self::assertSame('=SUM(A1:A2)', $cell->getValue());          // doslovný text, ne vyhodnoceno
                self::assertSame(DataType::TYPE_STRING, $cell->getDataType()); // uloženo jako string
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Řádek INV-FX nenalezen v exportu.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function insertAsset(string $inv, array $overrides = []): int
    {
        $data = array_merge([
            'inventory_number'         => $inv,
            'name'                     => 'Stroj',
            'kind'                     => 'tangible',
            'asset_account_code'       => '022',
            'accumulated_account_code' => '082',
            'acquisition_account_code' => '042',
            'input_price'              => 100000.00,
            'acquisition_date'         => '2024-03-01',
            'put_into_use_date'        => null,
            'status'                   => 'draft',
            'tax_method'               => 'straight',
            'tax_group'                => 2,
            'opening_tax_years'        => 0,
            'opening_tax_amount'       => 0.00,
            'opening_acc_months'       => 0,
            'opening_acc_amount'       => 0.00,
            'acc_useful_life_months'   => 60,
            'created_by'               => $this->userId,
        ], $overrides);
        return $this->assets->insert($this->supplierId, $data);
    }

    private function findId(string $inv): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        $stmt->execute([$this->supplierId, $inv]);
        return (int) $stmt->fetchColumn();
    }
}
