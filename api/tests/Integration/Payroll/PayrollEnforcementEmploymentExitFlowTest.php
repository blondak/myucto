<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTerminationNoticeService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Scénář skončení pracovního poměru povinného s exekucí:
 *
 *   mzda se srážkou → skončení poměru → termín 7 dní v checklistu →
 *   oznámení soudu/exekutorovi (§ 295 odst. 2 o. s. ř.) s vyúčtováním
 *   srážek → položka checklistu se odškrtne dokladem → případ se
 *   u plátce ukončí bez soudního rozhodnutí.
 *
 * Dřív aplikace oznámení neznala vůbec, checklist neměl termín a případ šel
 * ukončit jen příkazem `stop`, který vyžaduje rozhodnutí o zastavení exekuce.
 */
#[Group('integration')]
final class PayrollEnforcementEmploymentExitFlowTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testExitNoticeDeadlineEvidenceAndCaseEndAtPayer(): void
    {
        $caseId = $this->seedRunCase('remit');
        $this->seedRunClaim($caseId, outstandingMinor: 1_000_000);
        $this->seedRunParty($caseId, 'executor', 'Syntetický exekutorský úřad', '999 EX 1/26');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický věřitel', null);
        $this->seedRunMonthEvidence();
        $run = $this->calculateEnforcementRun();
        self::assertSame(386_200, $run['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($run);

        $notices = $this->service(EnforcementTerminationNoticeService::class);
        $enforcement = $this->service(PayrollEnforcementRepository::class);

        // Dokud poměr trvá, oznámení se nevystavuje.
        self::assertNotNull($notices->overview($this->supplierId, $caseId)['blocked_reason']);

        $this->endEmployment('2026-06-30');
        // Kontakt vystavitele mzdových dokumentů (patička oznámení).
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code,
                 payroll_contact_name, payroll_contact_email, payroll_contact_phone)
             SELECT ?, id, "P", "Syntetická mzdová účetní", "mzdy@example.invalid",
                    "+420 000 000 000"
               FROM payroll_offices WHERE supplier_id = ? LIMIT 1
             ON DUPLICATE KEY UPDATE payroll_contact_name = VALUES(payroll_contact_name),
                 payroll_contact_email = VALUES(payroll_contact_email),
                 payroll_contact_phone = VALUES(payroll_contact_phone)'
        )->execute([$this->supplierId, $this->supplierId]);

        $checklist = $this->checklistItem('enforcement_insolvency_review');
        self::assertSame('2026-07-07', $checklist['due_date'], 'Termín § 295 odst. 2 o. s. ř. chybí.');
        self::assertSame('statute_verified', $checklist['deadline_source_status']);
        self::assertSame('pending', $checklist['effective_status']);

        // Bez oznámení případ u plátce ukončit nejde.
        $case = $enforcement->findCase($this->supplierId, $caseId);
        try {
            $enforcement->transition(
                $this->supplierId,
                $caseId,
                EnforcementCaseCommand::EndAtPayer,
                (int) $case['row_version'],
                null,
                null,
                $this->actorId,
                new EnforcementCaseLifecycle(),
            );
            self::fail('Případ šel ukončit bez oznámení soudu.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('oznámení', $exception->getMessage());
        }

        $overview = $notices->overview($this->supplierId, $caseId);
        self::assertNull($overview['blocked_reason']);
        self::assertSame('2026-07-07', $overview['preview']['due_on']);
        self::assertSame('999 EX 1/26', $overview['preview']['authority']['reference']);
        // Vyúčtování srážek: 3 862 Kč sraženo, z toho 3 812 Kč na pohledávku
        // (50 Kč je paušál plátce mzdy), vyplaceno zatím nic.
        self::assertSame(381_200, $overview['preview']['totals']['withheld_minor']);
        self::assertSame(0, $overview['preview']['totals']['paid_out_minor']);

        $notice = $notices->generate(
            $this->supplierId,
            $caseId,
            'Syntetický nový zaměstnavatel',
            null,
            $this->actorId,
        );
        self::assertSame(1, $notice['revision_no']);
        self::assertSame('2026-07-07', $notice['due_on']);
        $pdf = $notices->pdf($this->supplierId, (int) $notice['id']);
        self::assertStringStartsWith('%PDF-', $pdf['bytes']);

        $sent = $notices->markSent($this->supplierId, (int) $notice['id'], '2026-07-03', 'post', $this->actorId);
        self::assertSame('2026-07-03', $sent['sent_on']);

        $checklist = $this->checklistItem('enforcement_insolvency_review');
        self::assertSame('completed', $checklist['effective_status']);
        self::assertSame('enforcement_termination_notice', $checklist['evidence_kind']);

        $case = $enforcement->findCase($this->supplierId, $caseId);
        $ended = $enforcement->transition(
            $this->supplierId,
            $caseId,
            EnforcementCaseCommand::EndAtPayer,
            (int) $case['row_version'],
            null,
            null,
            $this->actorId,
            new EnforcementCaseLifecycle(),
        );
        self::assertSame('ended_at_payer', $ended['status']);
    }

    private function endEmployment(string $endOn): void
    {
        $employments = $this->service(PayrollEmploymentRepository::class);
        $version = (int) $this->db->pdo()
            ->query("SELECT row_version FROM payroll_employments WHERE id = {$this->employmentId}")
            ->fetchColumn();
        $employments->transition(
            $this->supplierId,
            $this->employmentId,
            'ended',
            $version,
            $endOn,
            'Syntetické skončení poměru',
            $this->actorId,
            null,
            null,
        );
    }

    /** @return array<string,mixed> */
    private function checklistItem(string $key): array
    {
        $detail = [];
        foreach ($this->service(PayrollEmploymentRepository::class)
            ->listForEmployee($this->supplierId, $this->employeeId) as $employment) {
            if ($employment['id'] === $this->employmentId) {
                $detail = $employment;
            }
        }
        foreach ($detail['checklist'] ?? [] as $item) {
            if ($item['item_key'] === $key) {
                return $item;
            }
        }
        self::fail("Checklist neobsahuje {$key}.");
    }
}
