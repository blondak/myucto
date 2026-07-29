<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payment\SepaPaymentOrderWriter;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * SEPA export MUSÍ projít XSD validaci oficiálního schématu ISO 20022
 * `api/xsd/pain.001.001.03.xsd` (analogie k IsdocExporterSchemaTest / EPO XSD testům).
 * Čistě syntetická data — žádné reálné IBAN/doklady (repo je veřejné).
 */
final class SepaPaymentOrderXsdValidationTest extends TestCase
{
    public function testGeneratedXmlPassesOfficialPain001Xsd(): void
    {
        $writer = new SepaPaymentOrderWriter(new IbanValidator());
        $xml = $writer->build([
            'order_id' => 7,
            'initiator_name' => 'Testovací s.r.o.',
            'payer_name' => 'Testovací s.r.o.',
            'payer_iban' => 'CZ1801000000001000000005',
            'payer_bic' => 'GIBACZPX',
            'payment_date' => '2026-07-20',
            'currency' => 'EUR',
            'items' => [
                [
                    'payee_name' => 'Dodavatel A s.r.o.', 'iban' => 'DE89370400440532013000',
                    'bic' => 'COBADEFFXXX', 'amount' => 1234.56,
                    'variable_symbol' => '2026001', 'message' => 'FV-2026-001',
                ],
                [
                    'payee_name' => 'Fournisseur B', 'iban' => 'FR1420041010050500013M02606',
                    'bic' => null, 'amount' => 500.0,
                    'variable_symbol' => null, 'message' => 'Facture B',
                ],
                [
                    // Bez BIC, bez VS/zprávy (fallback na EndToEndId "PLATBAn").
                    'payee_name' => null, 'iban' => 'SK3112000000198742637541',
                    'bic' => null, 'amount' => 1.5,
                    'variable_symbol' => null, 'message' => null,
                ],
            ],
        ]);

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('pain001')) {
            self::markTestSkipped('Chybí api/xsd/pain.001.001.03.xsd.');
        }
        $result = $validator->validate($xml, 'pain001');

        self::assertSame('passed', $result['status'], implode("\n", $result['errors']));
    }

    /**
     * Regresní test na HIGH nález (adversariální review): `DbtrAgt` je v
     * pain.001.001.03 POVINNÝ element (na rozdíl od `CdtrAgt`, který má
     * minOccurs="0"). Dosavadní testy vždy posílaly `payer_bic`, takže větev
     * "plátce bez BIC" (běžné u CZ účtů — FE gatuje jen na IBAN, ne na BIC)
     * nikdy neprošla XSD validací. Writer musí `DbtrAgt` emitovat VŽDY, s
     * fallbackem `Othr/Id=NOTPROVIDED`, když BIC není znám.
     */
    public function testMissingPayerBicStillPassesXsdViaDbtrAgtFallback(): void
    {
        $writer = new SepaPaymentOrderWriter(new IbanValidator());
        $xml = $writer->build([
            'order_id' => 8,
            'payer_name' => 'Testovací s.r.o.',
            'payer_iban' => 'CZ1801000000001000000005',
            'payer_bic' => null, // ← klíčové: plátce BEZ BIC
            'payment_date' => '2026-07-20',
            'currency' => 'EUR',
            'items' => [
                [
                    'payee_name' => 'Dodavatel A s.r.o.', 'iban' => 'DE89370400440532013000',
                    'bic' => null, 'amount' => 100.0,
                ],
            ],
        ]);

        self::assertStringContainsString('<DbtrAgt>', $xml, 'DbtrAgt musí být přítomen i bez BIC plátce.');
        self::assertStringContainsString('NOTPROVIDED', $xml);

        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('pain001')) {
            self::markTestSkipped('Chybí api/xsd/pain.001.001.03.xsd.');
        }
        $result = $validator->validate($xml, 'pain001');

        self::assertSame('passed', $result['status'], implode("\n", $result['errors']));
    }
}
