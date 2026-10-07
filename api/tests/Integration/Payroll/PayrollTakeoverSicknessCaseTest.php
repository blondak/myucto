<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSicknessWriter;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PRE-02: rozběhnutá neschopnost převzatá z předchozího mzdového programu.
 *
 * Převod zapisuje nepřítomnosti mimo schválení v Nepřítomnostech, takže případ
 * dávky dřív nevznikl vůbec a lhůtu HZUPN k návratu do práce (§ 97 odst. 3
 * zák. č. 187/2006 Sb.) nikdo nehlídal. Mzdy vede MyÚčto od 1. 10. 2026.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollTakeoverSicknessCaseTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRV', 'Syntetická účtárna převodu', '9990008888');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2026-10-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Rozpracovaný případ z PAMICA od 17. 9.: v MyÚčtu je jeho část od 1. 10.,
     * případ dávky ale začíná skutečným dnem vzniku. Patnáctý den neschopnosti
     * je 1. 10., tedy už v době MyÚčta — NEMPRI hlídá MyÚčto, stejně jako HZUPN.
     */
    public function testPamicaSicknessAcrossStartPeriodCreatesWatchedCase(): void
    {
        $person = $this->createEmployment($this->officeId, 'Pavla Převzatá', 20, 'hpp', 'employment', 40, 10_000);
        $writer = $this->writer(PohodaPayrollSicknessWriter::class);
        $result = $this->pamicaResult('FLOW-20', '2026-09-17', '2026-10-14');

        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');
        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases, 'Opakovaný převod případ nezdvojí.');
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('2026-10-14', $cases[0]['incapacity_to']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertSame('pending', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15', 'NEMPRI' => '2026-10-01'], $this->watched((int) $cases[0]['id']));
    }

    /**
     * Neschopnost od 3. 8.: lhůta NEMPRI začala běžet 17. 8., v době předchozího
     * programu, který ho podal. Případ ho nese jako vyřízené předchozím
     * programem, MyÚčto ho nepřipraví a hlídá jen HZUPN.
     */
    public function testPredecessorFiledNempriLeavesOnlyHzupnWatched(): void
    {
        $person = $this->createEmployment($this->officeId, 'Petra Dlouhá', 21, 'hpp', 'employment', 40, 10_000);
        $this->writer(PohodaPayrollSicknessWriter::class)->write(
            $this->supplierId,
            $this->actors[0],
            $this->pamicaResult('FLOW-21', '2026-08-03', '2026-10-14'),
            new ImportProtocol('import'),
            'payroll_sickness',
        );

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-08-03', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15'], $this->watched((int) $cases[0]['id']));

        try {
            $this->container->get(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id'], SicknessDocumentKind::Nempri);
            self::fail('NEMPRI podané předchozím programem MyÚčto znovu nepodává.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_document_handled_by_predecessor', $exception->validationCode);
        }
    }

    /**
     * Obecný převod (JMHZ, PREMIER, POHODA): nepřítomnost přes hranici prvního
     * měsíce vedení mezd založí případ; dřív skončená ne — tu vyřídil celou
     * předchozí program.
     */
    public function testGenericTakeoverCreatesCaseOnlyAcrossStartPeriod(): void
    {
        $person = $this->createEmployment($this->officeId, 'Jana Obecná', 22, 'hpp', 'employment', 40, 10_000);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: 'FLOW-22',
            relationKey: 'FLOW-22',
            absences: [
                ['type' => 'dpn', 'from' => '2026-06-01', 'to' => '2026-06-30', 'childbirth' => null],
                ['type' => 'dpn', 'from' => '2026-09-17', 'to' => '2026-10-14', 'childbirth' => null],
            ],
            transferStart: '2026-06',
        );

        $counts = $this->writer(PayrollTakeoverAbsenceWriter::class)->absences(
            $this->supplierId,
            $person['employment_id'],
            $employment,
            $this->actors[0],
            new PayrollTakeoverPolicy('jmhz', 'JMHZ'),
            new PayrollTakeoverRunState(),
        );

        self::assertSame(1, $counts['sickness_cases'] ?? 0, json_encode($counts) ?: '');
        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertArrayHasKey('HZUPN', $this->watched((int) $cases[0]['id']));
    }

    /** @return list<array<string,mixed>> */
    private function casesOf(int $employmentId): array
    {
        return $this->container->get(SicknessCaseService::class)
            ->list($this->supplierId, self::ENVIRONMENT, $employmentId);
    }

    /** @return array<string,string> agenda => termín */
    private function watched(int $caseId): array
    {
        $due = [];
        $overview = $this->container->get(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        foreach ($overview['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $due[$item['title']] = $item['due_on'];
            }
        }
        ksort($due);

        return $due;
    }

    /** @return array<string,mixed> */
    private function pamicaResult(string $personalNumber, string $from, string $to): array
    {
        return [
            'start_period' => '2026-10',
            'cases' => [[
                'in_progress' => true,
                'personal_number' => $personalNumber,
                'person_key' => $personalNumber,
                'type' => 'dpn',
                'date_from' => $from,
                'date_to' => $to,
                'childbirth' => null,
                'compensation_minor' => null,
                'compensation_source' => null,
            ]],
            'wage_compensation_rows' => 0,
            'benefit_rows' => 0,
            'benefit_claims' => 0,
            'unclassified' => [],
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function writer(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
