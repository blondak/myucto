<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolErrorOrigin;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPartKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolReport;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protokoly ČSSZ k NEMPRI25, HZUPN20 (`CSSZ_NEM_PRI`) a OZUSPOJ23
 * (`CSSZ_OZUSPOJ`). Tvar odpovídá zkušebním podáním do testovacího prostředí
 * ČSSZ z 8. 10. 2026 (kolo 2), hodnoty jsou syntetické
 * ({@see CsszFormProtocolSample}).
 */
final class JmhzCsszFormProtocolParserTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function acceptedForms(): iterable
    {
        yield 'NEMPRI25' => ['CSSZ_NEM_PRI', 'NEMPRI25', '1'];
        yield 'HZUPN20' => ['CSSZ_NEM_PRI', 'HZUPN20', '1'];
        // OZUSPOJ23 čísluje formulář od nuly.
        yield 'OZUSPOJ23' => ['CSSZ_OZUSPOJ', 'OZUSPOJ23', '0'];
    }

    #[DataProvider('acceptedForms')]
    public function testAcceptedFormIsAccepted(string $class, string $form, string $sequence): void
    {
        $report = self::parse(CsszFormProtocolSample::accepted($class, $form, $sequence));

        self::assertSame($class, $report->submissionClass);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertSame('accepted', $report->payrollRemoteStatus());
        self::assertSame(CsszFormProtocolSample::CORRELATION, $report->correlationReference);
        self::assertSame([], $report->errors);
        self::assertCount(1, self::forms($report));
        // GUID formuláře protokol nenese; výsledek formuláře se proto
        // neukládá zvlášť a rodné číslo se z protokolu nepřebírá.
        self::assertSame([], $report->formStatuses);
        self::assertNull(self::forms($report)[0]->formGuid);
        self::assertNull(self::forms($report)[0]->ikMpsv);
        self::assertFalse($report->missingServiceAuthorization());
    }

    /** VREP vrací u OZUSPOJ třídu v obálce s mezerou na konci (`CSSZ_OZUSPOJ `). */
    public function testPaddedOzuspojClassIsRead(): void
    {
        $report = self::parse(CsszFormProtocolSample::protocol(
            'CSSZ_OZUSPOJ',
            'response',
            'OK',
            [
                CsszFormProtocolSample::item('OZUSPOJ23', '', '', 'OK'),
                CsszFormProtocolSample::item('OZUSPOJ23', '0', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
            ],
            envelopeClass: 'CSSZ_OZUSPOJ ',
        ));

        self::assertSame('CSSZ_OZUSPOJ', $report->submissionClass);
        self::assertSame('accepted', $report->payrollRemoteStatus());
    }

    /**
     * Chyba komunikace 103 podání nezpracuje: odmítnutí s kódem a textem,
     * bez formuláře, a report ji pojmenuje jako chybějící pověření u OSSZ.
     */
    public function testServiceAuthorizationErrorRejectsWithoutAProcessedForm(): void
    {
        $report = self::parse(CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_NEM_PRI'));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        self::assertSame('rejected', $report->payrollRemoteStatus());
        self::assertCount(1, $report->errors);
        self::assertSame(103, $report->errors[0]->code);
        self::assertSame(JmhzProtocolErrorOrigin::Platform, $report->errors[0]->origin);
        self::assertStringStartsWith('Pověření k dané e-službě', $report->errors[0]->message);
        self::assertSame([], self::forms($report));
        self::assertTrue($report->missingServiceAuthorization());
    }

    public function testRejectedFormCarriesTheCodeFromTheMessage(): void
    {
        $message = 'NEMPRI25_LT: 604 - Rodné číslo pojištěnce neodpovídá evidenci.';
        $report = self::parse(CsszFormProtocolSample::rejectedForm('CSSZ_NEM_PRI', 'NEMPRI25', $message, '604'));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        $form = self::forms($report)[0];
        self::assertSame(JmhzSubmissionStatus::Rejected, $form->status);
        self::assertSame(604, $form->errors[0]->code);
        self::assertSame('Rodné číslo pojištěnce neodpovídá evidenci.', $form->errors[0]->message);
        self::assertFalse($report->missingServiceAuthorization());
    }

    /** Přijetí s upozorněním (`countWar`) je pořád přijetí; upozornění zůstává u formuláře. */
    public function testAcceptedWithWarningsIsAcceptedWithErrors(): void
    {
        $warning = 'OZUSPOJ23_LT: 291 - Záměr byl oznámen po lhůtě.';
        $report = self::parse(CsszFormProtocolSample::protocol(
            'CSSZ_OZUSPOJ',
            'response',
            'OK',
            [
                CsszFormProtocolSample::item('OZUSPOJ23', '', '', 'OK'),
                CsszFormProtocolSample::item('OZUSPOJ23', '0', CsszFormProtocolSample::BIRTH_NUMBER, 'OK', $warning, '291'),
            ],
            warnings: 1,
        ));

        self::assertSame(JmhzSubmissionStatus::ContainsPassableErrors, $report->status);
        self::assertSame('accepted', $report->payrollRemoteStatus());
        self::assertSame(291, self::forms($report)[0]->errors[0]->code);
    }

    /** @return iterable<string,array{string,string}> */
    public static function undocumentedShapes(): iterable
    {
        yield 'formulář jiné třídy' => [
            CsszFormProtocolSample::accepted('CSSZ_NEM_PRI', 'OZUSPOJ23'),
            'nemá doložený druh formuláře',
        ];
        yield 'dva různé formuláře' => [
            CsszFormProtocolSample::protocol('CSSZ_NEM_PRI', 'response', 'OK', [
                CsszFormProtocolSample::item('NEMPRI25', '', '', 'OK'),
                CsszFormProtocolSample::item('HZUPN20', '1', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
            ]),
            'nemá doložený druh formuláře',
        ];
        yield 'dva formuláře' => [
            CsszFormProtocolSample::protocol('CSSZ_NEM_PRI', 'response', 'OK', [
                CsszFormProtocolSample::item('NEMPRI25', '', '', 'OK'),
                CsszFormProtocolSample::item('NEMPRI25', '1', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
                CsszFormProtocolSample::item('NEMPRI25', '2', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
            ]),
            'právě jeden formulář',
        ];
        yield 'přijetí bez formuláře' => [
            CsszFormProtocolSample::protocol('CSSZ_NEM_PRI', 'response', 'OK', [
                CsszFormProtocolSample::item('NEMPRI25', '', '', 'OK'),
            ]),
            'neuvádí výsledek formuláře',
        ];
        yield 'obálka přijímá, výsledek odmítá' => [
            str_replace(
                '<Qualifier>error</Qualifier>',
                '<Qualifier>response</Qualifier>',
                CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_NEM_PRI'),
            ),
            'jiný výsledek než ProcessingResult',
        ];
        yield 'chyba a přijatý formulář' => [
            CsszFormProtocolSample::protocol('CSSZ_OZUSPOJ', 'error', 'ERROR', [
                CsszFormProtocolSample::item('OZUSPOJ23', '', '', 'OK'),
                CsszFormProtocolSample::item('OZUSPOJ23', '0', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
            ], 'OZUSPOJ23_LT: 604 - Chyba.', '604'),
            'jediný formulář je přijatý',
        ];
        yield 'prázdná položka u přijetí' => [
            CsszFormProtocolSample::protocol('CSSZ_NEM_PRI', 'response', 'OK', [
                CsszFormProtocolSample::item('', '', '', ''),
                CsszFormProtocolSample::item('NEMPRI25', '1', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
            ]),
            'prázdnou položku',
        ];
    }

    /**
     * Mimo doložený tvar se protokol nevykládá: pojmenuje se jako nedoložený
     * (podání jde k ručnímu vyřízení) a hláška říká, v čem se tvar liší.
     */
    #[DataProvider('undocumentedShapes')]
    public function testShapeOutsideTheDocumentedOneGoesToManualReview(string $xml, string $reason): void
    {
        try {
            self::parse($xml);
            self::fail('Protokol mimo doložený tvar prošel.');
        } catch (JmhzTransportException $exception) {
            self::assertSame(JmhzProtocolParser::UNDOCUMENTED_SHAPE_CODE, $exception->errorCode);
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    /**
     * Ověřený protokol předá platformě stav podání, ale ŽÁDNÝ výsledek
     * formuláře: ten platforma bere jen u JMHZ a registrací a u téhle třídy
     * by import protokolu odmítla.
     */
    #[DataProvider('acceptedForms')]
    public function testReceiptVerifierPassesTheStatusWithoutFormOutcomes(string $class, string $form, string $sequence): void
    {
        $verified = (new JmhzReceiptVerifier(self::passThroughSignatures()))->verify(
            CsszFormProtocolSample::accepted($class, $form, $sequence),
            'vrep_apep',
            'test',
            CsszFormProtocolSample::CORRELATION,
        );

        self::assertSame('accepted', $verified->remoteStatus);
        self::assertSame(CsszFormProtocolSample::CORRELATION, $verified->correlationReference);
        self::assertSame([], $verified->formOutcomes);
        self::assertSame([], $verified->partStatuses);
    }

    public function testReceiptVerifierPassesTheServiceAuthorizationRejection(): void
    {
        $verified = (new JmhzReceiptVerifier(self::passThroughSignatures()))->verify(
            CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_NEM_PRI'),
            'vrep_apep',
            'test',
            CsszFormProtocolSample::CORRELATION,
        );

        self::assertSame('rejected', $verified->remoteStatus);
        self::assertSame([], $verified->formOutcomes);
    }

    private static function parse(string $xml): JmhzProtocolReport
    {
        return (new JmhzProtocolParser())->parse($xml, 1, CsszFormProtocolSample::CORRELATION);
    }

    /** @return list<\MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPart> */
    private static function forms(JmhzProtocolReport $report): array
    {
        return array_values(array_filter(
            $report->parts,
            static fn ($part): bool => $part->kind === JmhzProtocolPartKind::Form,
        ));
    }

    private static function passThroughSignatures(): JmhzProtocolSignatureVerifierInterface
    {
        return new class () implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };
    }
}
