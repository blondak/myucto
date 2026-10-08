<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Obálka VREP pro NEMPRI25, HZUPN20 a OZUSPOJ23.
 *
 * Zdroj: ČSSZ „Přehled obálek" (`CSSZSubmClasses.pdf`, stažen 8. 10. 2026):
 * NEMPRI25 i HZUPN20 mají třídu `CSSZ_NEM_PRI` a liší se jen `eType`,
 * OZUSPOJ23 má `CSSZ_OZUSPOJ`. Variabilní symbol klíče `vars` se musí shodovat
 * s VS v datové větě (podací protokol v1.47, str. 30).
 */
final class JmhzGovTalkCsszFormShapeTest extends TestCase
{
    private const VS = '1234567890';

    /** @return iterable<string,array{string,string,string}> */
    public static function forms(): iterable
    {
        yield 'NEMPRI25' => ['NEMPRI25', 'CSSZ_NEM_PRI', self::nempri(self::VS)];
        yield 'HZUPN20' => ['HZUPN20', 'CSSZ_NEM_PRI', self::hzupn(self::VS)];
        yield 'OZUSPOJ23' => ['OZUSPOJ23', 'CSSZ_OZUSPOJ', self::ozuspoj(self::VS)];
    }

    #[DataProvider('forms')]
    public function testEnvelopeCarriesTheDocumentedClassAndFormPair(
        string $form,
        string $class,
        string $payload,
    ): void {
        self::assertSame($class, JmhzGovTalkRequestShape::classForForm($form));

        $xml = $this->build(JmhzGovTalkRequestShape::forForm($form), $payload, $class);

        self::assertStringContainsString("<Class>{$class}</Class>", $xml);
        self::assertStringContainsString("eType=\"{$form}\"", $xml);
        self::assertStringContainsString('<Key Type="vars">' . self::VS . '</Key>', $xml);
    }

    /** Dvě agendy sdílí třídu: z třídy samotné se `eType` odvodit nesmí. */
    public function testSharedClassCannotPickItsFormOnItsOwn(): void
    {
        self::assertNull(JmhzGovTalkRequestShape::envelopeTypeFor('CSSZ_NEM_PRI'));
        self::assertSame('OZUSPOJ23', JmhzGovTalkRequestShape::envelopeTypeFor('CSSZ_OZUSPOJ'));

        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('není doložený `eType`');
        JmhzGovTalkRequestShape::forSubmissionClass('CSSZ_NEM_PRI');
    }

    #[DataProvider('forms')]
    public function testVariableSymbolMustMatchTheDataSentence(
        string $form,
        string $class,
        string $payload,
    ): void {
        try {
            $this->build(
                JmhzGovTalkRequestShape::forForm($form),
                str_replace(self::VS, '1234567899', $payload),
                $class,
            );
            self::fail('Obálka s jiným VS než datová věta nesmí vzniknout.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_govtalk_variable_symbol_mismatch', $exception->errorCode);
        }
    }

    public function testFormOfAnotherAgendaIsRefused(): void
    {
        try {
            $this->build(JmhzGovTalkRequestShape::forForm('HZUPN20'), self::hzupn(self::VS), 'CSSZ_OZUSPOJ');
            self::fail('Formulář HZUPN20 nesmí odejít pod třídou CSSZ_OZUSPOJ.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_govtalk_envelope_type_mismatch', $exception->errorCode);
        }
    }

    public function testDataSentenceOfAnotherFormIsRefused(): void
    {
        try {
            $this->build(JmhzGovTalkRequestShape::forForm('NEMPRI25'), self::hzupn(self::VS), 'CSSZ_NEM_PRI');
            self::fail('Datová věta HZUPN nesmí odejít s eType NEMPRI25.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_govtalk_payload_invalid', $exception->errorCode);
        }
    }

    public function testPollRequestUsesTheSharedClass(): void
    {
        $poll = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::forForm('HZUPN20')))
            ->pollRequest('C0000000000000000000000000000001', self::VS, 'CSSZ_NEM_PRI');

        self::assertStringContainsString('<Class>CSSZ_NEM_PRI</Class>', $poll);
        self::assertStringContainsString('<Qualifier>poll</Qualifier>', $poll);
        self::assertStringContainsString('<Key Type="vars">' . self::VS . '</Key>', $poll);
    }

    private function build(JmhzGovTalkRequestShape $shape, string $payload, string $class): string
    {
        return (new JmhzGovTalkEnvelope($shape))->build(
            $payload,
            self::VS,
            $class,
            'test',
            new JmhzSoftwareIdentification('MyÚčto.cz', '6.0.0'),
        )->unsignedXml;
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
