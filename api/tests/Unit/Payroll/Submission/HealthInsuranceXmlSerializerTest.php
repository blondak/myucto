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
use DOMDocument;
use LibXMLError;
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

    /**
     * Ověří větu proti připnutému XSD. Tohle je ta kontrola, kterou bajtový
     * golden test sám neudělá — potvrzoval by i strukturu, kterou schéma
     * odmítá.
     */
    private function assertValidAgainstPinnedSchema(
        string $documentType,
        string $xml,
    ): void {
        $schema = $this->schemas->schemaFor($documentType);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode('; ', array_map(
            static fn (LibXMLError $error): string => trim($error->message),
            $errors,
        )));
    }

    // --- manifest a připnuté XSD ----------------------------------------

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
     * Obě schémata jsou v repu a otiskem sedí na manifest. Tenhle test hlídá
     * obojí najednou: jiný bajt v souboru shodí `available` a s ním celou
     * validační cestu.
     */
    public function testBothSchemasArePinnedByTheirHash(): void
    {
        self::assertTrue(
            $this->schemas->isBundleAvailable(),
            'Připnutá XSD musí být v api/xsd/zp/ a sedět otiskem.',
        );
        foreach ($this->schemas->documentTypes() as $documentType) {
            $schema = $this->schemas->schemaFor($documentType);
            self::assertFileExists($schema['path']);
            self::assertSame(
                HealthInsuranceSchemaCatalog::XSD_VERSION,
                $schema['xsd_version'],
            );
        }
    }

    /** Věta z jiného zdroje než ze serializéru schématem neprojde. */
    public function testTamperedDocumentIsRefusedByThePinnedSchema(): void
    {
        $xml = str_replace(
            '<typPrehledu>radny</typPrehledu>',
            '<typPrehledu>mimoradny</typPrehledu>',
            $this->serializer->serializePaymentOverview($this->paymentOverview()),
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document->loadXML($xml, LIBXML_NONET);
        $valid = $document->schemaValidate(
            $this->schemas->schemaFor(HealthInsuranceSchemaCatalog::PPZ)['path'],
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertFalse($valid, 'Neexistující typ přehledu nesmí projít.');
    }

    /** Plná validační cesta: doména, bajtová shoda se zdrojem i XSD. */
    public function testValidatorAcceptsTheSerializedPaymentOverview(): void
    {
        $payload = $this->paymentOverview();
        $this->validator->validatePaymentOverview(
            $payload,
            $this->serializer->serializePaymentOverview($payload),
        );
        self::assertTrue(true);
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
            <nazevPlatce>Testovací firma s.r.o.</nazevPlatce>
            <adresaPlatceUlice>Zkušební</adresaPlatceUlice>
            <adresaPlatceCisloPopisneOrientacni>12</adresaPlatceCisloPopisneOrientacni>
            <adresaPlatcePsc>11000</adresaPlatcePsc>
            <adresaPlatceObec>Praha 1</adresaPlatceObec>
            <adresaPlatceTelefon>420111222333</adresaPlatceTelefon>
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
                <ulice>Krátká 3</ulice>
                <obec>Brno</obec>
                <psc>60200</psc>
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
        $this->assertValidAgainstPinnedSchema(
            HealthInsuranceSchemaCatalog::HOZ,
            $xml,
        );
    }

    /**
     * Volitelná interní identifikace stojí ve schématu za oběma konstantami
     * a před kódem pojišťovny — ne kdekoli jinde.
     */
    public function testInternalReferenceGoesRightAfterTheFixedConstants(): void
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
            ],
            internalReference: 'hoz-2026-03-000123',
        );
        $xml = $this->serializer->serializeBulkNotification($payload);

        self::assertStringContainsString(
            "<identifikacePredmetuPodaniKod>882b97d9-3a41-4552-887b-3942ae92c3ea"
            . "</identifikacePredmetuPodaniKod>\n"
            . "  <interniIdentifikacePodaniPodavatele>hoz-2026-03-000123"
            . "</interniIdentifikacePodaniPodavatele>\n"
            . '  <kodZdravotniPojistovny>111</kodZdravotniPojistovny>',
            $xml,
        );
        $this->assertValidAgainstPinnedSchema(
            HealthInsuranceSchemaCatalog::HOZ,
            $xml,
        );
    }

    /**
     * Telefon je ve schématu `\d{1,30}` a volitelný: oddělovače se odstraní
     * a firma bez telefonu prvek prostě nemá.
     */
    public function testPhoneIsReducedToDigitsAndOmittedWhenEmpty(): void
    {
        $payload = new HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: new HealthEmployerIdentification(
                payerNumber: '1234567800',
                name: 'Testovací firma s.r.o.',
                street: 'Zkušební',
                houseNumber: '12',
                postalCode: '11000',
                city: 'Praha 1',
                phone: '',
            ),
            changes: [
                new HealthNotificationChange(
                    changeCode: 'P',
                    changedOn: '2026-03-01',
                    insuranceNumber: '9001011234',
                    firstName: 'Jan',
                    lastName: 'Novák',
                ),
            ],
        );
        $xml = $this->serializer->serializeBulkNotification($payload);

        self::assertStringNotContainsString('adresaPlatceTelefon', $xml);
        $this->assertValidAgainstPinnedSchema(
            HealthInsuranceSchemaCatalog::HOZ,
            $xml,
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
          <kodZdravotniPojistovny>111</kodZdravotniPojistovny>
          <typPrehledu>radny</typPrehledu>
          <identifikaceZamestnavatele>
            <identifikacniCisloPlatce>1234567800</identifikacniCisloPlatce>
            <nazevPlatce>Testovací firma s.r.o.</nazevPlatce>
            <adresaPlatceUlice>Zkušební</adresaPlatceUlice>
            <adresaPlatceCisloPopisneOrientacni>12</adresaPlatceCisloPopisneOrientacni>
            <adresaPlatcePsc>11000</adresaPlatcePsc>
            <adresaPlatceObec>Praha 1</adresaPlatceObec>
            <adresaPlatceTelefon>420111222333</adresaPlatceTelefon>
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

        $xml = $this->serializer->serializePaymentOverview(
            $this->paymentOverview(),
        );
        self::assertSame($expected, $xml);
        $this->assertValidAgainstPinnedSchema(
            HealthInsuranceSchemaCatalog::PPZ,
            $xml,
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
