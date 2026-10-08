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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Registrace ČSSZ odeslané z PREMIER (`MZ_VREP`) a ČSSZ přijaté se při převodu mezd převezmou
 * produktovým importem registrací: přijatá věta zapíše identifikátory a profil přihlášky A1,
 * věta odmítnutá příjemcem, věta v odmítnutém podání ani věta pozdější než převáděné období
 * se nezapíše a opakovaný převod nic nezdvojí. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierPayrollRegistrationsImportTest extends TestCase
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
        foreach (['premier_import_map', 'payroll_employments', 'payroll_offices', 'payroll_registration_a1_profiles', 'payroll_employment_external_ids'] as $table) {
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_reg_int_' . bin2hex(random_bytes(5));
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

    public function testAcceptedSentencesAreImportedByProductImportAndRerunChangesNothing(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backupWithSubmissions();

        // Rok 2025: všechny věty jsou z roku 2026, vztah Karla Vzorového ještě neexistuje - nic se nezapíše.
        $early = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($early->hasErrors(), $this->explain($early));
        $counts = self::stepCounts($early, 'payroll');
        self::assertSame([3, 1, 1, 3, 0], [$counts['registrations_files'] ?? 0, $counts['registrations_files_rejected'] ?? 0, $counts['registrations_sentences_rejected'] ?? 0,
            $counts['registrations_later'] ?? 0, $counts['registrations_applied'] ?? 0], $this->explain($early));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ?', $supplierId));

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $counts = self::stepCounts($first, 'payroll');
        // Soubory: přijatý se třemi větami (jednu ČSSZ odmítla, jedna je osoba, kterou převod nezná), odmítnutý celý
        // a přijatý s dohlášením z roku 2027.
        self::assertSame([3, 1, 1, 3, 1, 1, 1], [$counts['registrations_files'] ?? 0, $counts['registrations_files_rejected'] ?? 0, $counts['registrations_sentences_rejected'] ?? 0,
            $counts['registrations_sentences'] ?? 0, $counts['registrations_applied'] ?? 0, $counts['registrations_later'] ?? 0, $counts['registrations_unmatched'] ?? 0],
            $this->explain($first));
        self::assertArrayNotHasKey('registrations_failed', $counts, $this->explain($first));
        self::assertArrayNotHasKey('registrations_blocked', $counts, $this->explain($first));
        self::assertContains('registrations_imported', $this->messageCodes($first));

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_employment_external_ids i JOIN payroll_employments e ON e.id = i.employment_id
            WHERE i.supplier_id = ? AND e.code = '3' AND i.identifier_type = 'id_ppv'", $supplierId), $this->explain($first));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ?', $supplierId), $this->explain($first));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ? AND full_name LIKE 'Odmítnutá%'", $supplierId),
            'Věta, kterou ČSSZ odmítla, ani věta v odmítnutém podání osobu nezaloží.');
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ? AND full_name LIKE 'Zamítnutá%'", $supplierId));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ? AND full_name LIKE 'Neznámá%'", $supplierId),
            'Věta, ke které převod nezná vztah, osobu ani vztah nezaloží.');
        self::assertContains('registration_unmatched', $this->messageCodes($first));

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'payroll');
        // Zapsaná věta je v mapě převodu a znovu se nepřepisuje; věta bez vztahu se zkusí znovu.
        self::assertSame([0, 1, 1, 0], [$counts['registrations_applied'] ?? 0, $counts['registrations_done'] ?? 0, $counts['registrations_unmatched'] ?? 0,
            $counts['registrations_unchanged'] ?? 0], $this->explain($again));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind = 'payroll_registration'", $supplierId));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ?', $supplierId));
    }

    public function testBackupWithoutSubmissionTableSkipsTheStep(): void
    {
        $supplierId = $this->supplier();
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_plain';
        SyntheticPremierBackup::writeDir($dir, false, ['payroll' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, PremierBackup::open($dir), SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertArrayNotHasKey('registrations_files', self::stepCounts($protocol, 'payroll'));
        self::assertNotContains('registrations_imported', $this->messageCodes($protocol));
    }

    private function backupWithSubmissions(): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_submissions';
        SyntheticPremierBackup::writeDir($dir, false, ['payroll' => true]);

        $karel = ['bno' => '9206200102', 'first' => 'Karel', 'last' => 'Vzorový', 'tit' => null, 'birth_date' => '1992-06-20', 'birth_surname' => 'Vzorový',
            'birth_place' => 'Ostrava', 'sex' => 'M', 'start' => '2026-02-01', 'insurer' => '201', 'oid' => '2000000000321', 'street' => 'Pokusná', 'num' => '7',
            'pnu' => '70200', 'city' => 'Ostrava'];
        // Osoby, které by věty založily, kdyby je převod přijal: nesmí vzniknout.
        $rejectedSentence = ['bno' => RegistrationXmlFixtures::birthNumber('1991-02-03', 'male', 3), 'first' => 'Petr', 'last' => 'Odmítnutá', 'birth_date' => '1991-02-03',
            'sex' => 'M', 'start' => '2026-03-01', 'oid' => '2000000000322'];
        // Přijatá věta osoby, kterou převod nezná (PREMIER ji eviduje jinak): vztah ani osoba nevzniknou.
        $unknown = ['bno' => RegistrationXmlFixtures::birthNumber('1990-06-07', 'female', 5), 'first' => 'Alena', 'last' => 'Neznámá', 'birth_date' => '1990-06-07',
            'start' => '2026-03-15'];
        $rejectedFile = ['bno' => RegistrationXmlFixtures::birthNumber('1993-04-05', 'female', 4), 'first' => 'Eva', 'last' => 'Zamítnutá', 'birth_date' => '1993-04-05',
            'start' => '2026-03-01', 'oid' => '2000000000323'];

        $accepted = static fn (string $items): string => '<?xml version="1.0" encoding="utf-8"?><answer><result>True</result><accepted>True</accepted><errorText />'
            . '<dataError>&lt;ProcessingResult count="2"&gt;&lt;Details&gt;&lt;Item sqnr="" identifier="" subtype="REGZEC25" result="OK" /&gt;'
            . $items . '&lt;/Details&gt;&lt;/ProcessingResult&gt;</dataError></answer>';
        $item = static fn (int $sqnr, string $result): string => '&lt;Item sqnr="' . $sqnr . '" identifier="" subtype="REGZEC25" result="' . $result . '" /&gt;';
        $rejected = '<?xml version="1.0" encoding="utf-8"?><answer><result>True</result><accepted>False</accepted><errorText>REGZEC25_LT: 103901604 - Překryv.</errorText></answer>';

        $oneFile = RegistrationXmlFixtures::regzecA1($karel);
        $second = RegistrationXmlFixtures::regzecA1($rejectedSentence);
        self::assertSame(1, preg_match('#<employee\b.*?</employee>#s', $second, $employee));
        $twoSentences = str_replace('</employees>', str_replace('sqnr="1"', 'sqnr="2"', $employee[0]) . '</employees>', $oneFile);
        self::assertSame(1, preg_match('#<employee\b.*?</employee>#s', RegistrationXmlFixtures::regzecA1($unknown), $third));
        $threeSentences = str_replace('</employees>', str_replace('sqnr="1"', 'sqnr="3"', $third[0]) . '</employees>', $twoSentences);
        // Dohlášení z roku 2027: pozdější než převáděné období 2026.
        $later = str_replace('dat="2026-09-02"', 'dat="2027-01-05"', RegistrationXmlFixtures::regzecA3($karel['bno'], '2027-01-05', ['insurer' => '111']));

        DbfWriter::write($dir . DIRECTORY_SEPARATOR . 'MZ_VREP.DBF', [
            ['ID', 'C', 36], ['TYP_ZPRAVY', 'C', 30], ['DAT_ZPRAVY', 'C', 20], ['STAV', 'N', 2], ['POZNAMKA', 'M'], ['POZNAMKA2', 'M'],
        ], [
            // Odeslané později, ale v tabulce první: pořadí podle času odeslání.
            ['ID' => 'AAAA0003-0000-0000-0000-000000000003', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2027-01-05 10:00:00', 'STAV' => 6,
                'POZNAMKA' => '?' . $later, 'POZNAMKA2' => $accepted($item(1, 'OK'))],
            ['ID' => 'AAAA0001-0000-0000-0000-000000000001', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-02 09:00:00', 'STAV' => 6,
                'POZNAMKA' => '?' . $threeSentences, 'POZNAMKA2' => $accepted($item(1, 'OK') . $item(2, 'ERROR') . $item(3, 'OK'))],
            ['ID' => 'AAAA0002-0000-0000-0000-000000000002', 'TYP_ZPRAVY' => 'REGZEC25', 'DAT_ZPRAVY' => '2026-07-03 09:00:00', 'STAV' => 7,
                'POZNAMKA' => '?' . RegistrationXmlFixtures::regzecA1($rejectedFile), 'POZNAMKA2' => $rejected],
            // Jiný druh podání se nepřebírá.
            ['ID' => 'AAAA0004-0000-0000-0000-000000000004', 'TYP_ZPRAVY' => 'JMHZ25', 'DAT_ZPRAVY' => '2026-07-04 09:00:00', 'STAV' => 6,
                'POZNAMKA' => '?<jmhz/>', 'POZNAMKA2' => $accepted('')],
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
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
