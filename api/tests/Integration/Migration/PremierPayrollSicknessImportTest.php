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
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Sickness\SicknessImportXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Podání nemocenských dávek odeslaná z PREMIER (`MZ_VREP`, NEMPRI a HZUPN) a ČSSZ přijatá se při
 * převodu mezd převezmou produktovým importem: k převzatému vztahu vznikne případ dávky vyřízený
 * předchozím programem. Podání odmítnuté ČSSZ ani podání odeslané po převáděném období se nezapíše,
 * věta osoby, kterou převod nezná, osobu nezaloží a opakovaný převod nic nezdvojí. Izolovaná firma,
 * transakce s rollbackem.
 */
#[Group('integration')]
final class PremierPayrollSicknessImportTest extends TestCase
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
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PremierImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        foreach (['premier_import_map', 'payroll_employments', 'payroll_offices', 'payroll_sickness_cases'] as $table) {
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_sick_int_' . bin2hex(random_bytes(5));
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

    public function testAcceptedSicknessSubmissionsBecomePredecessorCasesAndRerunChangesNothing(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backupWithSicknessSubmissions();

        // Rok 2025: všechna podání jsou odeslaná v roce 2026 a později, nic se nezapíše.
        $early = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($early->hasErrors(), $this->explain($early));
        $counts = self::stepCounts($early, 'payroll');
        self::assertSame([4, 1, 3, 0], [$counts['benefits_files'] ?? 0, $counts['benefits_files_rejected'] ?? 0, $counts['benefits_later'] ?? 0,
            $counts['benefits_applied'] ?? 0], $this->explain($early));
        self::assertSame(0, $this->caseCount($supplierId));

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $counts = self::stepCounts($first, 'payroll');
        // Přijaté ošetřovné Karla Vzorového, přijaté podání osoby, kterou převod nezná, odmítnuté podání a podání z roku 2027.
        self::assertSame([4, 1, 3, 1, 1, 1], [$counts['benefits_files'] ?? 0, $counts['benefits_files_rejected'] ?? 0, $counts['benefits_sentences'] ?? 0,
            $counts['benefits_applied'] ?? 0, $counts['benefits_blocked'] ?? 0, $counts['benefits_later'] ?? 0], $this->explain($first));
        self::assertArrayNotHasKey('benefits_failed', $counts, $this->explain($first));
        self::assertContains('benefits_imported', $this->messageCodes($first));
        self::assertContains('benefit_blocked', $this->messageCodes($first));

        $case = $this->db->pdo()->prepare(
            "SELECT c.benefit_kind, c.source, c.nempri_status, c.incapacity_from, c.incapacity_to, c.decision_number
               FROM payroll_sickness_cases c JOIN payroll_employments e ON e.id = c.employment_id
              WHERE c.supplier_id = ? AND e.code = '3'",
        );
        $case->execute([$supplierId]);
        self::assertSame([[
            'benefit_kind' => 'OSE', 'source' => 'predecessor', 'nempri_status' => 'predecessor',
            'incapacity_from' => '2026-05-04', 'incapacity_to' => '2026-05-08', 'decision_number' => '10278000600075284N',
        ]], $case->fetchAll(\PDO::FETCH_ASSOC), $this->explain($first));
        self::assertSame(1, $this->caseCount($supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ? AND full_name LIKE '%Neznámá%'", $supplierId),
            'Podání osoby, kterou převod nezná, osobu nezaloží.');

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'payroll');
        // Zapsaná věta je v mapě převodu; zablokovaná se zkusí znovu.
        self::assertSame([0, 1, 1], [$counts['benefits_applied'] ?? 0, $counts['benefits_done'] ?? 0, $counts['benefits_blocked'] ?? 0], $this->explain($again));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind = 'payroll_sickness'", $supplierId));
        self::assertSame(1, $this->caseCount($supplierId));
    }

    private function backupWithSicknessSubmissions(): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_sickness';
        SyntheticPremierBackup::writeDir($dir, false, ['payroll' => true]);

        $karel = '9206200102';
        $care = static fn (string $birthNumber, array $o = []): string => SicknessImportXmlFixtures::nempri25('OSE', $birthNumber, $o + [
            'first' => 'Karel', 'last' => 'Vzorový', 'ic' => SyntheticPremierBackup::ICO, 'employmentFrom' => '2026-02-01',
            'roFrom' => '2026-02-01', 'roTo' => '2026-04-30', 'from' => '2026-05-04', 'to' => '2026-05-08', 'decision' => '10278000600075284N',
        ]);
        $unknown = RegistrationXmlFixtures::birthNumber('1990-06-07', 'female', 5);
        $accepted = '<?xml version="1.0" encoding="utf-8"?><answer><result>False</result><accepted>False</accepted></answer>'
            . '<?xml version="1.0" encoding="utf-8"?><answer><result>True</result><accepted>True</accepted><errorText /></answer>';
        $rejected = '<?xml version="1.0" encoding="utf-8"?><answer><result>True</result><accepted>False</accepted><errorText>Neplatná data.</errorText></answer>';

        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'MZ_VREP.DBF', [
            ['ID', 'C', 36], ['TYP_ZPRAVY', 'C', 30], ['DAT_ZPRAVY', 'C', 20], ['STAV', 'N', 2], ['POZNAMKA', 'M'], ['POZNAMKA2', 'M'],
        ], [
            // Odeslané v roce 2027: pozdější než převáděné období 2026.
            ['ID' => 'EEEE0004-0000-0000-0000-000000000004', 'TYP_ZPRAVY' => 'NEMPRI25', 'DAT_ZPRAVY' => '2027-01-05 10:00:00', 'STAV' => 6,
                'POZNAMKA' => '?' . $care($karel, ['from' => '2027-01-04', 'to' => '2027-01-05', 'decision' => '10278000600075285N']), 'POZNAMKA2' => $accepted],
            ['ID' => 'EEEE0001-0000-0000-0000-000000000001', 'TYP_ZPRAVY' => 'NEMPRI25', 'DAT_ZPRAVY' => '2026-05-11 09:00:00', 'STAV' => 6,
                'POZNAMKA' => '?' . $care($karel), 'POZNAMKA2' => $accepted],
            ['ID' => 'EEEE0002-0000-0000-0000-000000000002', 'TYP_ZPRAVY' => 'NEMPRI25', 'DAT_ZPRAVY' => '2026-05-12 09:00:00', 'STAV' => 6,
                'POZNAMKA' => '?' . $care($unknown, ['first' => 'Alena', 'last' => 'Neznámá']), 'POZNAMKA2' => $accepted],
            ['ID' => 'EEEE0003-0000-0000-0000-000000000003', 'TYP_ZPRAVY' => 'NEMPRI25', 'DAT_ZPRAVY' => '2026-05-13 09:00:00', 'STAV' => 7,
                'POZNAMKA' => '?' . $care($karel, ['from' => '2026-06-01', 'to' => '2026-06-03']), 'POZNAMKA2' => $rejected],
        ]);
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
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$id]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-07-01", ?, NOW())',
        )->execute([$id, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "PRM", "Syntetická účtárna", ?, 1)',
        )->execute([$id, SicknessImportXmlFixtures::VARIABLE_SYMBOL]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2025-01-01", ?, "synthetic:premier-payroll")',
        )->execute([$id, $officeId, SicknessImportXmlFixtures::VARIABLE_SYMBOL]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "112")',
        )->execute([$id, $officeId]);
        return $id;
    }

    private function caseCount(int $supplierId): int
    {
        return $this->scalar('SELECT COUNT(*) FROM payroll_sickness_cases WHERE supplier_id = ?', $supplierId);
    }

    private function scalar(string $sql, int ...$params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'] ?? [];
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
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
