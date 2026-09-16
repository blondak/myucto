<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod syntetického XML exportu z POHODY do firmy v MyÚčtu - celý řetěz nad skutečnou
 * DB: osnova, deník s počátečními stavy, adresář, faktury, banka, vazby, úhrady
 * a rekonciliace. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaImportTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
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
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testImportReconcilesToTheCentAndIsIdempotent(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(1, $reconciliation);
        self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $reconciliation[0]['journal_diffs']);

        // Deník: jeden otevírací zápis + 5 dokladů; přijatá faktura je MD 518 / D 321.
        self::assertSame(6, $this->rows('journal_entries', $supplierId));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "source_type = 'opening'"));
        $side = $this->db->pdo()->prepare(
            "SELECT l.side FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.account_code = '518.000'"
        );
        $side->execute([$supplierId]);
        self::assertSame('debit', $side->fetchColumn());

        // Doklady navázané na deník a uhrazené bankou.
        self::assertSame(1, $this->rows('invoices', $supplierId, "status = 'paid' AND total_with_vat = 1210.00"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "status = 'paid' AND total_with_vat = 605.00 AND vendor_invoice_number = 'D-2026-7'"));
        self::assertSame(2, $this->rows('payment_matches', $supplierId));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "source_type = 'invoice' AND source_id IS NOT NULL"));
        self::assertSame(2, $this->rows('clients', $supplierId));

        // Výpis navazuje na počáteční stav 221 z deníku; doklad „Počáteční stav bankovního
        // účtu" není pohyb a podruhé se nezapočte.
        self::assertSame(1, $this->rows('bank_statements', $supplierId));
        self::assertSame(1, $this->rows('bank_statements', $supplierId, 'prev_balance = 100000.00 AND curr_balance = 100555.00 AND transaction_count = 3'));

        // Pohyb, který Pohoda zaúčtovala bez dokladu (poplatek), je vyřízený - výpis nesvítí
        // jako nedopárovaný. Úhrady faktur zůstávají spárované.
        $status = $this->db->pdo()->prepare(
            'SELECT t.match_status, t.match_reason, COUNT(*) AS n FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? GROUP BY t.match_status, t.match_reason ORDER BY t.match_status'
        );
        $status->execute([$supplierId]);
        self::assertSame([['manual', null, 2], ['ignored', 'pohoda_booked', 1]],
            array_map(static fn (array $r): array => [$r['match_status'], $r['match_reason'], (int) $r['n']], $status->fetchAll(\PDO::FETCH_ASSOC)));

        // Neuhrazená faktura minulého období se převede kvůli saldu, bez zápisu v deníku roku.
        self::assertSame(1, $this->rows('invoices', $supplierId, "status = 'sent' AND issue_date = '2025-12-20'"));
        $link = self::stepCounts($protocol, 'link');
        self::assertSame(1, $link['previous_period'] ?? 0);
        self::assertArrayNotHasKey('orphans', $link);

        // Doklad minulého období je v deníku zastoupený počátečními stavy - Doúčtování
        // dokladů ho nesmí nabízet, jinak by se zaúčtoval podruhé.
        $pending = Bootstrap::buildApp()->getContainer()->get(PendingBackfillCounter::class)->count($supplierId);
        self::assertSame(0, $pending['invoices'], json_encode($pending));
        self::assertSame(0, $pending['purchase_invoices'], json_encode($pending));
        self::assertSame(0, $pending['cash_documents'], json_encode($pending));

        // Opakovaný převod téhož exportu nic nezdvojí a nehlásí nic navíc - doklad minulého
        // období nesmí napodruhé vypadat jako doklad bez zápisu v deníku.
        $before = $this->snapshot($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
        $link = self::stepCounts($again, 'link');
        self::assertSame(1, $link['previous_period'] ?? 0, $this->explain($again));
        self::assertArrayNotHasKey('orphans', $link, $this->explain($again));
        self::assertSame('completed', $again->status(), $this->explain($again));
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /** Bankovní účet s pohyby patří mezi účty firmy (tab Účty, zůstatky), i když firma už korunový účet má. */
    public function testBankAccountJoinsCompanyAccounts(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE currencies SET account_number = '2000000009', bank_code = '0300' WHERE supplier_id = ?")->execute([$supplierId]);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, $this->rows('currencies', $supplierId, "code = 'CZK'"));
        self::assertSame(1, $this->rows('currencies', $supplierId, "account_number = '2000000009' AND is_default = 1"));
        self::assertSame(1, $this->rows('currencies', $supplierId, sprintf("account_number = '%s' AND bank_code = '%s' AND is_default = 0 AND is_active = 1",
            SyntheticPohodaExport::BANK_ACCOUNT, SyntheticPohodaExport::BANK_CODE)));
        $linked = $pdo->prepare(
            'SELECT COUNT(*) FROM supplier_bank_accounts a JOIN currencies c ON c.id = a.currency_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND c.account_number = ?'
        );
        $linked->execute([$supplierId, SyntheticPohodaExport::BANK_ACCOUNT]);
        self::assertSame(1, (int) $linked->fetchColumn());

        // Opakovaný převod účet nezdvojí.
        $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertSame(2, $this->rows('currencies', $supplierId, "code = 'CZK'"));
    }

    /**
     * Majetek z datového souboru POHODY: karta zařazená bez zápisu v deníku, počáteční
     * stavy pokrývají odpisy do posledního měsíce zaúčtovaného v POHODĚ (březen 2026)
     * a účetní odpis roku v MyÚčtu je jen za zbytek roku - leden až březen se nezdvojí.
     */
    public function testAssetsContinueAfterDepreciationBookedInPohoda(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, true));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'assets')['created'] ?? 0, $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare('SELECT * FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::ASSET_NUMBER]);
        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($asset);
        self::assertSame('in_use', $asset['status']);
        self::assertSame('2025-01-15', $asset['put_into_use_date']);
        self::assertSame('022.001', $asset['asset_account_code']);
        self::assertSame('082.001', $asset['accumulated_account_code']);
        // Účet pořízení se v deníku nepoužil, převod ho proto nezaložil - karta dostane syntetický 042.
        self::assertSame('042', $asset['acquisition_account_code']);
        self::assertSame('straight', $asset['tax_method']);
        self::assertSame(1, (int) $asset['tax_group']);
        self::assertSame([1, '24000.00'], [(int) $asset['opening_tax_years'], (string) $asset['opening_tax_amount']]);
        self::assertSame([14, '75000.00', 23], [(int) $asset['opening_acc_months'], (string) $asset['opening_acc_amount'], (int) $asset['acc_useful_life_months']]);
        // Zařazení se neúčtuje - zůstatky 022/082 přišly počátečními stavy.
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "source_type = 'asset'"));

        $plan = Bootstrap::buildApp()->getContainer()->get(AssetService::class)->plan($supplierId, (int) $asset['id']);
        $year = array_values(array_filter($plan['accounting'], static fn (array $row): bool => (int) $row['fiscal_year'] === SyntheticPohodaExport::YEAR))[0] ?? null;
        self::assertNotNull($year, json_encode($plan['accounting']));
        self::assertEqualsWithDelta(45000.0, (float) $year['amount'], 0.005, 'Duben až prosinec 2026 po 5 000 Kč.');

        // Drobný majetek: karta navázaná na převedenou přijatou fakturu i s položkou, vyřazená karta bez dokladu.
        $counts = self::stepCounts($protocol, 'small_assets');
        ksort($counts);
        self::assertSame(['created' => 3, 'linked' => 2, 'matched_by_amount' => 1, 'no_document' => 1], $counts, $this->explain($protocol));
        $small = $this->db->pdo()->prepare(
            'SELECT s.inventory_number, s.status, s.disposed_at, s.quantity, s.price, s.location, s.document_ref, s.purchase_invoice_item_id,
                    pi.vendor_invoice_number
               FROM small_assets s LEFT JOIN purchase_invoices pi ON pi.id = s.purchase_invoice_id
              WHERE s.supplier_id = ? ORDER BY s.inventory_number'
        );
        $small->execute([$supplierId]);
        [$drill, $chairs, $tools] = $small->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['DM0003', 'D-2026-8', 'D-2026-8'], [$tools['inventory_number'], $tools['document_ref'], $tools['vendor_invoice_number']]);
        self::assertNotNull($tools['purchase_invoice_item_id'], 'Karta bez odkazu se naváže podle data a částky.');
        self::assertSame(['DM0001', 'in_use', 'Dílna', '26PF0001', 'D-2026-7'],
            [$drill['inventory_number'], $drill['status'], $drill['location'], $drill['document_ref'], $drill['vendor_invoice_number']]);
        self::assertNotNull($drill['purchase_invoice_item_id'], 'Karta je navázaná i na položku faktury.');
        self::assertSame(['DM0002', 'disposed', '2026-02-01', '2.000', '3000.00', null],
            [$chairs['inventory_number'], $chairs['status'], $chairs['disposed_at'], (string) $chairs['quantity'], (string) $chairs['price'], $chairs['document_ref']]);

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertSame(1, self::stepCounts($again, 'assets')['existing'] ?? 0, $this->explain($again));
        self::assertSame(3, self::stepCounts($again, 'small_assets')['existing'] ?? 0, $this->explain($again));
        self::assertSame(1, $this->rows('assets', $supplierId));
        self::assertSame(3, $this->rows('small_assets', $supplierId));
    }

    /** Export cizí firmy se do téhle nevmíchá. */
    public function testExportOfAnotherCompanyIsRefused(): void
    {
        $supplierId = $this->supplier('99999994');
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('ico_mismatch', array_column((array) $protocol->get('preflight'), 'code'));
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
    }

    private function supplier(string $ico = SyntheticPohodaExport::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'clients',
            'payment_matches', 'posting_rules', 'bank_statements', 'journal_entry_document_links', 'chart_of_accounts'] as $t) {
            $out[$t] = $this->rows($t, $supplierId);
        }
        $out['map'] = array_sum((new PohodaImportRepository($this->db))->counts($supplierId));
        return $out;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
