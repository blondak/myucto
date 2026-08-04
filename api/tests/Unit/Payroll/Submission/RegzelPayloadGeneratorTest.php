<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Regzel\RegzelPayloadSnapshot;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelValidationException;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlGenerator;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlValidator;
use PHPUnit\Framework\TestCase;

final class RegzelPayloadGeneratorTest extends TestCase
{
    public function testGeneratesStableExactBytesAcceptedByPinnedSchema(): void
    {
        $snapshot = self::snapshot();
        $generator = new RegzelXmlGenerator();
        $xml = $generator->generate($snapshot);

        self::assertSame(self::goldenXml(), $xml);
        self::assertSame(hash('sha256', self::goldenXml()), hash('sha256', $xml));

        (new RegzelXmlValidator(new RegzelSchemaCatalog()))
            ->validate($snapshot, $xml);
    }

    public function testTestEnvironmentRequiresReservedVariableSymbol(): void
    {
        $snapshot = self::snapshot(environment: 'test');

        try {
            (new RegzelXmlValidator(new RegzelSchemaCatalog()))
                ->validateSnapshot($snapshot);
            self::fail('Testovací podání bez VS začínajícího 999 muselo selhat.');
        } catch (RegzelValidationException $exception) {
            self::assertSame('regzel_test_variable_symbol_required', $exception->validationCode);
        }
    }

    public function testUnsupportedEmployerRegistrationFailsClosed(): void
    {
        try {
            (new RegzelSchemaCatalog())->schemaFor('employer_registration');
            self::fail('Chybějící oficiální REGZEL XSD nesmí být nahrazen odhadem.');
        } catch (RegzelValidationException $exception) {
            self::assertSame('regzel_schema_unavailable', $exception->validationCode);
        }
    }

    private static function snapshot(string $environment = 'production'): RegzelPayloadSnapshot
    {
        return new RegzelPayloadSnapshot(
            supplierId: 11,
            officeId: 29,
            environment: $environment,
            interaction: 'supplemental_information',
            csszWorkplaceCode: '110',
            taxOfficeCode: '2001',
            taxOfficeWorkplaceCode: '2002',
            socialSecurityVariableSymbol: '1234567890',
            payerReferenceNumber: '123456789',
            notificationDataBoxId: 'abc1234',
            socialEnterprise: true,
            employmentAgency: false,
            protectedLaborMarket: true,
            employerSettingsRowVersion: 4,
            officeRowVersion: 7,
            profileRowVersion: 2,
            supplierUpdatedAt: '2026-08-04 00:00:00',
        );
    }

    private static function goldenXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<REGZELDOPL xmlns="http://schemas.cssz.cz/REGZELDOPL/2025" version="1.2" partialAccept="N">
  <VENDOR productName="MyÚčto.cz" productVersion="regzeldopl25-map-1"/>
  <formular>
    <hlavicka>
      <kodPracovisteCSSZ>110</kodPracovisteCSSZ>
      <kodFU>2001</kodFU>
      <kodPracovisteFU>2002</kodPracovisteFU>
    </hlavicka>
    <zamestnavatel>
      <vs>1234567890</vs>
      <vcp>123456789</vcp>
      <datovaSchranka>abc1234</datovaSchranka>
      <doplnInformace>
        <socialniPodnik>true</socialniPodnik>
        <agenturaPrace>false</agenturaPrace>
        <chranenyTrh>true</chranenyTrh>
      </doplnInformace>
    </zamestnavatel>
  </formular>
</REGZELDOPL>
XML;
    }
}
