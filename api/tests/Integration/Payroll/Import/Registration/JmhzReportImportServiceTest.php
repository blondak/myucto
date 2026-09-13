<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationImportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Import měsíčních hlášení JMHZ cizího mzdového programu nad izolovanou
 * syntetickou firmou. Firma „přešla" na MyÚčto od dubna 2026, takže leden až
 * březen jsou měsíce předchozího programu.
 */
#[Group('integration')]
final class JmhzReportImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MODULE_START = '2026-04-01';

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private int $supplierId;
    private int $userId;
    private string $oic;
    private string $idPpv = '200000000000000000101';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->imports = $this->container->get(RegistrationImportService::class);
        if (!$this->db->hasTable('payroll_person_external_ids')) {
            self::markTestSkipped('Chybí tabulka payroll_person_external_ids.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická účetní", "readonly", "cs", 1)'
        )->execute([
            'jmhz-import-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, 'IMPORT', 'Syntetická účtárna', '1234567890', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', ?)"
        )->execute([$this->supplierId, self::MODULE_START]);
        $this->oic = RegistrationXmlFixtures::oic(7);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Kruh: syntetický zdroj → skutečný serializér → import → evidence.
     * Převzaté hodnoty musí odpovídat zdroji a opakovaný import nesmí nic
     * přidat.
     */
    public function testForeignReportsRoundTripIntoEvidence(): void
    {
        [$employeeId, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['JMHZ', 'JMHZ', 'JMHZ'], array_column($preview['files'], 'document_type'));
        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($preview['files'], 'period'));
        self::assertCount(3, $preview['records']);
        $january = $preview['records'][0];
        self::assertSame('JMHZ', $january['document_type'], $this->dump($january));
        self::assertSame('2026-01', $january['period']);
        self::assertSame('matched', $january['match']['status']);
        self::assertSame('id_ppv', $january['match']['matched_by']);
        self::assertSame($employmentId, $january['match']['employment_id']);
        self::assertSame('update', $january['operation'], $this->dump($january));
        self::assertTrue($january['selectable']);
        self::assertNull($january['blocker']);
        $fields = array_column($january['changes'], 'imported', 'field');
        self::assertSame('Brno', $fields['work_place']);
        self::assertSame('582786', $fields['jmhz_workplace_municipality_code']);
        self::assertSame('signed', $fields['tax_declaration']);
        self::assertSame('verified', $fields['tax_credit:taxpayer']);
        self::assertSame('not_claimed', $fields['social_discount']);
        self::assertArrayHasKey('dependant:0', $fields);
        self::assertSame([
            'period' => '2026-01',
            'gross_minor' => 4_000_000,
            'tax_base_minor' => 4_000_000,
            'advance_tax_minor' => 216_300,
            'bonus_minor' => 0,
            'social_base_minor' => 4_000_000,
            'worked_hours' => '168.000',
            'average_hourly_minor' => 23_810,
        ], $january['history']);
        self::assertSame(3, $preview['summary']['update']);
        self::assertContains($employmentId, array_column($preview['employment_options'], 'employment_id'));

        self::assertCount(1, $preview['opening_balances']);
        $opening = $preview['opening_balances'][0];
        self::assertSame('ready', $opening['status'], (string) $opening['reason']);
        self::assertSame($employeeId, $opening['employee_id']);
        self::assertSame(2026, $opening['year']);
        self::assertSame([1, 2, 3], array_column($opening['months'], 'month'));
        self::assertCount(1, $preview['averages']);
        $average = $preview['averages'][0];
        self::assertSame('ready', $average['status'], (string) $average['reason']);
        self::assertSame([2026, 2], [$average['year'], $average['quarter']]);
        self::assertSame(12_000_000, $average['gross_minor']);
        self::assertSame(30_240, $average['worked_minutes']);
        self::assertSame(23_810, $average['average_hourly_minor']);

        $applied = $this->apply($files, array_column($preview['records'], 'key'), openings: true, averages: true);
        $january = $applied['results'][0];
        self::assertSame('applied', $january['status'], (string) $january['message']);
        self::assertNull($january['message'], (string) $january['message']);
        foreach (['terms', 'tax_declaration', 'tax_credit_claims', 'social_discount', 'dependants'] as $operation) {
            self::assertContains($operation, $january['operations']);
        }
        self::assertSame(['saved' => 1, 'skipped' => []], $applied['opening_balances']);
        self::assertSame(['created' => 1, 'approved' => 0, 'skipped' => []], $applied['averages']);
        self::assertSame(['completed' => 0, 'failed' => []], $applied['change_checklist']);

        $reference = 'jmhz-import:' . hash('sha256', base64_decode($files[0]['content_base64'], true))
            . ':' . JmhzReportFixtures::guid(1, 101);
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertCount(1, $terms, 'Leden opravuje verzi od nástupu, další měsíce nic nemění.');
        self::assertSame(
            ['Brno', '582786', 'CZ', 'no', 'no', 'no'],
            [
                $terms[0]['work_place'],
                $terms[0]['jmhz_workplace_municipality_code'],
                $terms[0]['jmhz_workplace_country_code'],
                $terms[0]['jmhz_apz_contribution_status'],
                $terms[0]['jmhz_functional_benefits_status'],
                $terms[0]['jmhz_temporary_assignment_status'],
            ],
        );

        $sections = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class)
            ->editorView($this->supplierId, $employeeId, '2026-03-01')['sections'];
        self::assertSame([['signed', '2026-01-01', null, $reference]], $this->rows($sections['tax_declarations'], ['status']));
        self::assertSame([['taxpayer', '2026-01-01', null, $reference]], $this->rows($sections['tax_credit_claims'], ['credit_kind']));
        self::assertSame([['not_claimed', '2026-01-01', null, null]], $this->rows($sections['social_discount_claims'], ['status']));

        $dependants = $this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-03-01')['dependants'];
        self::assertCount(1, $dependants);
        self::assertSame(['Eliška', 'Testovací', '2018-05-20', 'child_own'], [
            $dependants[0]['given_name'],
            $dependants[0]['family_name'],
            $dependants[0]['birth_date'],
            $dependants[0]['relation'],
        ]);
        self::assertCount(1, $dependants[0]['claims']);
        $claim = $dependants[0]['claims'][0];
        self::assertSame([1, 'claimed', 'verified', '2026-01-01', null, 'none', $reference], [
            $claim['child_order'],
            $claim['credit_status'],
            $claim['evidence_status'],
            $claim['effective_from'],
            $claim['effective_to'],
            $claim['other_household_caregiver_status'],
            $claim['evidence_reference'],
        ]);

        $openings = $this->container->get(PayrollOpeningBalanceService::class)->current($this->supplierId, $employeeId, 2026);
        $expectedMonth = static fn (int $month): array => [
            'month' => $month,
            'social_assessment_base_minor_units' => 4_000_000,
            'advance_base_minor_units' => 4_000_000,
            'advance_tax_minor_units' => 216_300,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_000,
            'applied_child_credit_minor_units' => 126_700,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 4_000_000,
        ];
        self::assertEquals([$expectedMonth(1), $expectedMonth(2), $expectedMonth(3)], $openings['months']);

        $averages = $this->container->get(PayrollAverageEarningRepository::class)->list($this->supplierId, $employmentId);
        self::assertCount(1, $averages);
        self::assertSame(
            [2026, 2, 12_000_000, 30_240, 63, 23_810, 'manual_review'],
            [
                (int) $averages[0]['applicable_year'],
                (int) $averages[0]['applicable_quarter'],
                (int) $averages[0]['gross_earnings_minor'],
                (int) $averages[0]['worked_minutes'],
                (int) $averages[0]['worked_days'],
                (int) $averages[0]['average_hourly_minor'],
                (string) $averages[0]['status'],
            ],
        );

        $again = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['none', 'none', 'none'], array_column($again['records'], 'operation'), $this->dump($again['records']));
        self::assertSame('unchanged', $again['opening_balances'][0]['status']);
        self::assertSame('exists', $again['averages'][0]['status']);
        $second = $this->apply($files, array_column($again['records'], 'key'), openings: true, averages: true);
        self::assertSame(['skipped', 'skipped', 'skipped'], array_column($second['results'], 'status'));
        self::assertSame(0, $second['opening_balances']['saved']);
        self::assertSame(0, $second['averages']['created']);
        self::assertSame(1, $this->tableRows('payroll_dependants'));
        self::assertSame(1, $this->tableRows('payroll_person_tax_child_claims'));
        self::assertSame(1, $this->tableRows('payroll_person_tax_declarations'));
    }

    /**
     * Volba automatického schválení: povinnosti ke změně, které založila
     * nová verze podmínek z importu, se odškrtnou a průměr se rovnou schválí.
     * Povinnosti při nástupu (z dřívější registrace) zůstanou účetní.
     */
    public function testAutoApprovalCompletesImportedChangeDutiesAndApprovesAverages(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
                'work_place' => $month === 1 ? 'Brno' : 'Olomouc',
                'municipality' => $month === 1 ? '582786' : '500496',
            ])], 2026, $month));
        }
        $keys = array_column($this->imports->preview($this->supplierId, 'test', $files)['records'], 'key');
        $onboardingBefore = $this->checklistStatuses($employmentId)['onboarding'] ?? [];

        $applied = $this->apply($files, $keys, averages: true, autoChanges: true, autoAverages: true);

        self::assertContains('applied', array_column($applied['results'], 'status'), $this->dump($applied['results']));
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertCount(2, $terms, 'Únorová změna pracoviště musí založit novou verzi podmínek.');
        $statuses = $this->checklistStatuses($employmentId);
        self::assertArrayNotHasKey('pending', $statuses['change'] ?? [], $this->dump($statuses));
        self::assertGreaterThan(0, $statuses['change']['completed'] ?? 0, $this->dump($statuses));
        self::assertSame(
            ['completed' => $statuses['change']['completed'], 'failed' => []],
            $applied['change_checklist'],
        );
        self::assertSame($onboardingBefore, $statuses['onboarding'] ?? [], 'Nástupní povinnosti import nemění.');

        self::assertSame(1, $applied['averages']['approved'], $this->dump($applied['averages']));
        $averages = $this->container->get(PayrollAverageEarningRepository::class)->list($this->supplierId, $employmentId);
        self::assertSame('approved', (string) $averages[0]['status']);
    }

    public function testUnpairedFormIsAssignedManually(): void
    {
        [$employeeId, $employmentId] = $this->registerEmployee(withIdentifiers: false);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $record = $preview['records'][0];
        self::assertSame('not_found', $record['match']['status'], $this->dump($record));
        self::assertSame('pair_required', $record['operation']);
        self::assertTrue($record['selectable']);
        self::assertSame(1, $preview['summary']['pair_required']);
        self::assertSame($this->oic, array_column($record['changes'], 'imported', 'field')['person_external_identifier']);
        self::assertSame([$employmentId], array_column($preview['employment_options'], 'employment_id'));

        $unpaired = $this->apply($files, [$record['key']]);
        self::assertSame('skipped', $unpaired['results'][0]['status']);
        self::assertStringContainsString('není spárovaný', (string) $unpaired['results'][0]['message']);

        $paired = $this->apply($files, [$record['key']], pairs: [['key' => $record['key'], 'employment_id' => $employmentId]]);
        $result = $paired['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('identifiers', $result['operations']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', $this->idPpv));
        self::assertSame(
            'verified_manual_import',
            $this->container->get(PayrollRegistrationIdentityRepository::class)
                ->activeExternalId($this->supplierId, $employmentId, 'test', 'id_ppv')['source_kind'],
        );

        $again = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('id_ppv', $again['match']['matched_by']);
        self::assertSame('none', $again['operation'], $this->dump($again));
    }

    public function testManualPairConflictingWithAutomaticMatchIsBlocked(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];

        $result = $this->apply($files, [$key], pairs: [['key' => $key, 'employment_id' => $employmentId + 1000]])['results'][0];

        self::assertSame('skipped', $result['status']);
        self::assertStringContainsString('neexistuje', (string) $result['message']);
    }

    /** Opravné podání (O, týž GUID formuláře) nahrazuje řádné téhož měsíce. */
    public function testCorrectionReplacesTheRegularFormOfTheSameMonth(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $person = ['oic' => $this->oic, 'id_ppv' => $this->idPpv];
        $files = [
            $this->file('radne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person($person)], 2026, 1)),
            $this->file('opravne.xml', JmhzReportFixtures::report(
                [JmhzReportFixtures::person($person + ['work_place' => 'Ostrava', 'municipality' => '554821'])],
                2026,
                1,
                ['type' => 'O', 'filled_at' => '2026-02-20T08:00:00Z'],
            )),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        [$regular, $correction] = $preview['records'];
        self::assertSame('none', $regular['operation']);
        self::assertStringContainsString('nahradilo', implode(' ', $regular['warnings']));
        self::assertSame('Měsíční hlášení 2026-01 – opravný formulář', $correction['action_label']);
        self::assertSame('Ostrava', array_column($correction['changes'], 'imported', 'field')['work_place']);

        $applied = $this->apply($files, [$regular['key'], $correction['key']]);
        self::assertSame(['skipped', 'applied'], array_column($applied['results'], 'status'));
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertSame('Ostrava', $terms[0]['work_place']);
    }

    public function testCancelledFormImportsNothing(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [
            $this->file('radne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, 1)),
            $this->file('storno.xml', JmhzReportFixtures::submissionCancellation(2026, 1, 1, '2026-02-25T08:00:00Z')),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(0, $preview['files'][1]['record_count']);
        self::assertSame('S', $preview['files'][1]['submission_type']);
        $record = $preview['records'][0];
        self::assertSame('none', $record['operation']);
        self::assertFalse($record['selectable']);
        self::assertStringContainsString('stornovalo', implode(' ', $record['warnings']));
        self::assertSame([], $preview['opening_balances']);

        $applied = $this->apply($files, [$record['key']], openings: true);
        self::assertSame('skipped', $applied['results'][0]['status']);
        self::assertSame(0, $this->tableRows('payroll_person_tax_declarations'));
        self::assertSame([], $this->container->get(PayrollOpeningBalanceService::class)
            ->current($this->supplierId, $employeeId, 2026)['months']);
    }

    public function testOpeningBalancesAreBlockedWhenTheYearHasApprovedPayroll(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare(
                "INSERT INTO payroll_statutory_accumulator_entries
                    (supplier_id, employee_id, tax_year, period_start, revision_id, calculation_kind,
                     values_json, source_result_hash, record_hash)
                 VALUES (?, ?, 2026, '2026-04-01', 1, 'income_tax', '{}', ?, ?)"
            )->execute([$this->supplierId, $employeeId, str_repeat('a', 64), str_repeat('b', 64)]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $opening = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'][0];
        self::assertSame('blocked', $opening['status']);
        self::assertStringContainsString('schválenou mzdu', (string) $opening['reason']);
        self::assertCount(3, $opening['months']);

        $applied = $this->apply($files, [], openings: true);
        self::assertSame(0, $applied['opening_balances']['saved']);
        self::assertStringContainsString('schválenou mzdu', (string) $applied['opening_balances']['skipped'][0]['reason']);
    }

    public function testMissingMonthBlocksOpeningBalances(): void
    {
        $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $opening = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'][0];

        self::assertSame('blocked', $opening['status']);
        self::assertStringContainsString('2026-02', (string) $opening['reason']);
    }

    public function testBrokenAndForeignFilesAreReportedWhileOthersAreProcessed(): void
    {
        $valid = JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2);
        $preview = $this->imports->preview($this->supplierId, 'test', [
            $this->file('ok.xml', $valid),
            $this->file('rozbite.xml', (string) preg_replace('#\s*<form:mzdaZuctovana>\d+</form:mzdaZuctovana>#', '', $valid, 1)),
            $this->file('cizi.xml', '<?xml version="1.0"?><jmhz xmlns="urn:example:other"/>'),
            $this->file('stara-verze.xml', (string) preg_replace(
                '#\s*<form:pocetDnu>\d+</form:pocetDnu>#',
                '',
                JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 3),
                1,
            )),
        ]);

        self::assertNull($preview['files'][0]['error']);
        self::assertSame('JMHZ', $preview['files'][1]['document_type']);
        self::assertStringContainsString('neodpovídá schématu', (string) $preview['files'][1]['error']);
        self::assertStringContainsString('ani měsíční hlášení JMHZ', (string) $preview['files'][2]['error']);
        self::assertNull($preview['files'][3]['error']);
        self::assertStringContainsString('převzal s varováním', implode(' ', $preview['files'][3]['warnings']));
        self::assertCount(2, $preview['records']);
    }

    public function testActionPassesPairsAndFlagsThrough(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: false);
        $action = $this->container->get(PayrollRegistrationImportAction::class);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];

        $preview = $action->preview($this->request(['environment' => 'test', 'files' => $files]), new Response());
        if ($preview->getStatusCode() === 403) {
            self::markTestSkipped('Mzdový modul není v téhle instalaci licencovaný.');
        }
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $body = $this->json($preview);
        self::assertArrayHasKey('employment_options', $body);
        self::assertArrayHasKey('opening_balances', $body);
        self::assertArrayHasKey('averages', $body);
        $key = $body['records'][0]['key'];

        $applied = $action->apply($this->request([
            'environment' => 'test',
            'files' => $files,
            'keys' => [$key],
            'pairs' => [['key' => $key, 'employment_id' => $employmentId]],
            'evidence_confirmed' => true,
            'apply_opening_balances' => false,
        ]), new Response());
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getBody());
        $result = $this->json($applied);
        self::assertSame('applied', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        self::assertSame(['saved' => 0, 'skipped' => []], $result['opening_balances']);
    }

    /**
     * Dítě „N" (zvýhodnění uplatňuje druhý rodič) dostane nejnižší pořadí,
     * které nepoužívá uplatňované dítě, a nárok nese jmenovanou jinou osobu.
     */
    public function testChildClaimedByOtherGetsFreeOrderAndNamedCaregiver(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [$this->file('jmhz-1.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
            'children' => [
                [
                    'identity' => ['given_name' => 'Adam', 'family_name' => 'Testovací', 'birth_date' => '2015-02-10'],
                    'ztp_p' => false,
                    'order' => 'N',
                ],
                [
                    'identity' => ['given_name' => 'Bára', 'family_name' => 'Testovací', 'birth_date' => '2019-09-01'],
                    'ztp_p' => false,
                    'order' => '1',
                ],
            ],
            'other_caregivers' => [['given_name' => 'Petr', 'family_name' => 'Testovací', 'birth_date' => '1988-03-03']],
        ])], 2026, 1))];

        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], (string) $result['message']);

        $claims = [];
        foreach ($this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-01-01')['dependants'] as $dependant) {
            $claim = $dependant['claims'][0];
            $claims[$dependant['given_name']] = [
                $claim['child_order'],
                $claim['credit_status'],
                $claim['other_claimant_excluded'],
                $claim['other_household_caregiver_status'],
                $claim['other_caregiver_given_name'],
                $claim['other_caregiver_birth_date'],
            ];
        }
        ksort($claims);
        self::assertSame([
            'Adam' => [2, 'claimed_by_other', false, 'present', 'Petr', '1988-03-03'],
            'Bára' => [1, 'claimed', true, 'present', 'Petr', '1988-03-03'],
        ], $claims);
    }

    /** @return array{0:int,1:int} [employee_id, employment_id] */
    private function registerEmployee(bool $withIdentifiers): array
    {
        $options = [
            'bno' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            'start' => '2026-01-01',
        ];
        if ($withIdentifiers) {
            $options += ['ikmpsv' => $this->oic, 'oid' => $this->idPpv];
        }
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1($options))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);

        return [(int) $result['employee_id'], (int) $result['employment_id']];
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @param list<array{key:string,employment_id:int}>|null $pairs
     * @return array<string,mixed>
     */
    private function apply(
        array $files,
        array $keys,
        ?array $pairs = null,
        bool $openings = false,
        bool $averages = false,
        bool $autoChanges = false,
        bool $autoAverages = false,
    ): array {
        return $this->imports->apply(
            $this->supplierId,
            'test',
            $files,
            $keys,
            true,
            null,
            $this->userId,
            null,
            'jmhz-import-test',
            $pairs,
            $openings,
            $averages,
            $autoChanges,
            $autoAverages,
        );
    }

    /** @return array<string,array<string,int>> fáze => stav => počet */
    private function checklistStatuses(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT phase, status, COUNT(*) AS items
               FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ?
              GROUP BY phase, status'
        );
        $statement->execute([$this->supplierId, $employmentId]);
        $statuses = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $statuses[(string) $row['phase']][(string) $row['status']] = (int) $row['items'];
        }

        return $statuses;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<list<mixed>>
     */
    private function rows(array $rows, array $fields): array
    {
        return array_map(
            static fn (array $row): array => [
                ...array_map(static fn (string $field): mixed => $row[$field], $fields),
                (string) $row['effective_from'],
                $row['effective_to'] === null ? null : (string) $row['effective_to'],
                $row['evidence_reference'] ?? null,
            ],
            $rows,
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function tableRows(string $table): int
    {
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/imports/registrations/preview')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
