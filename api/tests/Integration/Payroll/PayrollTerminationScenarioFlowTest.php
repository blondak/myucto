<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Document\EmploymentExitDocumentService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celé skončení pracovního poměru cestou účetní: ukončení vztahu na kartě,
 * důvod skončení, proplacení nevyčerpané dovolené a odstupné jako vstupy
 * posledního běhu, běh, měsíční hlášení JMHZ (dry-run s XSD a kontrolami)
 * a potvrzení pro Úřad práce se způsobem skončení ze záznamu.
 *
 * Náhrada za nevyčerpanou dovolenou musí v JMHZ skončit v 10338
 * (`mzda.nahrady.dovolena`), ne v obecných náhradách 10337.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollTerminationScenarioFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const END_ON = '2026-07-31';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        if (!$this->db->hasTable('payroll_employment_terminations')) {
            self::markTestSkipped('Migrace 1923 neproběhla.');
        }
        $this->officeId = $this->createOffice('SKON', 'Syntetická účtárna skončení', '9990001234');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testRedundancyTerminationFromCardToLastRunAndDocuments(): void
    {
        $person = $this->createEmployment($this->officeId, 'Olga Skončení', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Olga',
            'last_name' => 'Skončení',
            'birth_date' => '1986-03-14',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1986-03-14', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, reason, support_status, source_hash, created_by)
             VALUES (?, ?, 2026, "2026-01-01", "entitlement", 4800, "Syntetický nárok", "supported", ?, ?)',
        )->execute([$this->supplierId, $person['employment_id'], random_bytes(32), $this->actors[0]]);

        // 1) Ukončení vztahu na kartě.
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $person['employment_id']],
        );
        $employments->transition(
            $this->supplierId,
            $person['employment_id'],
            'ended',
            $version,
            self::END_ON,
            null,
            $this->actors[0],
            null,
            null,
        );

        // 2) Důvod skončení, vyrovnání dovolené a odstupné.
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $overview = $termination->save($this->supplierId, $person['employment_id'], [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->actors[0]);
        self::assertSame('4', $overview['derived']['regzec_reason_code']);
        self::assertSame('payout', $overview['leave_settlement']['state'], CanonicalJson::encode($overview['issues']));
        self::assertSame('ready', $overview['severance']['state'], CanonicalJson::encode($overview['severance']));
        self::assertSame(1, $overview['severance']['multiple'], 'Poměr trval méně než rok.');
        // Složka ODSTUPNE nemá výchozí zařazení v JMHZ; karta to hlásí dřív,
        // než by se hlášení za poslední měsíc zaseklo na chybějícím zařazení.
        self::assertContains('severance_jmhz_mapping_missing', array_column($overview['issues'], 'code'));
        $overview = $termination->settleLeave($this->supplierId, $person['employment_id'], $this->actors[0]);
        $leaveAmount = (int) $overview['leave_settlement']['amount_minor'];
        self::assertGreaterThan(0, $leaveAmount);

        // 3) Poslední běh a měsíční hlášení.
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base-' . $person['employment_id'], self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'termination');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $preparation = $this->prepareJmhz($revisionId, 'termination');
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        $components = [];
        foreach ($tested['body']['blockers'] ?? [] as $blocker) {
            if (($blocker['entity_type'] ?? null) === 'component') {
                $components[] = $this->scalar('SELECT code FROM payroll_component_definitions WHERE id = ?', [$blocker['entity_id']]);
            }
        }
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'] ?? null,
            'Zaseknutí: XSD nebo kontroly. ' . implode(',', $components) . ' '
                . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        $xml = (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));
        self::assertStringContainsString(
            '<form:dovolena>' . intdiv($leaveAmount, 100) . '</form:dovolena>',
            $xml,
            'Náhrada za nevyčerpanou dovolenou musí jít do 10338.',
        );

        // 4) Potvrzení pro Úřad práce se způsobem skončení ze záznamu.
        $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = "1986-03-14" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $person['employee_id']]);
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_addresses WHERE supplier_id = ? AND employee_id = ? AND address_type = "residence"',
            [$this->supplierId, $person['employee_id']],
        ) === 0) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_addresses
                    (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
                 VALUES (?, ?, "residence", "Testovací 10", "Praha", "10000", "CZ", "2026-01-01")',
            )->execute([$this->supplierId, $person['employee_id']]);
        }
        $documents =$this->container->get(EmploymentExitDocumentService::class);
        self::assertInstanceOf(EmploymentExitDocumentService::class, $documents);
        $readiness = $documents->readiness($this->supplierId, $person['employment_id']);
        self::assertTrue(
            $readiness['average_earnings_certificate']['available'],
            'Zaseknutí: potvrzení pro ÚP. ' . (string) $readiness['average_earnings_certificate']['readiness_code'],
        );
        $certificate = $documents->generateAverageEarningsDocument(
            $this->supplierId,
            $person['employment_id'],
            'average_earnings_certificate',
            [
                'termination_assessment_complete' => true,
                'termination_reason_kind' => $overview['derived']['unemployment_office_kind'],
                'employee_stated_reason' => null,
                'pension_insurance_periods' => [['from' => '2026-01-01', 'to' => self::END_ON]],
                'correction_reason' => null,
            ],
            'termination-scenario-certificate',
            $this->actors[0],
        );
        self::assertSame('average_earnings_certificate', $certificate['document_kind']);
        $prefill = $termination->overview($this->supplierId, $person['employment_id'])['a2_prefill'];
        self::assertSame('4', $prefill['unemployment']['termination_reason']);
        self::assertSame('golden_handshake', $prefill['unemployment']['settlement_kind']);
    }
}
