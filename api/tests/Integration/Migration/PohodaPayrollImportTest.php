<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd z `91_mzdy.xml` do mezd izolované firmy: osoby a vztahy, měsíční dávky
 * importu, opakovaný převod bez duplicit, zkouška nanečisto bez stop a kontrola před
 * převodem. Transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PohodaPayrollImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        foreach (['payroll_attendance_imports', 'payroll_employees', 'payroll_offices', 'pohoda_import_map'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
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

    public function testImportCreatesPersonsAndMonthsAndIsIdempotent(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS);
        self::assertSame(2, $counts['months'] ?? 0, $this->explain($protocol));
        self::assertSame(4, $counts['payslips'] ?? 0);
        self::assertSame(2, $counts['persons_created'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('persons_failed', $counts, $this->explain($protocol));
        $codes = array_column(array_merge(...array_column($protocol->toArray()['steps'], 'messages')), 'code');
        self::assertContains('person_data_omitted', $codes, $this->explain($protocol));
        self::assertSame(2, $this->rows('payroll_employees', $supplierId));
        $batches = $this->db->pdo()->prepare('SELECT * FROM payroll_attendance_imports WHERE supplier_id = ?');
        $batches->execute([$supplierId]);
        self::assertSame(2, $this->rows('payroll_attendance_imports', $supplierId), json_encode($batches->fetchAll(\PDO::FETCH_ASSOC)) . $this->explain($protocol));
        $map = (new PohodaImportRepository($this->db))->all($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH);
        self::assertCount(2, $map, json_encode($map) . $this->explain($protocol));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(2, self::stepCounts($again, PohodaPayrollImporter::STEP_MONTHS)['existing'] ?? 0);
        self::assertSame(2, $this->rows('payroll_employees', $supplierId));
        self::assertSame(2, $this->rows('payroll_attendance_imports', $supplierId));
    }

    public function testPeopleDetailsIdentifiersTerminationDeadlinesAndOpenings(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $pdo = $this->db->pdo();

        // Bez potvrzení původu se OIČ a ID PPV nepřevezmou, zbytek údajů ano.
        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_PEOPLE);
        self::assertSame(2, $counts['identifiers_unconfirmed'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['person_card'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['tax_residence'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['tax_declarations'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $counts['ended'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('people_failed', $counts, $this->explain($protocol));
        self::assertSame(0, $this->rows('payroll_person_external_ids', $supplierId));

        $jana = $this->employment($supplierId, '1001');
        $petr = $this->employment($supplierId, '1002');
        self::assertSame('ended', $petr['status']);
        self::assertSame(SyntheticPohodaPayroll::PETR_END, $petr['end_date']);

        $identity = $pdo->prepare(
            'SELECT citizenship_country_code, birth_place, title_prefix FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$supplierId, $jana['employee_id']]);
        self::assertSame(['citizenship_country_code' => 'CZ', 'birth_place' => 'Brno', 'title_prefix' => 'Ing.'], $identity->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_addresses WHERE supplier_id = ? AND employee_id = ? AND address_type = 'residence' AND city = 'Brno'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_contacts WHERE supplier_id = ? AND employee_id = ? AND contact_type = 'email' AND is_primary = 1", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_contacts WHERE supplier_id = ? AND employee_id = ? AND contact_type = 'phone' AND is_primary = 1", [$supplierId, $petr['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ? AND residence = 'czech-resident'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? AND status = 'signed' AND effective_from = '2026-01-01'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? AND status = 'not-signed'", [$supplierId, $petr['employee_id']]));
        $terms = $pdo->prepare('SELECT cz_isco_code, jmhz_workplace_municipality_code, work_place FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC LIMIT 1');
        $terms->execute([$supplierId, $jana['id']]);
        self::assertSame(['cz_isco_code' => '43111', 'jmhz_workplace_municipality_code' => '582786', 'work_place' => 'Brno'], $terms->fetch(\PDO::FETCH_ASSOC), $this->explain($protocol));

        // Zákonné termíny: odškrtnuté jen tam, kde PAMICA nese doklad.
        self::assertSame([
            'employment_contract' => 'completed',
            'health_insurance_registration' => 'completed',
            'social_jmhz_registration' => 'completed',
            'tax_declaration' => 'completed',
        ], $this->checklist($supplierId, (int) $jana['id'], 'onboarding'), $this->explain($protocol));
        self::assertSame([
            'employment_contract' => 'completed',
            'health_insurance_registration' => 'pending',
            'social_jmhz_registration' => 'pending',
            'tax_declaration' => 'pending',
        ], $this->checklist($supplierId, (int) $petr['id'], 'onboarding'));
        $offboarding = $this->checklist($supplierId, (int) $petr['id'], 'offboarding');
        self::assertSame('completed', $offboarding['termination_document'] ?? null, json_encode($offboarding) . $this->explain($protocol));
        self::assertSame('completed', $offboarding['health_insurance_deregistration'] ?? null);
        self::assertSame('completed', $offboarding['social_jmhz_deregistration'] ?? null);
        $note = $pdo->prepare("SELECT note FROM payroll_employment_checklist_items WHERE supplier_id = ? AND employment_id = ? AND item_key = 'social_jmhz_registration'");
        $note->execute([$supplierId, $jana['id']]);
        self::assertStringStartsWith('Převzato z PAMICA', (string) $note->fetchColumn());
        // Oznámení ZP nezpracované, ale vztah vznikl před převodem u platné pojišťovny.
        $health = $pdo->prepare("SELECT note FROM payroll_employment_checklist_items WHERE supplier_id = ? AND employment_id = ? AND item_key = 'health_insurance_registration'");
        $health->execute([$supplierId, $jana['id']]);
        self::assertStringContainsString('vztah vznikl před převodem', (string) $health->fetchColumn());
        // Změna měsíční mzdy od února: změnové položky zpracovala PAMICA.
        self::assertSame([
            'contract_amendment' => 'completed',
            'health_insurance_change' => 'completed',
            'social_jmhz_change' => 'completed',
        ], $this->checklist($supplierId, (int) $jana['id'], 'change'), $this->explain($protocol));

        // Sjednaná měsíční mzda z PAMICA: od ledna 40 000 Kč, od února 42 000 Kč.
        $wages = $pdo->prepare('SELECT effective_from, monthly_gross_minor FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from');
        $wages->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['effective_from' => '2025-03-01', 'monthly_gross_minor' => 4000000],
            ['effective_from' => '2026-02-01', 'monthly_gross_minor' => 4200000],
        ], array_map(static fn (array $r): array => ['effective_from' => $r['effective_from'], 'monthly_gross_minor' => (int) $r['monthly_gross_minor']], $wages->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));

        // Pracoviště a CZ-ISCO musí nést KAŽDÁ verze podmínek, ne jen poslední. Opravit jde
        // vždy jen poslední verzi, takže zapsáno až po všech měsících by starší verze zůstaly
        // prázdné a za jejich měsíce by nešlo zmrazit hlášení JMHZ.
        $places = $pdo->prepare(
            'SELECT effective_from, jmhz_workplace_municipality_code AS obec, work_place, cz_isco_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from'
        );
        $places->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['effective_from' => '2025-03-01', 'obec' => '582786', 'work_place' => 'Brno', 'cz_isco_code' => '43111'],
            ['effective_from' => '2026-02-01', 'obec' => '582786', 'work_place' => 'Brno', 'cz_isco_code' => '43111'],
        ], array_map(static fn (array $r): array => [
            'effective_from' => $r['effective_from'],
            'obec' => $r['obec'],
            'work_place' => $r['work_place'],
            'cz_isco_code' => $r['cz_isco_code'],
        ], $places->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));

        // Průměrný výdělek čtvrtletí, nepřítomnost s daty, výplatní účet a účet pojišťovny.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = 'approved' AND average_hourly_minor = 25000", [$supplierId, $jana['id']]), $this->explain($protocol));
        // Dovolenou nese souhrn z importu docházky, proto ji převod nezakládá podruhé.
        // Měsíc se souhrnem a zároveň nepřítomností s daty by tutéž dobu vedl dvakrát
        // a krácení měsíční mzdy by se neprovedlo vůbec.
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'vacation'", [$supplierId, $jana['id']]), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_PEOPLE)['absences_from_import'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND employee_id = ? AND is_active = 1', [$supplierId, $jana['employee_id']]));
        // Účet, na který PAMICA opakovaně vyplácela mzdu, je ověřený dnem POSLEDNÍ výplaty
        // (10. 3. za únor), ne dnem převodu. Bez ověření brání značka podání i příkazu.
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND verification_source = 'user_verified'
                AND verified_on = '2026-03-10' AND verified_by = ?",
            [$supplierId, $jana['employee_id'], $this->userId],
        ), $this->explain($protocol));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_employee_profiles WHERE supplier_id = ? AND employee_id = ? AND payout_method = 'bank'", [$supplierId, $jana['employee_id']]));
        // Předpis základní měsíční mzdy dostane jen měsíčně placený vztah, ne Petrova DPP za hodiny.
        // Souvislá řada bez děr: první předpis platí od prvního převáděného měsíce, druhý
        // od měsíce, kdy PAMICA mzdu zvedla, a předchozí končí dnem předtím.
        $prescriptions = $pdo->prepare(
            "SELECT r.valid_from, r.valid_to, r.amount_minor, r.allocation_rule
               FROM payroll_recurring_components r
               JOIN payroll_component_definitions c ON c.id = r.component_id
              WHERE r.supplier_id = ? AND r.employment_id = ? AND c.code = 'MZDA_MESICNI'
              ORDER BY r.valid_from"
        );
        $prescriptions->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['valid_from' => '2026-01-01', 'valid_to' => '2026-01-31', 'amount_minor' => 4000000, 'allocation_rule' => 'calendar_days'],
            ['valid_from' => '2026-02-01', 'valid_to' => null, 'amount_minor' => 4200000, 'allocation_rule' => 'calendar_days'],
        ], array_map(static fn (array $r): array => [
            'valid_from' => $r['valid_from'],
            'valid_to' => $r['valid_to'],
            'amount_minor' => (int) $r['amount_minor'],
            'allocation_rule' => $r['allocation_rule'],
        ], $prescriptions->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ?', [$supplierId, $petr['id']]));
        // Rodičovská dovolená z PAMICA (H08) se přenese jako absence, i když hodiny nenese.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'parental' AND date_from = '2026-02-01' AND date_to = '2026-02-28'", [$supplierId, $petr['id']]), $this->explain($protocol));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_institution_accounts a JOIN payroll_institutions i ON i.id = a.institution_id WHERE a.supplier_id = ? AND i.institution_type = 'health_insurer' AND i.institution_code = '111' AND a.variable_symbol = '12345678'", [$supplierId]), $this->explain($protocol));

        // Dítě s daňovým zvýhodněním na 1. dítě; sleva na poplatníka (kód 36) dítětem není.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_dependants WHERE supplier_id = ? AND employee_id = ?', [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_child_claims WHERE supplier_id = ? AND employee_id = ? AND child_order = 1 AND evidence_status = 'verified' AND effective_from = '2026-01-01'", [$supplierId, $jana['employee_id']]), $this->explain($protocol));

        // Příslušnost k sociálnímu pojištění a sleva pracujícího důchodce po měsících.
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM payroll_person_social_jurisdictions WHERE supplier_id = ? AND jurisdiction = 'czech_regime_verified' AND a1_status = 'not_applicable'", [$supplierId]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_social_discount_claims WHERE supplier_id = ? AND employee_id = ? AND status = 'not_claimed'", [$supplierId, $jana['employee_id']]));
        $discounts = $pdo->prepare('SELECT status, effective_from, effective_to FROM payroll_person_social_discount_claims WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from');
        $discounts->execute([$supplierId, $petr['employee_id']]);
        self::assertSame([
            ['status' => 'not_claimed', 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'],
            ['status' => 'verified', 'effective_from' => '2026-02-01', 'effective_to' => null],
        ], $discounts->fetchAll(\PDO::FETCH_ASSOC));

        // S potvrzením se uloží platné OIČ a ID PPV; OIČ s chybnou kontrolní číslicí ne.
        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_PEOPLE);
        self::assertSame(1, $counts['oic'] ?? 0, $this->explain($again));
        self::assertSame(2, $counts['id_ppv'] ?? 0, $this->explain($again));
        self::assertSame(1, $counts['oic_invalid'] ?? 0, $this->explain($again));
        self::assertArrayNotHasKey('person_card', $counts, 'Opakovaný převod nesmí kartu osoby zapisovat znovu.');
        self::assertSame(1, $this->rows('payroll_person_external_ids', $supplierId));
        self::assertSame(2, $this->rows('payroll_employment_external_ids', $supplierId));

        // Mzdy vedené v MyÚčtu od února: leden z PAMICA jako počáteční stav kumulací.
        $pdo->prepare("UPDATE payroll_module_state SET start_period = '2026-02-01' WHERE supplier_id = ?")->execute([$supplierId]);
        $third = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true);
        self::assertSame(2, self::stepCounts($third, PohodaPayrollImporter::STEP_PEOPLE)['openings'] ?? 0, $this->explain($third));
        $opening = $pdo->prepare("SELECT values_json FROM payroll_statutory_accumulator_openings WHERE supplier_id = ? AND employee_id = ? AND calculation_kind = 'social_insurance'");
        $opening->execute([$supplierId, $jana['employee_id']]);
        self::assertSame(4300000, json_decode((string) $opening->fetchColumn(), true)['assessment_base_minor_units'] ?? null);
        $tax = $pdo->prepare("SELECT values_json FROM payroll_statutory_accumulator_openings WHERE supplier_id = ? AND employee_id = ? AND calculation_kind = 'income_tax'");
        $tax->execute([$supplierId, $jana['employee_id']]);
        $values = json_decode((string) $tax->fetchColumn(), true);
        self::assertSame(1, $values['completed_months'] ?? null);
        self::assertSame(388000, $values['advance_tax_minor_units'] ?? null);
        self::assertSame(257000, $values['applied_non_refundable_credits_minor_units'] ?? null);
        $tax->execute([$supplierId, $petr['employee_id']]);
        self::assertSame(75000, json_decode((string) $tax->fetchColumn(), true)['withholding_tax_minor_units'] ?? null);

        $fourth = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true);
        self::assertSame(2, self::stepCounts($fourth, PohodaPayrollImporter::STEP_PEOPLE)['openings_existing'] ?? 0, $this->explain($fourth));
        // Dvě osoby krát tři druhy kumulace (sociální, zdravotní, daň z příjmů).
        self::assertSame(6, $this->rows('payroll_statutory_accumulator_openings', $supplierId));
    }

    /**
     * Regrese: účty, které založil starší běh převodu (ten ověřovat ještě neuměl), musí
     * opakovaný převod doplnit. Dřív krok skončil holým `return []`, jakmile osoba nějaký
     * účet měla — ověření se proto nedoplnilo NIKDY a opakovaný import, kterým to jde
     * přirozeně zkusit, nechal evidenci přesně tak, jak byla. Ověřený účet je přitom
     * podmínka bankovního příkazu (mezera `payout_account`).
     */
    public function testRepeatedImportVerifiesAccountsLeftUnverifiedByEarlierRun(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $verified = $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        );
        self::assertGreaterThan(0, $verified, $this->explain($protocol));

        // Stav instalace po starším převodu: účty jsou založené, ověření u nich chybí.
        $this->db->pdo()
            ->prepare('UPDATE payroll_person_accounts SET verification_source = NULL, verified_on = NULL, verified_by = NULL WHERE supplier_id = ?')
            ->execute([$supplierId]);
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        ));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($verified, $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        ), $this->explain($again));
        // Datum nese den poslední výplaty z PAMICA, ne den opakovaného převodu.
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_accounts
              WHERE supplier_id = ? AND verification_source = 'user_verified'
                AND verified_on = '2026-03-10' AND verified_by = ?",
            [$supplierId, $this->userId],
        ), $this->explain($again));
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->payrollSupplier();
        $protocol = $this->importer->run($supplierId, $this->userId, SyntheticPohodaPayroll::write($this->tmp), SyntheticPohodaPayroll::YEAR, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS)['months'] ?? 0);
        self::assertSame(0, $this->rows('payroll_employees', $supplierId));
        self::assertSame(0, $this->rows('payroll_attendance_imports', $supplierId));
        self::assertSame(0, $this->rows('pohoda_import_map', $supplierId));
        self::assertTrue($this->db->pdo()->inTransaction(), 'Zkouška nanečisto nesmí vrátit vnější transakci.');
    }

    public function testPreflightRequiresPayrollModuleAndOffice(): void
    {
        $supplierId = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$supplierId]);
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $codes = array_column($this->importer->preflight($supplierId, $file, SyntheticPohodaPayroll::YEAR), 'code');
        self::assertContains('payroll_disabled', $codes);
        self::assertContains('payroll_office_missing', $codes);
        self::assertContains('payroll_no_months', array_column($this->importer->preflight($supplierId, $file, 2025), 'code'));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(0, $this->rows('payroll_employees', $supplierId));
    }

    /** Izolovaná firma se zapnutými mzdami a výchozí účtárnou (stejně jako test importu docházky). */
    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:pohoda-payroll")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);
        return $supplierId;
    }

    /** @return array<string,mixed> */
    private function employment(int $supplierId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id, status, end_date FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vztah {$code} nevznikl.");
        return $row;
    }

    /** @return array<string,string> položka => stav */
    private function checklist(int $supplierId, int $employmentId, string $phase): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_key, status FROM payroll_employment_checklist_items WHERE supplier_id = ? AND employment_id = ? AND phase = ? ORDER BY item_key'
        );
        $stmt->execute([$supplierId, $employmentId, $phase]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function rows(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
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

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
