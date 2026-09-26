<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrka převedeného roku z PREMIER (krok `closing`) nad skutečnou DB: rok uzavřený
 * v PREMIER (podané DPPO `D_PO1` nebo celý rok zamčený v `PERIODY`) se v MyÚčtu uzavře
 * průvodcem uzávěrky a další rok naváže jediným otevíracím zápisem. Co by uzávěrka musela
 * zaúčtovat navíc, vrátí celý krok zpět. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierClosingImportTest extends TestCase
{
    private const Y1 = SyntheticPremierBackup::YEAR1;
    private const Y2 = SyntheticPremierBackup::YEAR2;

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
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PremierImporter::class);
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_close_' . bin2hex(random_bytes(5));
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

    /**
     * Podané DPPO za 2025: převod 2025 rok uzavře a otevření 2026 založí období i otevírací
     * zápis. Převod 2026 ho převezme (preflight ho nepočítá jako cizí zápis), rok 2026 zůstane
     * otevřený. Opakované převody nic nezdvojí.
     */
    public function testFiledDppoClosesYearAndNextYearKeepsSingleOpening(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(['dppo' => true]);

        $first = $this->convert($supplierId, $backup, self::Y1);
        self::assertSame(1, self::stepCounts($first, 'closing')['closed'] ?? 0, $this->explain($first));
        self::assertContains('year_closed', $this->codes($first, 'closing'));
        self::assertSame('closed', $this->periodStatus($supplierId, self::Y1));
        self::assertSame(1, $this->entries($supplierId, self::Y1, 'closing'));
        // openNext založil rok 2026 i s otevíracím zápisem, ještě před převodem roku 2026.
        self::assertSame('open', $this->periodStatus($supplierId, self::Y2));
        self::assertSame(1, $this->entries($supplierId, self::Y2, 'opening'));
        $opening = $this->openingId($supplierId, self::Y2);

        $second = $this->convert($supplierId, $backup, self::Y2);
        self::assertNotContains('journal_not_empty', array_column((array) $second->get('preflight'), 'code'), 'Otevírací zápis z uzávěrky není cizí zápis.');
        self::assertContains('opening_kept', $this->codes($second, 'journal'), $this->explain($second));
        self::assertSame(1, $this->entries($supplierId, self::Y2, 'opening'));
        self::assertSame($opening, $this->openingId($supplierId, self::Y2), 'Otevírací zápis z uzávěrky zůstal, nezaložil se druhý.');
        self::assertSame('open', $this->periodStatus($supplierId, self::Y2));
        self::assertContains('year_open_in_premier', $this->codes($second, 'closing'));
        self::assertSame(0, $this->entries($supplierId, self::Y2, 'closing'));

        // Opakovaný převod obou let: uzavřený rok se nemění, žádná druhá uzávěrka.
        $before = $this->snapshot($supplierId);
        $again = $this->convert($supplierId, $backup, self::Y1, false);
        self::assertContains('year_locked', $this->codes($again, 'journal'), $this->explain($again));
        self::assertArrayNotHasKey('closed', self::stepCounts($again, 'closing'));
        $this->convert($supplierId, $backup, self::Y2);
        self::assertSame($before, $this->snapshot($supplierId));
        self::assertSame([1, 1, 'closed'], [$this->entries($supplierId, self::Y1, 'closing'), $this->entries($supplierId, self::Y2, 'opening'), $this->periodStatus($supplierId, self::Y1)]);
    }

    /**
     * Oba roky převedené dřív (bez podaného DPPO), pak znovu rok 2025 ze zálohy s podaným
     * DPPO: uzávěrka převezme otevírací zápis 2026 z převodu, protože sedí účet po účtu.
     */
    public function testClosingTakesOverImportersOpeningOfNextYear(): void
    {
        $supplierId = $this->supplier();
        $plain = $this->backup([]);
        $this->convert($supplierId, $plain, self::Y1);
        $this->convert($supplierId, $plain, self::Y2);
        self::assertSame(1, $this->entries($supplierId, self::Y2, 'opening'));
        $opening = $this->openingId($supplierId, self::Y2);

        $closed = $this->convert($supplierId, $this->backup(['dppo' => true]), self::Y1);
        self::assertSame(1, self::stepCounts($closed, 'closing')['closed'] ?? 0, $this->explain($closed));
        self::assertSame(['closed', 1], [$this->periodStatus($supplierId, self::Y1), $this->entries($supplierId, self::Y1, 'closing')]);
        self::assertSame(1, $this->entries($supplierId, self::Y2, 'opening'), 'Otevírací zápis z převodu se převzal, nezdvojil.');
        self::assertSame($opening, $this->openingId($supplierId, self::Y2));

        $this->convert($supplierId, $plain, self::Y2);
        self::assertSame([1, $opening], [$this->entries($supplierId, self::Y2, 'opening'), $this->openingId($supplierId, self::Y2)]);
    }

    public function testFullyLockedPeriodsCloseTheYear(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, $this->backup(['periody' => true]), self::Y1);
        self::assertSame(1, self::stepCounts($protocol, 'closing')['closed'] ?? 0, $this->explain($protocol));
        self::assertSame(['closed', 1], [$this->periodStatus($supplierId, self::Y1), $this->entries($supplierId, self::Y1, 'closing')]);
    }

    public function testElevenLockedMonthsLeaveTheYearOpen(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, $this->backup(['periody_11' => true]), self::Y1);
        self::assertContains('year_open_in_premier', $this->codes($protocol, 'closing'), $this->explain($protocol));
        self::assertSame(['open', 0], [$this->periodStatus($supplierId, self::Y1), $this->entries($supplierId, self::Y1, 'closing')]);
    }

    public function testYearWithoutFiledReturnOrLocksStaysOpen(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, $this->backup([]), self::Y1);
        self::assertContains('year_open_in_premier', $this->codes($protocol, 'closing'));
        self::assertArrayNotHasKey('closed', self::stepCounts($protocol, 'closing'));
        self::assertSame(['open', 0, null], [$this->periodStatus($supplierId, self::Y1), $this->entries($supplierId, self::Y1, 'closing'), $this->periodStatus($supplierId, self::Y2)]);
    }

    /**
     * Pohyby karty v PREMIER neoznačují odpisy 2025 jako zaúčtované, i když v deníku jsou:
     * hromadné účtování odpisů by je zaúčtovalo podruhé. Uzávěrka se celá vrátí (savepoint),
     * rok zůstane otevřený a po pokusu nezůstane nic.
     */
    public function testClosingThatWouldBookDepreciationIsRolledBack(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, $this->backup(['dppo' => true, 'maj_h' => true, 'maj_h_unbooked' => true]), self::Y1);

        self::assertSame(1, self::stepCounts($protocol, 'assets')['created'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('accounting_depreciation_booked', self::stepCounts($protocol, 'assets'));
        self::assertContains('closing_not_done', $this->codes($protocol, 'closing'), $this->explain($protocol));
        self::assertStringContainsString('hromadné účtování odpisů', (string) json_encode($protocol->toArray()['steps'], JSON_UNESCAPED_UNICODE), 'Důvod: odpisy, ne jiná chyba.');
        self::assertArrayNotHasKey('closed', self::stepCounts($protocol, 'closing'));
        self::assertSame('open', $this->periodStatus($supplierId, self::Y1));
        self::assertSame(0, $this->entries($supplierId, self::Y1, 'closing'));
        self::assertNull($this->periodStatus($supplierId, self::Y2), 'Otevření dalšího roku se nespustilo.');
        // Po pokusu nic: žádný účetní odpis, žádný zápis mimo převod, žádný stav průvodce.
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ? AND kind = 'accounting'", $supplierId));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ? AND kind = 'tax' AND status = 'confirmed'", $supplierId),
            'Daňový odpis z převodu majetku (mimo uzávěrku) zůstává.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = ? AND e.source_type <> 'opening'
            AND NOT EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)", $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id = ?', $supplierId));
        self::assertTrue($protocol->get('reconciliation')[0]['ok'] ?? false, $this->explain($protocol));
    }

    public function testDryRunClosesInsideTheTransactionButLeavesNothing(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['dppo' => true]), self::Y1, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'closing')['closed'] ?? 0, $this->explain($protocol));
        self::assertSame($before, $this->snapshot($supplierId));
        self::assertNull($this->periodStatus($supplierId, self::Y1));
    }

    /** Převod roku bez chyb a s rekonciliací na haléř. */
    private function convert(int $supplierId, PremierBackup $backup, int $year, bool $reconciled = true): ImportProtocol
    {
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, $year, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        if ($reconciled) {
            $reconciliation = $protocol->get('reconciliation');
            self::assertCount(1, $reconciliation, $this->explain($protocol));
            self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
        }
        return $protocol;
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . md5((string) json_encode($flags));
        if (!is_dir($dir)) {
            SyntheticPremierBackup::writeDir($dir, false, $flags);
        }
        return PremierBackup::open($dir);
    }

    private function supplier(): int
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
        return $id;
    }

    private function periodStatus(int $supplierId, int $year): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $stmt->execute([$supplierId, $year]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    private function entries(int $supplierId, int $year, string $source): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND p.fiscal_year = ? AND e.source_type = ? AND e.reversed_by IS NULL'
        );
        $stmt->execute([$supplierId, $year, $source]);
        return (int) $stmt->fetchColumn();
    }

    private function openingId(int $supplierId, int $year): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND p.fiscal_year = ? AND e.source_type = 'opening' AND e.reversed_by IS NULL"
        );
        $stmt->execute([$supplierId, $year]);
        return (int) $stmt->fetchColumn();
    }

    private function scalar(string $sql, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'accounting_closing_steps', 'purchase_invoices', 'invoices',
            'clients', 'bank_statements', 'payment_matches', 'depreciation_entries'] as $t) {
            $out[$t] = $this->scalar("SELECT COUNT(*) FROM {$t} WHERE supplier_id = ?", $supplierId);
        }
        $out['closed_periods'] = $this->scalar("SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ? AND status = 'closed'", $supplierId);
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
    private function codes(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return array_column($step['messages'] ?? [], 'code');
            }
        }
        return [];
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
