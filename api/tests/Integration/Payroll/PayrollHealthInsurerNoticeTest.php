<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollHealthInsurerNoticeRepository;
use MyInvoice\Service\Payroll\PayrollHealthInsurerNoticeService;
use MyInvoice\Service\Pdf\PayrollHealthInsurerNoticePdfRenderer;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Smalot\PdfParser\Parser;

/**
 * HOZ-LEG-12: sdělení zdravotní pojišťovny zaměstnancem a písemné potvrzení
 * zaměstnavatele (§ 12 písm. b) zákona č. 48/1997 Sb.). Data jsou syntetická.
 */
#[Group('integration')]
final class PayrollHealthInsurerNoticeTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $employeeId;
    private int $coverageId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $officeId = $this->createOffice('ZPN', 'Syntetická účtárna sdělení', '1234567890');
        $person = $this->createEmployment($officeId, 'Zaměstnanec Sdělující', 1, 'hpp', 'employment', 40, 5_000);
        $this->employeeId = $person['employee_id'];
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "205",
                     "synteticky-doklad", "2026-03-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->coverageId = (int) $this->db->pdo()->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testRecordsTheNoticeAndTheConfirmationOnTheCoverageRow(): void
    {
        $service = $this->service('2026-03-05');

        $before = array_values(array_filter(
            $service->list($this->supplierId, $this->employeeId),
            fn (array $row): bool => $row['id'] === $this->coverageId,
        ));
        self::assertCount(1, $before);
        self::assertNull($before[0]['employee_notified_on']);
        self::assertFalse($before[0]['confirmation_available']);

        $recorded = $service->record(
            $this->supplierId,
            $this->employeeId,
            $this->coverageId,
            '2026-03-02',
            '2026-03-04',
            $this->actors[0],
        );

        self::assertSame('2026-03-02', $recorded['employee_notified_on']);
        self::assertSame('2026-03-04', $recorded['employer_confirmed_on']);
        self::assertTrue($recorded['confirmation_available']);
        self::assertSame('205', $recorded['insurer_code']);
    }

    /** Zápis sdělení nesmí změnit otisk věty ani verzi pro editor zákonné evidence. */
    public function testRecordingDoesNotTouchTheRowVersionOfTheEvidenceEditor(): void
    {
        $versionBefore = (int) $this->scalar(
            'SELECT row_version FROM payroll_person_health_coverage_history WHERE id = ?',
            [$this->coverageId],
        );

        $this->service('2026-03-05')->record(
            $this->supplierId,
            $this->employeeId,
            $this->coverageId,
            '2026-03-02',
            null,
            $this->actors[0],
        );

        self::assertSame($versionBefore, (int) $this->scalar(
            'SELECT row_version FROM payroll_person_health_coverage_history WHERE id = ?',
            [$this->coverageId],
        ));
    }

    public function testConfirmationCannotPrecedeTheNoticeOrBeRecordedWithoutIt(): void
    {
        $service = $this->service('2026-03-05');

        foreach ([
            ['2026-03-04', '2026-03-02', 'dřív, než ho přijal'],
            [null, '2026-03-02', 'jen ke sdělení'],
            ['2026-03-10', null, 'v budoucnosti'],
            ['03/02/2026', null, 'RRRR-MM-DD'],
        ] as [$notified, $confirmed, $fragment]) {
            try {
                $service->record($this->supplierId, $this->employeeId, $this->coverageId, $notified, $confirmed, null);
                self::fail('Neplatné sdělení se nesmí zapsat: ' . $fragment);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($fragment, $exception->getMessage());
            }
        }
    }

    public function testCoverageOfAnotherEmployeeIsNotFound(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service('2026-03-05')->record(
            $this->supplierId,
            $this->employeeId + 99999,
            $this->coverageId,
            '2026-03-02',
            null,
            null,
        );
    }

    public function testConfirmationPdfNeedsTheRecordedNotice(): void
    {
        $service = $this->service('2026-03-05');

        $this->expectException(\DomainException::class);
        $service->confirmationPdf($this->supplierId, $this->employeeId, $this->coverageId);
    }

    public function testConfirmationPdfCarriesTheDatesInsurerAndNames(): void
    {
        $service = $this->service('2026-03-05');
        $service->record($this->supplierId, $this->employeeId, $this->coverageId, '2026-03-02', null, null);

        $pdf = $service->confirmationPdf($this->supplierId, $this->employeeId, $this->coverageId);

        self::assertStringStartsWith('%PDF-', $pdf['bytes']);
        self::assertStringEndsWith('.pdf', $pdf['filename']);
        $text = (new Parser())->parseContent($pdf['bytes'])->getText();
        self::assertStringContainsString('Potvrzení o přijetí sdělení', $text);
        self::assertStringContainsString('02.03.2026', $text);
        // potvrzení bez zapsaného dne nese den vystavení
        self::assertStringContainsString('05.03.2026', $text);
        self::assertStringContainsString('205', $text);
        self::assertStringContainsString('ČPZP', $text);
        self::assertStringContainsString('01.03.2026', $text);
    }

    private function service(string $today): PayrollHealthInsurerNoticeService
    {
        $repository = $this->container->get(PayrollHealthInsurerNoticeRepository::class);
        self::assertInstanceOf(PayrollHealthInsurerNoticeRepository::class, $repository);

        return new PayrollHealthInsurerNoticeService(
            $repository,
            new PayrollHealthInsurerNoticePdfRenderer(),
            new class ($today) implements ClockInterface {
                public function __construct(private readonly string $today) {}

                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable($this->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
                }
            },
        );
    }
}
