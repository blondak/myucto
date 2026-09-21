<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod syntetické zálohy PREMIER (iCAB) do firmy v MyÚčtu rok po roku - celý řetěz nad
 * skutečnou DB: osnova, deník s počátečními stavy dopočtenými z minulého roku, adresář,
 * faktury se zařazením DPH po položkách, pokladna, banka, vazby, úhrady, rekonciliace
 * a kontrola proti KH uloženému v PREMIER. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
    private DphPriznaniBuilder $dph;
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
            $this->importer = $container->get(PremierImporter::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('premier_import_map', 'premier_key')) {
            $this->markTestSkipped('Chybí migrace 1859 (premier_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_int_' . bin2hex(random_bytes(5));
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

    public function testTwoYearsReconcileToTheCentAndAreIdempotent(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup();

        // ── Rok 2025 ────────────────────────────────────────────────────────────
        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $this->assertReconciled($first, SyntheticPremierBackup::YEAR1);
        $journal = $first->get('journal')[0];
        self::assertSame([15, 0, 0], [$journal['entries'], $journal['existing'], $journal['skipped_rows']], $this->explain($first));
        self::assertSame(15, self::stepCounts($first, 'journal')['entries'] ?? null);
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "source_type = 'opening'"), 'První rok zálohy nemá z čeho počáteční stavy počítat.');

        // Vydané faktury: tuzemsko po položkách (kód 1), dobropis se zápornými částkami, oba uhrazené.
        self::assertSame(
            [['250001', 'invoice', 'paid', '12100.00', '1'], ['250002', 'credit_note', 'paid', '-1210.00', '1']],
            $this->fetch("SELECT varsymbol, invoice_type, status, total_with_vat, vat_classification_code FROM invoices WHERE supplier_id = ? ORDER BY varsymbol", $supplierId)
        );
        self::assertSame(
            [['Vývoj aplikace', '8000.00', '1680.00', '1'], ['Konzultace', '2000.00', '420.00', '1']],
            $this->fetch("SELECT it.description, it.total_without_vat, it.total_vat, it.vat_classification_code FROM invoice_items it JOIN invoices i ON i.id = it.invoice_id
                           WHERE i.supplier_id = ? AND i.varsymbol = '250001' ORDER BY it.order_index", $supplierId)
        );

        // Přijaté: tuzemsko 40, služba z EU 24e (daň 0, plný odpočet) - bez i se samovyměřením na 343
        // stejně, faktura v EUR přepočtená na deník.
        self::assertSame([
            ['PF250001/2025', 'invoice', 'paid', '1210.00', '210.00', '40', '0', 'full'],
            ['PF250002/2025', 'invoice', 'paid', '5000.00', '0.00', '24e', '1', 'full'],
            ['PF250003/2025', 'invoice', 'paid', '3000.00', '0.00', '24e', '1', 'full'],
            ['PF250004/2025', 'invoice', 'paid', '12162.58', '2110.86', '40', '0', 'full'],
        ], $this->fetch('SELECT varsymbol, document_kind, status, total_with_vat, total_vat, vat_classification_code, reverse_charge, vat_deduction
                           FROM purchase_invoices WHERE supplier_id = ? ORDER BY varsymbol', $supplierId));
        self::assertSame([
            ['PF250002/2025', '5000.00', '0.00', '21.00', '24e'],
            ['PF250003/2025', '3000.00', '0.00', '21.00', '24e'],
            ['PF250004/2025', '2512.30', '527.58', '21.00', '40'],
            ['PF250004/2025', '7539.42', '1583.28', '21.00', '40'],
        ], $this->fetch("SELECT p.varsymbol, it.total_without_vat, it.total_vat, it.vat_rate_snapshot, it.vat_classification_code
                           FROM purchase_invoice_items it JOIN purchase_invoices p ON p.id = it.purchase_invoice_id
                          WHERE p.supplier_id = ? AND p.varsymbol IN ('PF250002/2025', 'PF250003/2025', 'PF250004/2025') ORDER BY p.varsymbol, it.order_index", $supplierId));
        self::assertSame(2, self::stepCounts($first, 'purchase_invoices')['self_assessed'] ?? 0);
        // Záloha nese všechny roky: úhrada z ledna 2026 (vazba VAZBY) je vidět už při převodu 2025.
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = 'PF250002/2025' AND paid_at = '2026-01-15'"));

        // Pokladna: vklad bez DPH, výdej s řádkem DPH (kód 15 → 40).
        self::assertSame([
            ['in', 'other', 'none', '5000.00', null],
            ['out', 'purchase', 'vat', '605.00', '40|21.00|500.00|105.00|full'],
        ], $this->fetch("SELECT d.doc_type, d.purpose, d.vat_mode, d.total_amount,
                                (SELECT CONCAT_WS('|', l.vat_classification_code, l.vat_rate, l.base_amount, l.vat_amount, l.vat_deduction)
                                   FROM cash_document_vat_lines l WHERE l.cash_document_id = d.id LIMIT 1)
                           FROM cash_documents d WHERE d.supplier_id = ? ORDER BY d.issue_date, d.id", $supplierId));
        self::assertSame(2, $this->rows('cash_documents', $supplierId, 'journal_entry_id IS NOT NULL'), 'Pokladní doklady jsou navázané na zápis deníku.');

        // Banka: výpis na každý doklad řady BV, úhrady spárované podle vazeb PREMIER, pohyby bez faktury vyřízené.
        self::assertSame(7, $this->rows('bank_statements', $supplierId));
        self::assertSame(1, $this->rows('bank_statements', $supplierId, sprintf("account_number = '%s' AND prev_balance = 0.00 AND curr_balance = 200000.00", SyntheticPremierBackup::BANK_ACCOUNT)));
        self::assertSame(5, $this->rows('payment_matches', $supplierId));
        self::assertSame([['manual', null, 5], ['ignored', 'premier_booked', 2]], $this->txStatuses($supplierId));
        self::assertSame(3, $this->rows('clients', $supplierId), 'Odběratel, dodavatel a dodavatel z EU; vlastní firma z adresáře se přeskočí.');

        // Služba z EU v přiznání za duben: daň na výstupu ř. 5 a odpočet ř. 43.
        $lines = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::RC_MONTH, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(5000.0, (float) ($lines['5']['base'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(1050.0, (float) ($lines['5']['vat'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(5000.0, (float) ($lines['43']['base'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(1050.0, (float) ($lines['43']['vat'] ?? 0), 0.005, json_encode($lines, JSON_UNESCAPED_UNICODE));
        // Totéž se samovyměřením zaúčtovaným na 343 (květen) - nezdvojí se.
        $may = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 5, 'monthly')['summary']['lines'];
        self::assertSame([3000.0, 630.0, 3000.0, 630.0], [round((float) $may['5']['base'], 2), round((float) $may['5']['vat'], 2), round((float) $may['43']['base'], 2), round((float) $may['43']['vat'], 2)]);

        // KH za únor sedí na poslední (následné) podání v PREMIER.
        $verification = self::stepCounts($first, 'verification');
        self::assertSame([1, 1], [$verification['kh_months'] ?? null, $verification['kh_months_ok'] ?? null], $this->explain($first));

        // ── Rok 2026: počáteční stavy z deníku 2025 ────────────────────────────
        $second = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        $this->assertReconciled($second, SyntheticPremierBackup::YEAR2);
        self::assertSame(4, $second->get('journal')[0]['entries'], $this->explain($second));
        self::assertSame(8, self::stepCounts($second, 'journal')['opening_accounts'] ?? null, $this->explain($second));

        self::assertSame([
            ['211.001', '4395.00', '0.00'],
            ['221.001', '194467.42', '0.00'],
            ['321.000', '0.00', '5000.00'],
            ['343.021', '535.86', '0.00'],
            ['343.100', '630.00', '0.00'],
            ['343.200', '0.00', '630.00'],
            ['411.000', '0.00', '205000.00'],
            ['431.000', '10601.72', '0.00'],
            ['701', '210630.00', '210630.00'],
        ], $this->fetch("SELECT a.account_code,
                                FORMAT(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE 0 END), 2, 'en_US'),
                                FORMAT(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 2, 'en_US')
                           FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id JOIN chart_of_accounts a ON a.id = l.account_id
                          WHERE e.supplier_id = ? AND e.source_type = 'opening' GROUP BY a.account_code ORDER BY a.account_code", $supplierId, true));

        // Výpis 2026 navazuje na zůstatek 221 z roku 2025.
        self::assertSame(
            [['194467.42', '189467.42'], ['189467.42', '213667.42']],
            $this->fetch("SELECT prev_balance, curr_balance FROM bank_statements WHERE supplier_id = ? AND statement_date >= '2026-01-01' ORDER BY statement_date", $supplierId)
        );
        // Úhrada služby z EU z minulého roku se spáruje s fakturou převedenou v roce 2025.
        self::assertSame(7, $this->rows('payment_matches', $supplierId));
        self::assertSame(1, $this->rows('payment_matches', $supplierId, "purchase_invoice_id = (SELECT id FROM purchase_invoices WHERE supplier_id = payment_matches.supplier_id AND varsymbol = 'PF250002/2025')"));
        self::assertSame(1, self::stepCounts($second, 'purchase_invoices')['existing'] ?? 0, 'Neuhrazená faktura minulého roku se nepřevádí znovu.');
        self::assertSame(1, self::stepCounts($second, 'link')['previous_period'] ?? 0, $this->explain($second));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '260001' AND status = 'paid' AND total_with_vat = 24200.00"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = 'PF260001/2026' AND status = 'booked' AND total_with_vat = 2420.00"));

        // ── Opakovaný převod obou let nic nezdvojí ─────────────────────────────
        $before = $this->snapshot($supplierId);
        foreach ([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2] as $year) {
            $again = $this->importer->run($supplierId, $this->userId, $backup, $year, false);
            self::assertFalse($again->hasErrors(), $this->explain($again));
            self::assertSame('completed', $again->status(), $this->explain($again));
            $journal = $again->get('journal')[0];
            self::assertSame(0, $journal['entries'], $this->explain($again));
            self::assertSame($year === SyntheticPremierBackup::YEAR1 ? 15 : 4, $journal['existing']);
            self::assertArrayNotHasKey('created', self::stepCounts($again, 'purchase_invoices'), $this->explain($again));
            self::assertArrayNotHasKey('created', self::stepCounts($again, 'issued_invoices'), $this->explain($again));
            self::assertArrayNotHasKey('transactions', self::stepCounts($again, 'bank'), $this->explain($again));
            $this->assertReconciled($again, $year);
        }
        self::assertSame($before, $this->snapshot($supplierId));
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), SyntheticPremierBackup::YEAR1, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(15, $protocol->get('journal')[0]['entries'], 'Zkouška nanečisto projde celý převod.');
        self::assertSame($before, $this->snapshot($supplierId));
    }

    public function testBackupOfAnotherCompanyIsRefused(): void
    {
        $supplierId = $this->supplier('99999994');
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), SyntheticPremierBackup::YEAR1, false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('ico_mismatch', array_column((array) $protocol->get('preflight'), 'code'));
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
    }

    public function testYearMissingInBackupIsRefused(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), 2019, false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('year_missing', array_column((array) $protocol->get('preflight'), 'code'));
    }

    /**
     * Vydaný doklad s kódem bez řádků přiznání, který nese daň (prodej koncovému
     * zákazníkovi na Slovensko): se zapnutým OSS jde do OSS, ne do tuzemského přiznání.
     */
    public function testIssuedDocumentOutsideReturnWithVatBecomesOssSupply(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_identification_country = 'CZ', oss_return_currency = 'EUR', oss_valid_from = '2025-01-01', oss_valid_to = NULL WHERE id = ?"
        )->execute([$supplierId]);
        $this->foreignRate(SyntheticPremierBackup::OSS_COUNTRY, SyntheticPremierBackup::OSS_RATE);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->fetch(
            "SELECT i.status, i.vat_classification_code, it.vat_classification_code, it.oss_applicable, it.oss_consumer_country, r.country, it.vat_rate_snapshot, it.total_vat
               FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id JOIN vat_rates r ON r.id = it.vat_rate_id
              WHERE i.supplier_id = ? AND i.varsymbol = ?", $supplierId, false, [SyntheticPremierBackup::OSS_DOCUMENT]
        );
        self::assertSame([['sent', null, null, '1', 'SK', 'SK', '23.00', '230.00']], $row, $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'issued_invoices')['oss_items'] ?? 0);

        $september = $this->dph->build($supplierId, SyntheticPremierBackup::YEAR1, 9, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(0.0, (float) ($september['1']['base'] ?? 0), 0.005, 'OSS plnění do tuzemského ř. 1 nepatří.');
    }

    /** Bez zapnutého OSS se cizí daň do tuzemského přiznání nepustí - doklad se nepřevezme, zbytek ano. */
    public function testIssuedDocumentOutsideReturnWithVatIsNotDomesticWhenOssIsOff(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(true), SyntheticPremierBackup::YEAR1, false);

        self::assertSame(0, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND status <> 'draft'", SyntheticPremierBackup::OSS_DOCUMENT)), $this->explain($protocol));
        self::assertSame(0, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND vat_classification_code IS NOT NULL", SyntheticPremierBackup::OSS_DOCUMENT)));
        self::assertContains('oss_setup', $this->messageCodes($protocol), $this->explain($protocol));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '250001' AND status = 'paid'"), $this->explain($protocol));
        self::assertSame(4, $this->rows('purchase_invoices', $supplierId), $this->explain($protocol));
    }

    private function assertReconciled(ImportProtocol $protocol, int $year): void
    {
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(1, $reconciliation, $this->explain($protocol));
        self::assertSame($year, $reconciliation[0]['year']);
        self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $reconciliation[0]['journal_diffs']);
    }

    private function backup(bool $oss = false): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . ($oss ? 'oss' : 'base');
        if (!is_dir($dir)) {
            PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . DIRECTORY_SEPARATOR . 'zaloha.icab', $this->tmp, $oss), $dir);
        }
        return PremierBackup::open($dir);
    }

    private function foreignRate(string $country, float $rate): void
    {
        $pdo = $this->db->pdo();
        $code = $country . '-' . (int) $rate;
        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, "2024-01-01", NULL, 900)'
        )->execute([$code, $rate, $country, $code, $code]);
    }

    private function supplier(string $ico = SyntheticPremierBackup::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([SyntheticPremierBackup::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
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

    /**
     * @param list<mixed> $params
     * @return list<list<mixed>>
     */
    private function fetch(string $sql, int $supplierId, bool $stripThousands = false, array $params = []): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $params));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $out[] = array_map(static fn (mixed $v): mixed => $stripThousands && is_string($v) ? str_replace(',', '', $v) : (is_int($v) ? (string) $v : $v), $row);
        }
        return $out;
    }

    /** @return list<array{0:string,1:?string,2:int}> */
    private function txStatuses(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.match_status, t.match_reason, COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? GROUP BY t.match_status, t.match_reason ORDER BY t.match_status'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [$r[0], $r[1], (int) $r[2]], $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @return array<string,int> */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'clients', 'cash_documents', 'cash_registers',
            'payment_matches', 'bank_statements', 'journal_entry_document_links', 'chart_of_accounts', 'supplier_bank_accounts'] as $t) {
            $out[$t] = $this->rows($t, $supplierId);
        }
        $out['map'] = array_sum((new PremierImportRepository($this->db))->counts($supplierId));
        return $out;
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

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        return $codes;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
