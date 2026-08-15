<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Protokol o zpracování je TŘETÍ druh protokolu — chodí bez vyžádání do datové
 * schránky a je to doklad, který uživatel reálně dostane. Parser ho dřív neznal
 * a skončil by na něm chybou „neznámý druh protokolu", tedy právě u toho
 * jediného, který se v praxi otevírá nejčastěji.
 *
 * Tvar je opsaný ze dvou skutečně doručených protokolů (06/2026 a 07/2026);
 * identifikátory jsou nahrazené smyšlenými, aby se cizí údaje nedostaly do
 * repozitáře.
 */
final class JmhzProcessingProtocolTest extends TestCase
{
    private const CORRELATION = '8E72FD2813264449A40E51427F484E1C';
    private const SUBMISSION_GUID = 'F2865C9A-3953-48E6-BE44-4E5B9C921307';

    public function testDeliveredProtocolIsRead(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol());

        self::assertSame(JmhzProtocolKind::Completeness, $report->kind);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertSame(self::CORRELATION, $report->correlationReference);
        self::assertSame([], $report->errors);
    }

    /**
     * `idPodani` je GUID, který jsme vyrobili my. Párovat protokol k podání
     * naším identifikátorem je spolehlivější než jen tím od ČSSZ.
     */
    public function testSubmissionGuidComesBackForPairing(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol());

        self::assertSame(self::SUBMISSION_GUID, $report->submissionGuid);
    }

    /**
     * Do jedné datové schránky chodí protokoly ke všem podáním firmy. Vzít
     * ten, který zrovna přišel, by přeneslo stav cizího podání na naše.
     */
    public function testProtocolForAnotherSubmissionIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/jinému podání/');

        (new JmhzProtocolParser())->parse(
            self::protocol(),
            1,
            '56CFA011B9034D8CBB73C38CF1AC54D8',
        );
    }

    /** Kód a název stavu si nesmí odporovat — jeden z nich by pak lhal. */
    public function testStatusCodeAndLabelMustAgree(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/si odporují/');

        (new JmhzProtocolParser())->parse(str_replace(
            '<kod>1</kod>',
            '<kod>3</kod>',
            self::protocol(),
        ));
    }

    public function testUnreadableStatusIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);

        (new JmhzProtocolParser())->parse(str_replace(
            '<kod>1</kod>',
            '<kod></kod>',
            self::protocol(),
        ));
    }

    private static function protocol(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ProtokolOZpracovani'
            . ' xmlns="http://schemas.cssz.cz/JMHZ/ProtokolOZpracovani/2026">'
            . '<datumProtokolu>2026-07-02T16:20:20.382+02:00</datumProtokolu>'
            . '<variabilniSymbol>' . JmhzTransportSample::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<idKonkretnihoPodani>' . self::CORRELATION . '</idKonkretnihoPodani>'
            . '<datumPodani>2026-07-02T16:15:36+02:00</datumPodani>'
            . '<idPodani>' . self::SUBMISSION_GUID . '</idPodani>'
            . '<mesic>6</mesic>'
            . '<rok>2026</rok>'
            . '<stavMH>'
            . '<kod>1</kod>'
            . '<nazev>Hlášení je zpracováno a je úplné</nazev>'
            . '</stavMH>'
            . '</ProtokolOZpracovani>';
    }
}
