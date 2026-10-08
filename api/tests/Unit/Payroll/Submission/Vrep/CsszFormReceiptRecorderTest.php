<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Vrep;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolReport;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Vrep\CsszFormReceiptRecorder;
use MyInvoice\Tests\Unit\Payroll\Submission\CsszFormProtocolSample;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Výsledek ověřeného protokolu NEMPRI, HZUPN a OZUSPOJ se zapisuje přes
 * TYTÉŽ služby jako ruční zápis výsledku. Test hlídá, co a s jakými údaji
 * jim recorder předá, a že odmítnutí služby skončí nálezem u podání.
 */
final class CsszFormReceiptRecorderTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;
    private const CASE_ID = 5;
    private const INTENT_ID = 8;
    /** 22:30 UTC je v Praze už další den — den doručení se bere pražský. */
    private const SUBMITTED_AT = '2026-10-07 22:30:00';
    private const DELIVERED_ON = '2026-10-08';

    /** @var list<array{severity:string,code:string,details:?array<string,mixed>}> */
    private array $issues = [];

    protected function setUp(): void
    {
        $this->issues = [];
    }

    public function testAcceptedNempriIsRecordedOnTheCaseWithTheDeliveryDay(): void
    {
        $cases = $this->cases(['nempri_submission_id' => self::SUBMISSION]);
        $cases->expects(self::once())->method('recordReceipt')->with(
            self::SUPPLIER,
            'test',
            self::CASE_ID,
            SicknessDocumentKind::Nempri,
            'accepted',
            self::DELIVERED_ON,
            null,
        )->willReturn([]);

        $outcome = $this->recorder('sickness:' . self::CASE_ID . ':nempri', $cases)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::accepted('CSSZ_NEM_PRI', 'NEMPRI25'),
            ));

        self::assertSame('recorded', $outcome);
        self::assertSame([], $this->issues);
    }

    /**
     * Chyba 103: HZUPN se u případu vede jako odmítnuté s důvodem, který říká,
     * že vada je v pověření u OSSZ, ne v podání. Podání dostane nález.
     */
    public function testServiceAuthorizationErrorRejectsTheHzupnWithAnUnderstandableReason(): void
    {
        $cases = $this->cases(['hzupn_submission_id' => self::SUBMISSION]);
        $reason = null;
        $cases->expects(self::once())->method('recordReceipt')->willReturnCallback(
            function (int $supplier, string $environment, int $case, SicknessDocumentKind $document, string $outcome, ?string $acceptedOn, ?string $rejection) use (&$reason): array {
                self::assertSame(SicknessDocumentKind::Hzupn, $document);
                self::assertSame('rejected', $outcome);
                self::assertNull($acceptedOn);
                $reason = $rejection;

                return [];
            },
        );

        $outcome = $this->recorder('sickness:' . self::CASE_ID . ':hzupn', $cases)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_NEM_PRI'),
            ));

        self::assertSame('recorded', $outcome);
        self::assertIsString($reason);
        self::assertStringContainsString('chyba 103', $reason);
        self::assertStringContainsString('pověření k e-službě CSSZ_NEM_PRI', $reason);
        self::assertStringContainsString('Nejde o vadu podání', $reason);
        self::assertLessThanOrEqual(190, mb_strlen($reason));
        self::assertSame([CsszFormReceiptRecorder::SERVICE_AUTHORIZATION_ISSUE], array_column($this->issues, 'code'));
        self::assertSame('error', $this->issues[0]['severity']);
    }

    public function testRejectedNempriCarriesTheCodeAndTextFromTheProtocol(): void
    {
        $cases = $this->cases(['nempri_submission_id' => self::SUBMISSION]);
        $cases->expects(self::once())->method('recordReceipt')->with(
            self::SUPPLIER,
            'test',
            self::CASE_ID,
            SicknessDocumentKind::Nempri,
            'rejected',
            null,
            'ČSSZ podání odmítla: 604 - Rodné číslo neodpovídá evidenci.',
        )->willReturn([]);

        $this->recorder('sickness:' . self::CASE_ID . ':nempri', $cases)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::rejectedForm(
                    'CSSZ_NEM_PRI',
                    'NEMPRI25',
                    'NEMPRI25_LT: 604 - Rodné číslo neodpovídá evidenci.',
                    '604',
                ),
            ));
    }

    /** Pozdní protokol podání, které případ už nenese, nesmí přepsat platný výsledek. */
    public function testProtocolOfASupersededSubmissionDoesNotTouchTheCase(): void
    {
        $cases = $this->cases(['nempri_submission_id' => self::SUBMISSION + 1]);
        $cases->expects(self::never())->method('recordReceipt');

        $outcome = $this->recorder('sickness:' . self::CASE_ID . ':nempri', $cases)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::accepted('CSSZ_NEM_PRI', 'NEMPRI25'),
            ));

        self::assertSame('not_recorded', $outcome);
        self::assertSame(CsszFormReceiptRecorder::NOT_RECORDED_ISSUE, $this->issues[0]['code']);
        self::assertSame('sickness_receipt_submission_mismatch', $this->issues[0]['details']['reason_code'] ?? null);
    }

    /** Co služba případu odmítne, se nezapíše; podání nese nález s důvodem. */
    public function testRefusalOfTheCaseServiceEndsAsAnIssue(): void
    {
        $cases = $this->cases(['nempri_submission_id' => self::SUBMISSION]);
        $cases->method('recordReceipt')->willThrowException(new SicknessException(
            'sickness_receipt_already_recorded',
            'Přijetí NEMPRI je už zapsané.',
        ));

        $outcome = $this->recorder('sickness:' . self::CASE_ID . ':nempri', $cases)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::accepted('CSSZ_NEM_PRI', 'NEMPRI25'),
            ));

        self::assertSame('not_recorded', $outcome);
        self::assertSame('warning', $this->issues[0]['severity']);
        self::assertSame('sickness_receipt_already_recorded', $this->issues[0]['details']['reason_code'] ?? null);
        self::assertSame('Přijetí NEMPRI je už zapsané.', $this->issues[0]['details']['message'] ?? null);
    }

    public function testAcceptedOzuspojStartAcceptsTheIntentWithTheDeliveryDay(): void
    {
        $intents = $this->intents(['start_submission_id' => self::SUBMISSION]);
        $intents->expects(self::once())->method('recordReceipt')->with(
            self::SUPPLIER,
            'test',
            self::INTENT_ID,
            'accepted',
            self::DELIVERED_ON,
            null,
        )->willReturn([]);

        self::assertSame('recorded', $this->recorder('ozuspoj:' . self::INTENT_ID . ':start', null, $intents)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::accepted('CSSZ_OZUSPOJ', 'OZUSPOJ23', '0'),
            )));
    }

    public function testAcceptedOzuspojEndEndsTheIntent(): void
    {
        $intents = $this->intents(['end_submission_id' => self::SUBMISSION]);
        $intents->expects(self::once())->method('recordReceipt')->with(
            self::SUPPLIER,
            'test',
            self::INTENT_ID,
            'ended',
            self::DELIVERED_ON,
            null,
        )->willReturn([]);

        $this->recorder('ozuspoj:' . self::INTENT_ID . ':end', null, $intents)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::accepted('CSSZ_OZUSPOJ', 'OZUSPOJ23', '0'),
            ));
    }

    public function testRejectedOzuspojStartRejectsTheIntentWithTheReason(): void
    {
        $intents = $this->intents(['start_submission_id' => self::SUBMISSION]);
        $intents->expects(self::once())->method('recordReceipt')->with(
            self::SUPPLIER,
            'test',
            self::INTENT_ID,
            'rejected',
            null,
            'ČSSZ podání odmítla: 291 - Záměr už oznámil jiný zaměstnavatel.',
        )->willReturn([]);

        $this->recorder('ozuspoj:' . self::INTENT_ID . ':start', null, $intents)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::rejectedForm(
                    'CSSZ_OZUSPOJ',
                    'OZUSPOJ23',
                    'OZUSPOJ23_LT: 291 - Záměr už oznámil jiný zaměstnavatel.',
                    '291',
                ),
            ));
    }

    /**
     * Chyba 103 u OZUSPOJ záměr NEODMÍTÁ: ČSSZ ho vůbec neposoudila a odmítnutý
     * záměr už přijmout nejde. Zůstává podaný, důvod nese nález u podání.
     */
    public function testServiceAuthorizationErrorLeavesTheIntentSubmitted(): void
    {
        $intents = $this->intents(['start_submission_id' => self::SUBMISSION]);
        $intents->expects(self::never())->method('recordReceipt');

        $outcome = $this->recorder('ozuspoj:' . self::INTENT_ID . ':start', null, $intents)
            ->record(self::SUPPLIER, 'test', self::SUBMISSION, self::report(
                CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_OZUSPOJ'),
            ));

        self::assertSame('skipped', $outcome);
        self::assertSame([CsszFormReceiptRecorder::SERVICE_AUTHORIZATION_ISSUE], array_column($this->issues, 'code'));
        self::assertStringContainsString(
            'pověření k e-službě CSSZ_OZUSPOJ',
            (string) ($this->issues[0]['details']['message'] ?? ''),
        );
    }

    public function testProtocolOfAnotherClassIsNotApplicable(): void
    {
        $repository = $this->createMock(PayrollSubmissionRepository::class);
        $repository->expects(self::never())->method('singlePartReceiptTarget');
        $recorder = new CsszFormReceiptRecorder(
            $repository,
            $this->platform(),
            $this->createStub(SicknessCaseService::class),
            $this->createStub(OzuspojIntentService::class),
        );

        self::assertSame('not_applicable', $recorder->record(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            (new JmhzProtocolParser())->parse(JmhzTransportSample::partialProtocol()),
        ));
        self::assertSame([], $this->issues);
    }

    private static function report(string $xml): JmhzProtocolReport
    {
        return (new JmhzProtocolParser())->parse($xml, 1, CsszFormProtocolSample::CORRELATION);
    }

    /**
     * @param array<string,mixed> $row
     * @return MockObject&SicknessCaseService
     */
    private function cases(array $row): MockObject
    {
        $cases = $this->createMock(SicknessCaseService::class);
        $cases->expects(self::once())->method('requireCase')->with(self::SUPPLIER, 'test', self::CASE_ID)->willReturn($row + [
            'id' => self::CASE_ID,
            'nempri_submission_id' => null,
            'hzupn_submission_id' => null,
        ]);

        return $cases;
    }

    /**
     * @param array<string,mixed> $row
     * @return MockObject&OzuspojIntentService
     */
    private function intents(array $row): MockObject
    {
        $intents = $this->createMock(OzuspojIntentService::class);
        $intents->expects(self::once())->method('requireIntent')->with(self::SUPPLIER, 'test', self::INTENT_ID)->willReturn($row + [
            'id' => self::INTENT_ID,
            'status' => 'submitted',
            'start_submission_id' => null,
            'end_submission_id' => null,
        ]);

        return $intents;
    }

    private function recorder(
        string $partReference,
        ?SicknessCaseService $cases = null,
        ?OzuspojIntentService $intents = null,
    ): CsszFormReceiptRecorder {
        $repository = $this->createStub(PayrollSubmissionRepository::class);
        $repository->method('singlePartReceiptTarget')->willReturn([
            'status' => 'accepted',
            'submitted_at' => self::SUBMITTED_AT,
            'part_reference' => $partReference,
        ]);

        return new CsszFormReceiptRecorder(
            $repository,
            $this->platform(),
            $cases ?? $this->createStub(SicknessCaseService::class),
            $intents ?? $this->createStub(OzuspojIntentService::class),
        );
    }

    private function platform(): PayrollSubmissionService
    {
        $platform = $this->createStub(PayrollSubmissionService::class);
        $platform->method('get')->willReturn(['id' => self::SUBMISSION, 'row_version' => 3]);
        $platform->method('recordIssue')->willReturnCallback(
            function (int $supplier, int $submission, int $version, ?int $part, string $severity, string $stage, string $code, ?string $entityType = null, ?string $entityReference = null, ?array $details = null): array {
                self::assertSame(self::SUBMISSION, $submission);
                self::assertSame(3, $version);
                self::assertSame('remote', $stage);
                $this->issues[] = ['severity' => $severity, 'code' => $code, 'details' => $details];

                return ['id' => count($this->issues), 'submission_row_version' => 4];
            },
        );

        return $platform;
    }
}
