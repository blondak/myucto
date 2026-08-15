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

    /**
     * Protokol nese variabilní symbol, období i datumy. Bez nich se načtený
     * soubor nedá ověřit ani zařadit k období — a přesně to jsou údaje,
     * kvůli kterým se do protokolu kouká.
     */
    public function testProtocolCarriesItsOwnIdentity(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol());

        self::assertSame(JmhzTransportSample::VARIABLE_SYMBOL, $report->variableSymbol);
        self::assertSame(6, $report->periodMonth);
        self::assertSame(2026, $report->periodYear);
        self::assertSame('2026-07-02T16:20:20.382+02:00', $report->protocolDate);
        self::assertSame('2026-07-02T16:15:36+02:00', $report->submittedDate);
    }

    /**
     * REGRESE: chyby v protokolu o zpracování se četly v namespace odpovědi
     * DZMH, takže `kod` nebyl k nalezení, přečetl se jako nula a
     * `JmhzProtocolError::fromCode(0)` shodila celý protokol. Nečitelný byl
     * tedy právě ten protokol, který chyby má — jediný, kvůli kterému stojí
     * za to ho otevřít.
     */
    public function testFailedProtocolIsReadableAndKeepsItsErrorCodes(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            '3',
            'Hlášení je zamítnuto',
            '<chybySeznam><chyba>'
                . '<id>1</id><typChyby>zpracovani</typChyby>'
                . '<castPodani>form</castPodani>'
                . '<idFormulare>' . JmhzTransportSample::FORM_GUID . '</idFormulare>'
                . '<kod>20301</kod>'
                . '<popis>Pojistné neodpovídá vyměřovacímu základu.</popis>'
                . '</chyba></chybySeznam>',
        ));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        self::assertCount(1, $report->errors);
        self::assertSame(20301, $report->errors[0]->code);
        self::assertSame(301, $report->errors[0]->controlId?->value);
        self::assertSame(
            'Pojistné neodpovídá vyměřovacímu základu.',
            $report->errors[0]->message,
        );
        // Chyba musí zůstat přiřazená k formuláři, jinak se nedá dohledat,
        // koho se týká.
        self::assertCount(1, $report->errorsForForm(JmhzTransportSample::FORM_GUID));
    }

    /** Variabilní symbol mimo doložený tvar nesmí projít jako ověřený údaj. */
    public function testUnreadableVariableSymbolIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/[Vv]ariabilní symbol/');

        (new JmhzProtocolParser())->parse(str_replace(
            '<variabilniSymbol>' . JmhzTransportSample::VARIABLE_SYMBOL . '</variabilniSymbol>',
            '<variabilniSymbol>99900000012345</variabilniSymbol>',
            self::protocol(),
        ));
    }

    private static function protocol(
        string $statusCode = '1',
        string $statusLabel = 'Hlášení je zpracováno a je úplné',
        string $failures = '',
    ): string {
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
            . '<kod>' . $statusCode . '</kod>'
            . '<nazev>' . $statusLabel . '</nazev>'
            . '</stavMH>'
            . $failures
            . '</ProtokolOZpracovani>';
    }
}
