<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\DbfWriter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanci a mzdy z PREMIER nad skutečnou DB: osoby a vztahy (jednatelka, skončená
 * DPP, pracovní poměr z dalšího roku), převzaté měsíce jako evidence bez účetních zápisů,
 * počáteční stavy kumulací, rekonciliace mezd proti deníku, opakovaný převod a zkouška
 * nanečisto. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierPayrollImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
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
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        foreach (['premier_import_map', 'payroll_migration_reference_totals', 'payroll_employments', 'payroll_offices'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_pay_int_' . bin2hex(random_bytes(5));
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

    public function testEmployeesAndMonthsWithoutJournalEntriesAndRerunIsIdempotent(): void
    {
        $supplierId = $this->supplier(true);
        $backup = $this->backup(['payroll' => true]);

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertTrue($first->get('reconciliation')[0]['ok'], $this->explain($first));
        $counts = self::stepCounts($first, 'payroll');
        self::assertSame([2, 2, 16, 1], [$counts['employees_created'] ?? 0, $counts['employments_created'] ?? 0, $counts['months'] ?? 0, $counts['employees_later'] ?? 0],
            $this->explain($first));
        self::assertArrayNotHasKey('details_failed', $counts, $this->explain($first));
        self::assertArrayNotHasKey('deductions_not_converted', $counts, 'Bez srážek ve zdroji žádné upozornění.');
        self::assertArrayNotHasKey('absences_not_converted', $counts);
        self::assertArrayNotHasKey('failed', $counts, $this->explain($first));
        self::assertSame([2, 2, 2, 1], [$counts['tax_residence'] ?? 0, $counts['tax_declarations'] ?? 0, $counts['social_jurisdiction'] ?? 0, $counts['ended'] ?? 0]);

        self::assertSame([
            ['1', 'statutory_body', 'active', null, 'Jana Fiktivní'],
            ['2', 'dpp', 'ended', '2025-06-30', 'Petr Zkušební'],
        ], $this->fetch('SELECT e.code, e.relation_type, e.status, e.end_date, p.full_name FROM payroll_employments e
                           JOIN payroll_employees p ON p.id = e.employee_id WHERE e.supplier_id = ? ORDER BY e.code', $supplierId), $this->explain($first));
        self::assertSame([['S', '600000']], $this->fetch("SELECT t.activity_code, t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '1'", $supplierId));

        // Převzaté měsíce: evidence předchozího systému, žádné účetní zápisy.
        self::assertSame([['16', '8400000']], $this->fetch("SELECT COUNT(*), SUM(gross_minor) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND source = 'other'", $supplierId));
        self::assertSame([['4', '0', '0']], $this->fetch("SELECT COUNT(*), MAX(pension_participation), MAX(insurance_days) FROM payroll_migration_reference_totals
            WHERE supplier_id = ? AND relation_type = 'dpp'", $supplierId), 'DPP do 4 000 Kč nezakládá účast na pojištění.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = ?
            AND e.source_type NOT IN ('opening', 'closing') AND NOT EXISTS (SELECT 1 FROM premier_import_map m
                WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)", $supplierId),
            'Převod mezd nezaložil vlastní účetní zápis.');

        $payroll = $first->get('payroll_reconciliation');
        self::assertCount(12, $payroll[0]['months'], $this->explain($first));
        self::assertTrue($payroll[0]['ok'], $this->explain($first));
        self::assertContains('payroll_reconciled', $this->messageCodes($first));

        // Karta osoby: adresa, zákonná evidence a neověřený výplatní účet.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ?", $supplierId), $this->explain($first));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'payroll');
        self::assertSame([0, 2, 0, 16], [$counts['employees_created'] ?? 0, $counts['existing'] ?? 0, $counts['months'] ?? 0, $counts['months_existing'] ?? 0],
            $this->explain($again));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(16, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ?", $supplierId));

        // Další rok: nový zaměstnanec, změna odměny, měsíce od začátku vedení mezd se nepřebírají,
        // za leden (před začátkem 2/2026) vzniknou počáteční stavy kumulací.
        $next = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($next->hasErrors(), $this->explain($next));
        $counts = self::stepCounts($next, 'payroll');
        self::assertSame([1, 1, 2, 1, 1], [$counts['employees_created'] ?? 0, $counts['months'] ?? 0, $counts['months_after_start'] ?? 0,
            $counts['openings'] ?? 0, $counts['wage_changes'] ?? 0], $this->explain($next));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(17, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND period_start >= '2026-02-01'", $supplierId));
        self::assertSame([['600000'], ['650000']], $this->fetch("SELECT t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '1' ORDER BY t.effective_from", $supplierId));
        self::assertTrue($next->get('payroll_reconciliation')[0]['ok'], $this->explain($next));

        // Opakovaný převod roku 2026: počáteční stavy se shodnými čísly nevytvoří novou verzi.
        $repeat = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($repeat->hasErrors(), $this->explain($repeat));
        $counts = self::stepCounts($repeat, 'payroll');
        self::assertSame([0, 1, 0, 0], [$counts['openings'] ?? 0, $counts['openings_existing'] ?? 0, $counts['employees_created'] ?? 0, $counts['wage_changes'] ?? 0],
            $this->explain($repeat));
    }

    /**
     * Mzdové zápisy deníku dávají návrh kontací mezd stejnou cestou jako převod z PAMICA:
     * uloží se jen návrh, nastavení zaměstnavatele se nemění.
     */
    public function testPostingMapProposalFromPayrollJournal(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare('SELECT source, status, source_year, proposal_json FROM payroll_posting_map_proposals WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $stored = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $stored, $this->explain($protocol));
        self::assertSame(['other', 'draft', 2025], [$stored[0]['source'], $stored[0]['status'], (int) $stored[0]['source_year']]);
        $keys = [];
        foreach (json_decode((string) $stored[0]['proposal_json'], true)['keys'] as $key) {
            $keys[$key['key']] = [$key['status'], $key['suggested_code']];
        }
        $expected = [
            'employment_gross_debit' => ['unambiguous', '521.100'],
            'employment_gross_credit' => ['unambiguous', '331.100'],
            'social_insurance_credit' => ['unambiguous', '336.100'],
            'health_insurance_credit' => ['unambiguous', '336.200'],
            'employer_insurance_debit' => ['unambiguous', '524.100'],
            'withholding_tax_credit' => ['unambiguous', '342.200'],
            // Záloha na daň v roce 2025 nikdo neměl, v deníku pro ni nic není.
            'income_tax_credit' => ['missing', null],
        ];
        $actual = array_intersect_key($keys, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, $this->explain($protocol));
        self::assertSame([], json_decode((string) $stored[0]['proposal_json'], true)['unmapped']);
        self::assertContains('posting_map', $this->messageCodes($protocol));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_posting_map_proposals WHERE supplier_id = ? AND status = 'confirmed'", $supplierId));
    }

    /**
     * Srážky a vyloučené doby PREMIER nese jen jako částky a počty dnů za měsíc; převod
     * je nezakládá, ale musí to říct s osobními čísly, jinak by o nich mlčel.
     */
    public function testDeductionsAndExcludedDaysAreReportedAsNotConverted(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backupWithDeductions(), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $messages = [];
        foreach (self::step($protocol, 'payroll')['messages'] as $m) {
            $messages[$m['code']] = $m;
        }
        self::assertArrayHasKey('deductions_not_converted', $messages, $this->explain($protocol));
        self::assertSame('warning', $messages['deductions_not_converted']['level'] ?? null);
        self::assertStringContainsString('osobní čísla 1 (celkem 4 500,00 Kč)', $messages['deductions_not_converted']['text']);
        self::assertArrayHasKey('absences_not_converted', $messages, $this->explain($protocol));
        self::assertStringContainsString('1 (naposledy 2025-11)', $messages['absences_not_converted']['text']);
        $counts = self::stepCounts($protocol, 'payroll');
        self::assertSame([1, 1], [$counts['deductions_not_converted'] ?? 0, $counts['absences_not_converted'] ?? 0]);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_absences a JOIN payroll_employments e ON e.id = a.employment_id WHERE e.supplier_id = ?', $supplierId));
    }

    /** Učeň nesmí vzniknout jako pracovní poměr; převod ho nezaloží a řekne to. */
    public function testApprenticeIsReportedInsteadOfCreatedAsEmployment(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND code = '4'", $supplierId), $this->explain($protocol));
        self::assertContains('relation_apprentice', $this->messageCodes($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'payroll')['apprentices'] ?? 0);
    }

    /** Sjednaná mzda vztahu ze sazby podle typu mzdy, ne z `MZDA_MES`. */
    public function testAgreedWageFromRateByWageType(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['2025-01-15', '3000000'], ['2025-07-01', '3200000']], $this->fetch("SELECT t.effective_from, t.monthly_gross_minor FROM payroll_employment_terms t
            JOIN payroll_employments e ON e.id = t.employment_id WHERE e.supplier_id = ? AND e.code = '5' ORDER BY t.effective_from", $supplierId), $this->explain($protocol));
    }

    /**
     * Zákonná evidence má účinnost po celých měsících. Vztah s nástupem uprostřed měsíce
     * ji dřív nedostal vůbec: uložení celé evidence odmítlo den nástupu jako začátek řady.
     */
    public function testStatutoryEvidenceStartsAtMonthOfMidMonthStart(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        foreach (['payroll_person_tax_residences', 'payroll_person_social_jurisdictions', 'payroll_person_tax_declarations'] as $table) {
            self::assertSame([['2025-01-01']], $this->fetch("SELECT MIN(x.effective_from) FROM {$table} x
                JOIN payroll_employments e ON e.employee_id = x.employee_id AND e.supplier_id = x.supplier_id WHERE e.supplier_id = ? AND e.code = '5'", $supplierId),
                $table . ' ' . $this->explain($protocol));
        }
    }

    /** Změna zdravotní pojišťovny v PREMIER se převede jako historie, ne jen poslední stav. */
    public function testHealthInsurerHistory(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['111', '2025-01-01', '2025-06-30'], ['201', '2025-07-01', null]], $this->fetch("SELECT h.insurer_code, h.effective_from, h.effective_to
            FROM payroll_person_health_coverage_history h JOIN payroll_employments e ON e.employee_id = h.employee_id AND e.supplier_id = h.supplier_id
            WHERE e.supplier_id = ? AND e.code = '5' ORDER BY h.effective_from", $supplierId), $this->explain($protocol));
    }

    /** Adresa se státem zapsaným názvem (mimo české varianty) se dřív nezapsala vůbec. */
    public function testForeignResidenceCountryFromName(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['residence', 'SK']], $this->fetch("SELECT a.address_type, a.country_code FROM payroll_person_addresses a
            JOIN payroll_employments e ON e.employee_id = a.employee_id AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '6'", $supplierId),
            $this->explain($protocol));
    }

    /** Korespondenční adresa z `PER_ADR` se doplní vedle trvalé. */
    public function testMailingAddressFromAdditionalAddresses(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_detail' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([['residence', '60200', 'CZ'], ['mailing', '77900', 'CZ']], $this->fetch("SELECT a.address_type, a.postal_code, a.country_code FROM payroll_person_addresses a
            JOIN payroll_employments e ON e.employee_id = a.employee_id AND e.supplier_id = a.supplier_id WHERE e.supplier_id = ? AND e.code = '5' ORDER BY a.address_type", $supplierId),
            $this->explain($protocol));
    }

    public function testLedgerMismatchIsAWarningNotAnError(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true, 'payroll_mismatch' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('warning', self::step($protocol, 'payroll')['status'] ?? null);
        $messages = array_values(array_filter(self::step($protocol, 'payroll')['messages'], static fn (array $m): bool => $m['code'] === 'payroll_ledger_diff'));
        self::assertCount(1, $messages, $this->explain($protocol));
        self::assertSame('2025-05', $messages[0]['context']['period'] ?? null);
        self::assertFalse($protocol->get('payroll_reconciliation')[0]['ok']);
        self::assertSame(1, self::stepCounts($protocol, 'payroll')['reconciliation_diffs'] ?? 0);
    }

    public function testWithoutPayrollModuleAccountingIsImportedAndPayrollSkipped(): void
    {
        $supplierId = $this->supplier(false);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('payroll_module_missing', array_column($protocol->get('preflight'), 'code'));
        self::assertContains('payroll_module_missing', $this->messageCodes($protocol));
        self::assertSame(16, self::stepCounts($protocol, 'payroll')['months_skipped'] ?? 0, $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertTrue($protocol->get('payroll_reconciliation')[0]['ok'], 'Rekonciliace mezd proti deníku běží i bez modulu Mzdy.');
    }

    public function testDryRunWritesNothing(): void
    {
        $supplierId = $this->supplier(true);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['payroll' => true]), SyntheticPremierBackup::YEAR1, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'payroll')['employees_created'] ?? 0, $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind LIKE 'payroll%'", $supplierId));
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . md5((string) json_encode($flags));
        if (!is_dir($dir)) {
            PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . DIRECTORY_SEPARATOR . 'zaloha.icab', $this->tmp, false, $flags), $dir);
        }
        return PremierBackup::open($dir);
    }

    /**
     * Záloha s mzdami, kde jednatelka má v 9-11/2025 srážku 1 500 Kč (`SR_VYZI`) a v 11/2025
     * vyloučenou dobu 5 dnů (`VYL_DND`). Syntetická data jen tohoto testu.
     */
    private function backupWithDeductions(): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_deductions';
        SyntheticPremierBackup::writeDir($dir, false, ['payroll' => true]);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, ['payroll' => true])['MZDY'];
        $fields[] = ['SR_VYZI', 'N', 12, 2];
        foreach ($rows as $i => $row) {
            if ($row['INTER'] === 1 && $row['ROK'] === 2025 && $row['MESIC'] >= 9 && $row['MESIC'] <= 11) {
                $rows[$i]['SR_VYZI'] = 1500;
            }
            if ($row['INTER'] === 1 && $row['ROK'] === 2025 && $row['MESIC'] === 11) {
                $rows[$i]['VYL_DND'] = 5;
            }
        }
        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'MZDY.DBF', $fields, $rows);
        return PremierBackup::open($dir);
    }

    /** Izolovaná firma; `$payroll` = zapnuté mzdy, výchozí účtárna a začátek vedení mezd 2/2026. */
    private function supplier(bool $payroll): int
    {
        $pdo = $this->db->pdo();
        $ico = SyntheticPremierBackup::ICO;
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
        if (!$payroll) {
            return $id;
        }
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$id]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-02-01", ?, NOW())',
        )->execute([$id, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "PRM", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$id]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2025-01-01", "1234567890", "synthetic:premier-payroll")',
        )->execute([$id, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$id, $officeId]);
        return $id;
    }

    private function scalar(string $sql, int ...$params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<list<mixed>> */
    private function fetch(string $sql, int ...$params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $row), $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @return array<string,mixed> */
    private static function step(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        return [];
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        return self::step($protocol, $key)['counts'] ?? [];
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
