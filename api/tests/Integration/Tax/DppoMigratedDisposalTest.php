<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\Ms3Table;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Tests\Fixtures\MoneyS3\Ms3FixtureWriter;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EP-1: majetek vyřazený v jiném systému a převzatý převodem (Money S3). Karta je
 * `disposed`, vyřazení zaúčtoval převzatý deník (541/082), zápis `asset_disposal`
 * z modulu majetku neexistuje. Přiznání DPPO přesto musí dostat rozdíl účetní
 * a daňové ZC.
 *
 * Syntetické auto DM-005 ({@see SyntheticAgenda::filesWithAssetTaxCases()}): vstupní
 * cena 200 000, účetně 18 × 3 000 → ZC 146 000 k 15. 10. 2024; daňově 2. sk.
 * rovnoměrně, počáteční stav 22 000 + půlodpis 2024 22 250 → daňová ZC 155 750.
 */
#[Group('integration')]
final class DppoMigratedDisposalTest extends TestCase
{
    private const JOURNAL_FIELDS = [
        ['Cislo', 'L', 4], ['Zdroj', 'C', 2], ['Doklad', 'C', 10], ['Datum', 'D', 2], ['DatPlnDPH', 'D', 2],
        ['Popis', 'C', 50], ['UcMD', 'C', 6], ['UcD', 'C', 6], ['Castka', 'E', 10], ['Stred', 'C', 10], ['Zakazka', 'C', 10], ['ParICO', 'C', 12], ['Del', 'B', 1],
    ];
    private const CHART_FIELDS = [['Ucet', 'C', 6], ['Nazev', 'C', 50]];

    private Connection $db;
    private MoneyS3Importer $importer;
    private DppoReturnDataProvider $provider;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(MoneyS3Importer::class);
            $this->provider = $container->get(DppoReturnDataProvider::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dppo_ms3_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    public function testMigratedDisposalBookedByLedgerGivesBridgeInReturn(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithDisposalJournal());
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, Ms3Backup::extract($this->tmp . '/agenda.lz', $this->tmp . '/x'),
            new ImportOptions(ImportOptions::MODE_IMPORT, false, null, [], []));
        // Jediná chyba smí být neuzavřený rok 2024: přidané vyřazení chybí v počátečních
        // stavech 2025 syntetické agendy a pro přiznání 2024 uzávěrka potřeba není.
        $errors = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] as $m) {
                if ($m['level'] === 'error') {
                    $errors[] = $m['code'];
                }
            }
        }
        self::assertSame(['closing_mismatch'], $errors);

        $data = $this->provider->gather($supplierId, 2024);
        $rows = array_column($data['disposals'], null, 'inventory_number');
        self::assertArrayHasKey('DM-005', $rows);
        $car = $rows['DM-005'];
        self::assertSame(
            [146000.0, 'card', 146000.0, 155750.0, 'tax_entries'],
            [$car['book_residual_value'], $car['book_residual_source'], $car['journal_residual_value'], $car['tax_residual_value'], $car['tax_residual_source']],
        );
        self::assertSame(9750.0, $data['disposal_tax_decrease'], 'Daňová ZC 155 750 − účetní 146 000.');
        self::assertSame(0.0, $data['disposal_tax_increase']);
        foreach ($data['warnings'] as $w) {
            self::assertStringNotContainsString('DM-005', $w, 'Karta sedí na převzatý deník.');
        }
    }

    /**
     * Agenda s daňovými případy majetku a vyřazením auta zaúčtovaným interním dokladem:
     * odpis ZC 541/082 a vyřazení z evidence 082/022 ke dni vyřazení.
     *
     * @return array<string,string>
     */
    private function filesWithDisposalJournal(): array
    {
        $files = SyntheticAgenda::filesWithAssetTaxCases();
        $append = static function (string $path, array $fields, array $rows) use (&$files): void {
            $table = strtoupper(pathinfo($path, PATHINFO_FILENAME));
            $existing = iterator_to_array(Ms3Table::fromString($files[$path], $table)->rows(), false);
            $files[$path] = Ms3FixtureWriter::table($fields, array_merge($existing, $rows));
        };
        foreach (['ROK.001', 'ROK.002'] as $dir) {
            $append($dir . '/UcOsnova.DAT', self::CHART_FIELDS, [['Ucet' => '541000', 'Nazev' => 'Zůstatková cena prodaného majetku']]);
        }
        $append('ROK.001/UcDenik.DAT', self::JOURNAL_FIELDS, [
            ['Cislo' => 60, 'Zdroj' => 'ID', 'Doklad' => 'IDH24010', 'Datum' => '2024-10-15', 'Popis' => 'Vyřazení auta - odpis ZC', 'UcMD' => '541000', 'UcD' => '082100', 'Castka' => 146000.0],
            ['Cislo' => 61, 'Zdroj' => 'ID', 'Doklad' => 'IDH24010', 'Datum' => '2024-10-15', 'Popis' => 'Vyřazení auta z evidence', 'UcMD' => '082100', 'UcD' => '022100', 'Castka' => 200000.0],
        ]);
        return $files;
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, "tax_evidence")'
        )->execute([SyntheticAgenda::NAME, $this->czId, SyntheticAgenda::ICO, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }
}
