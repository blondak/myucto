<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlSerializer;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlValidator;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationAddress;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use PHPUnit\Framework\TestCase;

final class HealthInsuranceXmlSerializerTest extends TestCase
{
    private HealthInsuranceSchemaCatalog $schemas;
    private HealthNotificationCodeCatalog $codes;
    private HealthInsuranceXmlSerializer $serializer;
    private HealthInsuranceXmlValidator $validator;

    protected function setUp(): void
    {
        $this->schemas = new HealthInsuranceSchemaCatalog();
        $this->codes = new HealthNotificationCodeCatalog();
        $this->serializer = new HealthInsuranceXmlSerializer(
            $this->schemas,
            $this->codes,
        );
        $this->validator = new HealthInsuranceXmlValidator(
            $this->schemas,
            $this->codes,
            $this->serializer,
        );
    }

    private function employer(): HealthEmployerIdentification
    {
        return HealthEmployerIdentification::fromBusinessId(
            businessId: '12345678',
            accountingUnit: '00',
            name: 'Testovací firma s.r.o.',
            street: 'Zkušební',
            houseNumber: '12',
            postalCode: '11000',
            city: 'Praha 1',
            phone: '+420111222333',
        );
    }

    // --- manifest a fail-closed XSD -------------------------------------

    public function testManifestPinsBothDocumentsOfTheSharedDataMessage(): void
    {
        self::assertSame(
            ['HOZ_2026', 'PPZ_2026'],
            $this->schemas->documentTypes(),
        );
        $hoz = $this->schemas->manifestFor(HealthInsuranceSchemaCatalog::HOZ);
        self::assertSame(
            'http://xmlns.vzp.cz/hromadneOznameniZamestnavatele/v1',
            $hoz['namespace'],
        );
        self::assertSame(
            '882b97d9-3a41-4552-887b-3942ae92c3ea',
            $hoz['subject_code'],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hoz['sha256']);
        self::assertStringStartsWith('https://', $hoz['url']);
    }

    public function testAllSevenInsurersShareTheSameDataMessage(): void
    {
        self::assertSame(
            ['111', '201', '205', '207', '209', '211', '213'],
            HealthInsuranceSchemaCatalog::INSURER_CODES,
        );
        foreach (HealthInsuranceSchemaCatalog::INSURER_CODES as $code) {
            $this->schemas->assertInsurerCode($code);
        }
        $this->expectException(HealthNotificationException::class);
        $this->schemas->assertInsurerCode('999');
    }

    /**
     * Balíček XSD v repu není (rešerše ho vědomě nezkopírovala), takže
     * validace musí selhat pojmenovaně — ne projít.
     */
    public function testSchemaLookupIsFailClosedWhileTheBundleIsMissing(): void
    {
        if ($this->schemas->isBundleAvailable()) {
            self::markTestSkipped(
                'Připnutý balíček XSD je k dispozici; fail-closed větev neplatí.',
            );
        }
        try {
            $this->schemas->schemaFor(HealthInsuranceSchemaCatalog::HOZ);
            self::fail('Bez připnutého XSD nesmí validace projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_schema_bundle_missing', $e->errorCode);
            self::assertStringContainsString('api/xsd/zp/', $e->getMessage());
        }
    }

    public function testValidationRefusesWhileTheBundleIsMissing(): void
    {
        if ($this->schemas->isBundleAvailable()) {
            self::markTestSkipped('Připnutý balíček XSD je k dispozici.');
        }
        $payload = $this->paymentOverview();
        $xml = $this->serializer->serializePaymentOverview($payload);
        try {
            $this->validator->validatePaymentOverview($payload, $xml);
            self::fail('Validace bez XSD nesmí projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_schema_bundle_missing', $e->errorCode);
        }
    }

    public function testUnknownDocumentTypeIsRefused(): void
    {
        try {
            $this->schemas->manifestFor('HOZ_2025');
            self::fail('Neznámý dokument nesmí projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_document_type_unknown', $e->errorCode);
        }
    }

    // --- HOZ -------------------------------------------------------------

    public function testBulkNotificationIsSerializedDeterministically(): void
    {
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: $this->employer(),
            changes: [
                new HealthNotificationChange(
                    changeCode: 'P',
                    changedOn: '2026-03-01',
                    insuranceNumber: '9001011234',
                    firstName: 'Jan',
                    lastName: 'Novák',
                ),
                new HealthNotificationChange(
                    changeCode: 'O',
                    changedOn: '2026-06-30',
                    insuranceNumber: 'M01021990',
                    firstName: 'Eva',
                    lastName: 'Dvořáková',
                    address: new HealthNotificationAddress(
                        street: 'Krátká',
                        houseNumber: '3',
                        postalCode: '60200',
                        city: 'Brno',
                    ),
                ),
            ],
        );

        $expected = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <hromadneOznameniZamestnavatele xmlns="http://xmlns.vzp.cz/hromadneOznameniZamestnavatele/v1">
          <identifikacePredmetuPodaniText>Hromadné oznámení zaměstnavatele 2026+</identifikacePredmetuPodaniText>
          <identifikacePredmetuPodaniKod>882b97d9-3a41-4552-887b-3942ae92c3ea</identifikacePredmetuPodaniKod>
          <kodZdravotniPojistovny>111</kodZdravotniPojistovny>
          <identifikaceZamestnavatele>
            <identifikacniCisloPlatce>1234567800</identifikacniCisloPlatce>
            <nazev>Testovací firma s.r.o.</nazev>
            <ulice>Zkušební</ulice>
            <cisloPopisne>12</cisloPopisne>
            <psc>11000</psc>
            <obec>Praha 1</obec>
            <telefon>+420111222333</telefon>
          </identifikaceZamestnavatele>
          <seznamZmenZamestnancu>
            <zmenaZamestance>
              <kodzmeny>P</kodzmeny>
              <datumZmeny>2026-03-01</datumZmeny>
              <cisloPojistence>9001011234</cisloPojistence>
              <jmeno>Jan</jmeno>
              <prijmeni>Novák</prijmeni>
            </zmenaZamestance>
            <zmenaZamestance>
              <kodzmeny>O</kodzmeny>
              <datumZmeny>2026-06-30</datumZmeny>
              <cisloPojistence>M01021990</cisloPojistence>
              <jmeno>Eva</jmeno>
              <prijmeni>Dvořáková</prijmeni>
              <adresa>
                <ulice>Krátká</ulice>
                <cisloPopisne>3</cisloPopisne>
                <psc>60200</psc>
                <obec>Brno</obec>
              </adresa>
            </zmenaZamestance>
          </seznamZmenZamestnancu>
        </hromadneOznameniZamestnavatele>
        XML;

        $xml = $this->serializer->serializeBulkNotification($payload);
        self::assertSame($expected, $xml);
        self::assertSame(
            $xml,
            $this->serializer->serializeBulkNotification($payload),
        );
    }

    /**
     * Kód, který zaměstnavatel od 2026 nehlásí, nesmí projít serializérem —
     * XSD by ho propustilo.
     */
    public function testSerializerRefusesACodeTheEmployerNoLongerReports(): void
    {
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: $this->employer(),
            changes: [
                new HealthNotificationChange(
                    changeCode: 'D',
                    changedOn: '2026-03-01',
                    insuranceNumber: '9001011234',
                    firstName: 'Jan',
                    lastName: 'Novák',
                ),
            ],
        );
        try {
            $this->serializer->serializeBulkNotification($payload);
            self::fail('Kód D po zúžení nesmí do podání.');
        } catch (HealthNotificationException $e) {
            self::assertSame(
                'zp_change_code_not_reported_by_employer',
                $e->errorCode,
            );
        }
    }

    public function testSameCodeBefore2026PassesBecauseTheDutyWasStillTheEmployers(): void
    {
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: $this->employer(),
            changes: [
                new HealthNotificationChange(
                    changeCode: 'D',
                    changedOn: '2025-12-31',
                    insuranceNumber: '9001011234',
                    firstName: 'Jan',
                    lastName: 'Novák',
                ),
            ],
        );
        self::assertStringContainsString(
            '<kodzmeny>D</kodzmeny>',
            $this->serializer->serializeBulkNotification($payload),
        );
    }

    public function testEmptyBulkNotificationIsRefused(): void
    {
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: $this->employer(),
            changes: [],
        );
        try {
            $this->serializer->serializeBulkNotification($payload);
            self::fail('Prázdné hromadné oznámení nedává smysl.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_bulk_notification_empty', $e->errorCode);
        }
    }

    public function testInsuranceNumberAcceptsBothDocumentedForms(): void
    {
        foreach (['123456789', '9001011234', 'M01021990', 'Z31121999'] as $n) {
            $change = new HealthNotificationChange(
                changeCode: 'P',
                changedOn: '2026-03-01',
                insuranceNumber: $n,
                firstName: 'Jan',
                lastName: 'Novák',
            );
            $change->assertValid($this->codes);
        }
        $this->expectException(HealthNotificationException::class);
        (new HealthNotificationChange(
            changeCode: 'P',
            changedOn: '2026-03-01',
            insuranceNumber: '12345678',
            firstName: 'Jan',
            lastName: 'Novák',
        ))->assertValid($this->codes);
    }

    // --- identifikace plátce ---------------------------------------------

    public function testPayerNumberIsBusinessIdFollowedByAccountingUnit(): void
    {
        $employer = HealthEmployerIdentification::fromBusinessId(
            businessId: '00012345',
            accountingUnit: '07',
            name: 'F',
            street: 'U',
            houseNumber: '1',
            postalCode: '11000',
            city: 'P',
            phone: '1',
        );
        self::assertSame('0001234507', $employer->payerNumber);
        $employer->assertValid();
    }

    public function testShortBusinessIdIsRefusedInsteadOfPadded(): void
    {
        try {
            HealthEmployerIdentification::fromBusinessId(
                businessId: '12345',
                accountingUnit: '00',
                name: 'F',
                street: 'U',
                houseNumber: '1',
                postalCode: '11000',
                city: 'P',
                phone: '1',
            );
            self::fail('Kratší IČO se nesmí doplňovat.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_payer_business_id_invalid', $e->errorCode);
        }
    }

    public function testPostalCodeMustBeFiveDigitsWithoutASpace(): void
    {
        $employer = new HealthEmployerIdentification(
            payerNumber: '1234567800',
            name: 'F',
            street: 'U',
            houseNumber: '1',
            postalCode: '110 00',
            city: 'P',
            phone: '1',
        );
        try {
            $employer->assertValid();
            self::fail('PSČ s mezerou neprojde.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_postal_code_invalid', $e->errorCode);
        }
    }

    // --- PPZ -------------------------------------------------------------

    private function paymentOverview(): HealthPaymentOverviewPayload
    {
        return new HealthPaymentOverviewPayload(
            insurerCode: '111',
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: $this->employer(),
            month: 1,
            year: 2026,
            employeeCount: 3,
            assessmentBaseMinorUnits: 12345600,
            contributionCzk: 16667,
        );
    }

    public function testPaymentOverviewIsSerializedDeterministically(): void
    {
        $expected = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <prehledPlatbyZamestnavatele xmlns="http://xmlns.vzp.cz/PrehledPlatbyZamestnavatele/v1">
          <identifikacePredmetuPodaniText>Přehled platby zaměstnavatele pro ZP 2026+</identifikacePredmetuPodaniText>
          <identifikacePredmetuPodaniKod>1079e224-84f4-46e4-99e8-6095bd282301</identifikacePredmetuPodaniKod>
          <typPrehledu>radny</typPrehledu>
          <kodZdravotniPojistovny>111</kodZdravotniPojistovny>
          <identifikaceZamestnavatele>
            <identifikacniCisloPlatce>1234567800</identifikacniCisloPlatce>
            <nazev>Testovací firma s.r.o.</nazev>
            <ulice>Zkušební</ulice>
            <cisloPopisne>12</cisloPopisne>
            <psc>11000</psc>
            <obec>Praha 1</obec>
            <telefon>+420111222333</telefon>
          </identifikaceZamestnavatele>
          <udajePlatby>
            <mesicHlaseni>1</mesicHlaseni>
            <rokHlaseni>2026</rokHlaseni>
            <pocetZamestnancu>3</pocetZamestnancu>
            <soucetZakladuPojistneho>123456.00</soucetZakladuPojistneho>
            <soucetPojistneho>16667</soucetPojistneho>
          </udajePlatby>
        </prehledPlatbyZamestnavatele>
        XML;

        self::assertSame(
            $expected,
            $this->serializer->serializePaymentOverview($this->paymentOverview()),
        );
    }

    /** `pocetZamestnancu` je positiveInteger — nula datovou větou neprojde. */
    public function testZeroEmployeeOverviewIsRefusedWithAnExplanation(): void
    {
        $payload = new HealthPaymentOverviewPayload(
            insurerCode: '111',
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: $this->employer(),
            month: 1,
            year: 2026,
            employeeCount: 0,
            assessmentBaseMinorUnits: 0,
            contributionCzk: 0,
        );
        try {
            $this->serializer->serializePaymentOverview($payload);
            self::fail('Nulový přehled datová věta neumí.');
        } catch (HealthNotificationException $e) {
            self::assertSame(
                'zp_overview_employee_count_invalid',
                $e->errorCode,
            );
            self::assertStringContainsString('positiveInteger', $e->getMessage());
        }
    }

    public function testAssessmentBaseKeepsTwoDecimalsFromMinorUnits(): void
    {
        foreach ([
            0 => '0.00',
            5 => '0.05',
            99 => '0.99',
            100 => '1.00',
            12345678 => '123456.78',
        ] as $minorUnits => $expected) {
            $payload = new HealthPaymentOverviewPayload(
                insurerCode: '111',
                overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
                employer: $this->employer(),
                month: 1,
                year: 2026,
                employeeCount: 1,
                assessmentBaseMinorUnits: $minorUnits,
                contributionCzk: 1,
            );
            self::assertSame($expected, $payload->assessmentBaseDecimal());
        }
    }

    public function testCorrectiveOverviewUsesTheDocumentedKeyword(): void
    {
        $payload = new HealthPaymentOverviewPayload(
            insurerCode: '207',
            overviewKind: HealthPaymentOverviewPayload::KIND_CORRECTIVE,
            employer: $this->employer(),
            month: 12,
            year: 2026,
            employeeCount: 1,
            assessmentBaseMinorUnits: 100,
            contributionCzk: 1,
        );
        $xml = $this->serializer->serializePaymentOverview($payload);
        self::assertStringContainsString('<typPrehledu>opravny</typPrehledu>', $xml);
        self::assertStringContainsString(
            '<kodZdravotniPojistovny>207</kodZdravotniPojistovny>',
            $xml,
        );
    }

    public function testOutOfRangeMonthAndYearAreRefused(): void
    {
        foreach ([[13, 2026], [0, 2026], [1, 1999], [1, 2100]] as [$m, $y]) {
            $payload = new HealthPaymentOverviewPayload(
                insurerCode: '111',
                overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
                employer: $this->employer(),
                month: $m,
                year: $y,
                employeeCount: 1,
                assessmentBaseMinorUnits: 100,
                contributionCzk: 1,
            );
            try {
                $this->serializer->serializePaymentOverview($payload);
                self::fail("Měsíc {$m} a rok {$y} nesmí projít.");
            } catch (HealthNotificationException $e) {
                self::assertContains(
                    $e->errorCode,
                    [
                        'zp_overview_month_invalid',
                        'zp_overview_year_invalid',
                    ],
                );
            }
        }
    }

    public function testValidatorRejectsBytesThatDidNotComeFromTheSource(): void
    {
        $payload = $this->paymentOverview();
        try {
            $this->validator->validatePaymentOverview(
                $payload,
                '<prehledPlatbyZamestnavatele/>',
            );
            self::fail('Cizí bajty se nesmí uložit jako podání.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_xml_snapshot_mismatch', $e->errorCode);
        }
    }
}
