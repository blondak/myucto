<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Vrep;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Vrep\CsszFormVrepTransportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Adaptér VREP pro NEMPRI25, HZUPN20 a OZUSPOJ23. Na ČSSZ se nic neposílá:
 * transport JMHZ je tu dvojník a test hlídá, co a s jakou obálkou by mu
 * adaptér předal.
 */
final class CsszFormVrepTransportServiceTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;
    private const VS = '1234567890';

    /** @return iterable<string,array{string,string,string,string}> */
    public static function forms(): iterable
    {
        yield 'NEMPRI' => ['NEMPRI', 'CSSZ_NEM_PRI', 'NEMPRI25', self::nempri(self::VS)];
        yield 'HZUPN' => ['HZUPN', 'CSSZ_NEM_PRI', 'HZUPN20', self::hzupn(self::VS)];
        yield 'OZUSPOJ' => ['OZUSPOJ', 'CSSZ_OZUSPOJ', 'OZUSPOJ23', self::ozuspoj(self::VS)];
    }

    #[DataProvider('forms')]
    public function testSendPassesTheFrozenBytesWithClassAndForm(
        string $agenda,
        string $class,
        string $form,
        string $xml,
    ): void {
        $platform = $this->createMock(PayrollSubmissionService::class);
        $platform->expects(self::once())
            ->method('adoptDispatchChannel')
            ->with(self::SUPPLIER, self::SUBMISSION, 'vrep_apep');
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->with(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                self::identicalTo($xml),
                self::VS,
                'click-1',
                7,
                $class,
                $form,
            )
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow()));

        $result = $this->service($agenda, $xml, $dispatch, $platform)
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-1', 7);

        self::assertSame($class, $result['submission_class']);
        self::assertSame($form, $result['form']);
        self::assertSame(hash('sha256', $xml), $result['payload_sha256']);
        self::assertFalse($result['manual_review']);
    }

    /**
     * Do ostrého prostředí VREP pro tyhle formuláře zatím nesmí: tvar obálky
     * a protokolu ověří až zkušební podání do testu ČSSZ.
     */
    public function testProductionStaysClosedUntilVerifiedByATestSubmission(): void
    {
        self::assertFalse(CsszFormVrepTransportService::PRODUCTION_OPEN);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $platform = $this->createMock(PayrollSubmissionService::class);
        $platform->expects(self::never())->method('adoptDispatchChannel');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jen v testovacím prostředí ČSSZ');

        $this->service('NEMPRI', self::nempri(self::VS), $dispatch, $platform, 'production')
            ->send(self::SUPPLIER, 'production', self::SUBMISSION, 'click-prod', 7);
    }

    /** Podání, které už jde datovou schránkou, nesmí odejít podruhé přes VREP. */
    public function testSubmissionQueuedInTheDataBoxIsNotSentViaVrep(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $platform = $this->createMock(PayrollSubmissionService::class);
        $platform->expects(self::never())->method('adoptDispatchChannel');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('odchozí frontě datové schránky');

        $this->service('HZUPN', self::hzupn(self::VS), $dispatch, $platform, 'test', 'ready', true)
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-2', 7);
    }

    public function testOtherAgendaNeverReachesTheTransport(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jen NEMPRI, HZUPN a OZUSPOJ');

        $this->service('JMHZ25', self::nempri(self::VS), $dispatch)
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-3', 7);
    }

    public function testDataSentenceOfAnotherFormNeverReachesTheTransport(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neodpovídá formuláři NEMPRI25');

        $this->service('NEMPRI', self::hzupn(self::VS), $dispatch)
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-4', 7);
    }

    public function testMissingVariableSymbolNeverReachesTheTransport(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('musí být v celém podání jediný');

        $this->service('OZUSPOJ', self::ozuspoj('12345'), $dispatch)
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-5', 7);
    }

    public function testAlreadySentSubmissionIsNotSentAgain(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('už bylo odesláno');

        $this->service('NEMPRI', self::nempri(self::VS), $dispatch, null, 'test', 'submitted')
            ->send(self::SUPPLIER, 'test', self::SUBMISSION, 'click-6', 7);
    }

    #[DataProvider('forms')]
    public function testPollAndCloseCarryClassAndForm(
        string $agenda,
        string $class,
        string $form,
        string $xml,
    ): void {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('poll')
            ->with(self::SUPPLIER, 'test', 5, self::VS, 1, $class, $form)
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow(), null, null, true));
        $dispatch->expects(self::once())
            ->method('close')
            ->with(self::SUPPLIER, 'test', 5, self::VS, $class, $form)
            ->willReturn(['closed' => true, 'already_closed' => false, 'attempt' => self::attemptRow()]);
        $service = $this->service($agenda, $xml, $dispatch, null, 'test', 'submitted');

        self::assertTrue($service->poll(self::SUPPLIER, 'test', 5)->manualReview);
        self::assertTrue($service->close(self::SUPPLIER, 'test', 5)['closed']);
    }

    private function service(
        string $agenda,
        string $xml,
        JmhzDispatchService $dispatch,
        ?PayrollSubmissionService $platform = null,
        string $environment = 'test',
        string $status = 'ready',
        bool $queuedInDataBox = false,
    ): CsszFormVrepTransportService {
        $submissions = $this->createStub(PayrollSubmissionRepository::class);
        $submissions->method('findSubmission')->willReturn([
            'id' => self::SUBMISSION,
            'status' => $status,
            'environment' => $environment,
        ]);
        $submissions->method('findObligationOfSubmission')->willReturn([
            'agenda_code' => $agenda,
            'subject_type' => 'employment',
            'subject_reference' => 'payroll_employment:9',
        ]);
        $submissions->method('hasActiveIsdsOutbox')->willReturn($queuedInDataBox);
        $attempts = $this->createStub(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->method('findByIdempotencyKey')->willReturn(null);
        $attempts->method('find')->willReturn(self::attemptRow());
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);

        return new CsszFormVrepTransportService(
            $submissions,
            $attempts,
            $frozen,
            $dispatch,
            $platform ?? $this->createStub(PayrollSubmissionService::class),
        );
    }

    /** @return array<string,mixed> */
    private static function attemptRow(): array
    {
        return [
            'id' => 5,
            'supplier_id' => self::SUPPLIER,
            'environment' => 'test',
            'submission_id' => self::SUBMISSION,
            'channel' => 'vrep_apep',
            'status' => 'awaiting_protocol',
            'correlation_reference' => 'C0000000000000000000000000000001',
        ];
    }

    private static function nempri(string $vs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<NEMPRI xmlns="http://schemas.cssz.cz/nem/NEMPRI25" version="1.0">'
            . '<datovaVeta><zamestnani><VSZamestnavatel>' . $vs . '</VSZamestnavatel>'
            . '</zamestnani></datovaVeta></NEMPRI>';
    }

    private static function hzupn(string $vs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<PodaniHZUPN xmlns="http://schemas.cssz.cz/nem/HZUPN20" version="1.2">'
            . '<FormularHZUPN poradoveCislo="1"><zamestnani><variabilniSymbol>' . $vs
            . '</variabilniSymbol></zamestnani></FormularHZUPN></PodaniHZUPN>';
    }

    private static function ozuspoj(string $vs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<podaniOzuspoj xmlns="http://schemas.cssz.cz/POJ/OZUSPOJ23">'
            . '<formularOzuspoj><zamestnavatel><vs>' . $vs . '</vs></zamestnavatel>'
            . '</formularOzuspoj></podaniOzuspoj>';
    }
}
