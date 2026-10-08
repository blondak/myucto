<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationRecord;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationXmlReader;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationXmlReaderTest extends TestCase
{
    private RegistrationXmlReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RegistrationXmlReader(new PayrollRegistrationSchemaCatalog());
    }

    public function testRegistrationA1IsReadWithIdentityAddressAndJob(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $read = $this->reader->read(RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]));

        self::assertSame('REGZEC25', $read['document_type']);
        self::assertCount(1, $read['records']);
        $record = $read['records'][0];
        self::assertSame(1, $record->actionCode);
        self::assertSame(1, $record->sequence);
        self::assertSame($birthNumber, $record->birthNumber);
        self::assertSame('Jana Testovací', $record->fullName());
        self::assertSame('Ing.', $record->titlePrefix);
        self::assertSame('female', $record->sex);
        self::assertSame('Zkušební', $record->birthSurname);
        self::assertSame('CZ', $record->citizenshipCountryCode);
        self::assertSame('2026-07-01', $record->startOn);
        self::assertSame('2026-07-01', $record->decisiveDate());
        self::assertSame('employment', $record->relationType());
        self::assertSame('43111', $record->professionCode);
        self::assertSame('554782', $record->workplaceMunicipalityCode);
        self::assertSame('111', $record->healthInsurerCode);
        self::assertSame('M', $record->highestEducationCode);
        self::assertSame([
            'street' => 'Zkušební',
            'house_number' => '12',
            'orientation_number' => null,
            'postal_code' => '11000',
            'city' => 'Praha',
            'country_code' => 'CZ',
        ], $record->permanentAddress);
    }

    /**
     * `fdr` (adresa pobytu v ČR) atribut státu nemá; bez doplnění `CZ` by
     * profil A1 nesl adresu bez státu a A1/A3 by se z něj nedalo sestavit.
     */
    public function testCzechResidenceAddressGetsCzechCountry(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::regzecA1(['fdr' => '60200']));

        $profile = $read['records'][0]->a1Profile;
        self::assertSame('CZ', $profile['czech_residence_address']['country_code']);
        self::assertSame('60200', $profile['czech_residence_address']['postal_code']);
        self::assertSame('Brno', $profile['czech_residence_address']['city']);
        self::assertSame('CZ', $profile['permanent_address']['country_code']);
    }

    /**
     * Adresa obce bez ulic (`adr` bez `str`) nese ulici výslovně prázdnou:
     * profil A1 jinak ponechal jednořádkovou ulici z návrhu („Obec 424")
     * a příští věta poslala číslo popisné dvakrát.
     */
    public function testAddressWithoutStreetCarriesExplicitlyEmptyStreet(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::regzecA1([
            'street' => null,
            'num' => '424',
            'city' => 'Testov',
        ]));

        $address = $read['records'][0]->a1Profile['permanent_address'];
        self::assertArrayHasKey('street', $address);
        self::assertNull($address['street']);
        self::assertArrayHasKey('orientation_number', $address);
        self::assertNull($address['orientation_number']);
        self::assertSame('424', $address['house_number']);
        self::assertSame('Testov', $address['city']);
    }

    /**
     * ČSSZ dávku zpracovává po větách (partialAccept): věta mimo schéma se
     * odmítne, ostatní se přijmou. Import dřív kvůli jedné vadné větě
     * (Premier `cnt="Čes"`) zahodil celý soubor i s devíti přijatými větami.
     */
    public function testSentenceOutsideSchemaIsRejectedAloneWhenTheRestOfTheFileIsValid(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::twoA1Sentences(true, false));

        self::assertCount(1, $read['records']);
        self::assertSame(2, $read['records'][0]->sequence);
        self::assertCount(1, $read['rejected']);
        self::assertSame(1, $read['rejected'][0]['position']);
        self::assertSame('1', $read['rejected'][0]['sequence']);
        self::assertStringContainsString('cnt', $read['rejected'][0]['reason']);

        $valid = $this->reader->read(RegistrationXmlFixtures::twoA1Sentences(false, false));
        self::assertCount(2, $valid['records']);
        self::assertSame([], $valid['rejected']);
    }

    /** Když vadu nejde přičíst jen některým větám, odmítne se soubor celý. */
    public function testFileWithAllSentencesOutsideSchemaIsRejectedWhole(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('neodpovídá schématu');

        $this->reader->read(RegistrationXmlFixtures::twoA1Sentences(true, true));
    }

    /** IMP-01, IMP-06, IMP-07: VS (starý i nový) zaměstnavatele, dřívější příjmení a VČP se čtou. */
    public function testEmployerSymbolsFormerSurnameAndVcpAreRead(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::regzecA1([
            'vs' => '1234567890',
            'nvs' => '9876543210',
            'ona' => 'Dřívější',
            'vcp' => '612345678',
        ]));

        $record = $read['records'][0];
        self::assertSame('1234567890', $record->employerVariableSymbol);
        self::assertSame('9876543210', $record->employerNewVariableSymbol);
        self::assertSame('Dřívější', $record->formerSurname);
        self::assertSame('612345678', $record->vcp);
        self::assertSame('Zkušební', $record->birthSurname, 'ona se nesmí zaměnit s rodným příjmením.');
    }

    public function testPreRegistrationReadsEmployerSymbol(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::prezecP1(
            RegistrationXmlFixtures::birthNumber('1985-03-04', 'female', 2),
            'Petra',
            'Nováková',
            '2026-12-01',
        ));

        self::assertSame('1234567890', $read['records'][0]->employerVariableSymbol);
        self::assertNull($read['records'][0]->employerNewVariableSymbol);
    }

    /** IMP-08: úmrtí a kód důvodu ukončení se čtou. */
    public function testDeregistrationReadsDeathAndTerminationReason(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::regzecA2(
            RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            RegistrationXmlFixtures::oic(7),
            '200000000000000000101',
            '2026-08-31',
            ['endbydeath' => 'A', 'rsnterempl' => '15'],
        ));

        $record = $read['records'][0];
        self::assertTrue($record->endedByDeath);
        self::assertSame('15', $record->terminationReasonCode);

        $plain = $this->reader->read(RegistrationXmlFixtures::regzecA2(
            RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            RegistrationXmlFixtures::oic(7),
            '200000000000000000101',
            '2026-08-31',
        ))['records'][0];
        self::assertFalse($plain->endedByDeath);
        self::assertNull($plain->terminationReasonCode);
    }

    public function testPreRegistrationIsReadWithExpectedStart(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::prezecP1(
            RegistrationXmlFixtures::birthNumber('1985-03-04', 'female', 2),
            'Petra',
            'Nováková',
            '2026-12-01',
        ));

        self::assertSame('PREZEC26', $read['document_type']);
        $record = $read['records'][0];
        self::assertSame(9, $record->actionCode);
        self::assertSame('2026-12-01', $record->expectedStartOn);
        self::assertSame('2026-12-01', $record->decisiveDate());
        self::assertSame('Testov', $record->birthPlace);
    }

    public function testCsszEmployeeExportIsReadWithIdentifiersAndActivity(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $oic = RegistrationXmlFixtures::oic(7);
        $read = $this->reader->read(RegistrationXmlFixtures::csszExport([
            [
                'RodneCislo' => $birthNumber,
                'Prijmeni' => 'Testovací',
                'Jmeno' => 'Jana',
                'KodDruhuCinnosti' => '2',
                'ZMR' => 'A',
                'IdZamestnani' => '2000000000101',
                'OIC' => $oic,
            ],
            [
                'EvidencniCisloPojistence' => '9005410005',
                'OIC' => RegistrationXmlFixtures::oic(8),
                'Prijmeni' => 'Zkušební',
                'Jmeno' => 'Petr',
                'KodDruhuCinnosti' => 't',
                'PojistnyVztahDo' => '2026-06-30',
            ],
        ]));

        self::assertSame(RegistrationRecord::CSSZ_EXPORT, $read['document_type']);
        self::assertCount(2, $read['records']);
        $record = $read['records'][0];
        self::assertTrue($record->isCsszExport());
        self::assertSame(1, $record->position);
        self::assertSame(0, $record->actionCode);
        self::assertSame('2026-09-20', $record->preparedOn);
        self::assertSame($birthNumber, $record->birthNumber);
        self::assertSame($oic, $record->personIdentifier);
        self::assertSame('2000000000101', $record->employmentIdentifier);
        self::assertSame('Jana Testovací', $record->fullName());
        self::assertSame('2', $record->activityCode);
        self::assertTrue($record->smallScale);
        self::assertSame('small_scale_employment', $record->relationType());
        self::assertSame('1234567890', $record->employerVariableSymbol);
        self::assertNull($record->startOn);
        self::assertSame('2026-09-20', $record->decisiveDate());

        self::assertNull($record->insuranceFrom, 'Dosavadní tvar exportu začátek pojistného vztahu nenese.');

        $second = $read['records'][1];
        self::assertNull($second->birthNumber);
        self::assertSame('9005410005', $second->insuredPersonNumber);
        self::assertSame('T', $second->activityCode);
        self::assertSame('dpp', $second->relationType());
        self::assertSame('2026-06-30', $second->insuranceTo);

        $started = $record->withDerivedStart([
            'on' => '2026-01-01',
            'source' => 'insurance_from',
            'period' => '2026-01',
            'earliest_period' => '2026-01',
        ]);
        self::assertSame('2026-01-01', $started->startOn);
        self::assertSame('2026-01-01', $started->decisiveDate());
        self::assertSame('insurance_from', $started->derivedStart['source'] ?? null);
        self::assertNull($record->startOn);
    }

    /**
     * Tvar od 15. 10. 2026: začátek pojistného vztahu, bližší určení činnosti
     * a EČP místo rodného čísla u cizince.
     */
    public function testCsszEmployeeExportFrom2026October(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::csszExport([
            [
                'RodneCislo' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
                'PojistnyVztahOd' => '2025-03-01',
                'KodBlizsihoUrceniCinnosti' => '1',
                'NazevBlizsihoUrceniCinnosti' => 'Žádné',
                'IdZamestnani' => '2000000000101',
            ],
            [
                'EvidencniCisloPojistence' => '9005410005',
                'PojistnyVztahOd' => '2024-02-01',
                'PojistnyVztahDo' => '2026-07-31',
                'KodDruhuCinnosti' => '2',
                'IdZamestnani' => '2000000000102',
            ],
        ]));

        [$first, $second] = $read['records'];
        self::assertSame('2025-03-01', $first->insuranceFrom);
        self::assertNull($first->insuranceTo);
        self::assertSame('1', $first->relationshipDetailCode);
        self::assertNull($first->startOn, 'Začátek pojištění není sám o sobě nástup; rozhoduje planner.');
        self::assertTrue($first->insuranceStartIsEmploymentStart());
        self::assertNull($second->birthNumber);
        self::assertSame('9005410005', $second->insuredPersonNumber);
        self::assertSame(['2024-02-01', '2026-07-31'], [$second->insuranceFrom, $second->insuranceTo]);
    }

    /** Zaměstnání malého rozsahu a DPP: začátek pojištění nástupem být nemusí. */
    public function testInsuranceStartOfSmallScaleAndDppIsNotEmploymentStart(): void
    {
        $read = $this->reader->read(RegistrationXmlFixtures::csszExport([
            ['RodneCislo' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1), 'PojistnyVztahOd' => '2025-03-01', 'ZMR' => 'A'],
            ['RodneCislo' => RegistrationXmlFixtures::birthNumber('1991-02-16', 'male', 2), 'PojistnyVztahOd' => '2025-04-01', 'KodDruhuCinnosti' => 'T'],
        ]));

        self::assertFalse($read['records'][0]->insuranceStartIsEmploymentStart());
        self::assertFalse($read['records'][1]->insuranceStartIsEmploymentStart());
    }

    /** @return iterable<string,array{list<array<string,string|null>>,string}> */
    public static function rejectedExports(): iterable
    {
        $rc = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        yield 'krátké OIČ' => [[['RodneCislo' => $rc, 'OIC' => '123456789']], 'neplatný OIČ'];
        yield 'ZMR mimo A/N' => [[['RodneCislo' => $rc, 'ZMR' => 'X']], 'neodpovídá schématu MPSV'];
        yield 'VS s písmeny' => [[['RodneCislo' => $rc, 'VariabilniSymbol' => '12AB']], 'variabilní symbol'];
        yield 'bez rodného čísla i EČP' => [[['OIC' => RegistrationXmlFixtures::oic(7)]], 'neodpovídá schématu MPSV'];
        yield 'neznámý element' => [[['RodneCislo' => $rc, 'Poznamka' => 'x']], 'neodpovídá schématu MPSV'];
        yield 'nový tvar bez začátku pojištění u další věty' => [
            [['RodneCislo' => $rc, 'PojistnyVztahOd' => '2025-01-01'], ['RodneCislo' => $rc]],
            'tvar od 15. 10. 2026',
        ];
    }

    /** @param list<array<string,string|null>> $employees */
    #[DataProvider('rejectedExports')]
    public function testInvalidExportIsRejectedAsWhole(array $employees, string $reason): void
    {
        $valid = ['RodneCislo' => RegistrationXmlFixtures::birthNumber('1985-03-04', 'male', 3), 'OIC' => RegistrationXmlFixtures::oic(9), 'IdZamestnani' => '2000000000109'];

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage($reason);
        $this->reader->read(RegistrationXmlFixtures::csszExport([$valid, ...$employees]));
    }

    public function testExportWithoutEmployeeListIsRejected(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('Zamestnanci');
        $this->reader->read('<ExportZamestnancu><DatumGenerovani>2026-09-20T10:15:00Z</DatumGenerovani></ExportZamestnancu>');
    }

    public function testNamespacedExportRootIsNotAccepted(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('export zaměstnanců');
        $this->reader->read('<ExportZamestnancu xmlns="urn:example:other"><Zamestnanci/></ExportZamestnancu>');
    }

    public function testDoctypeIsRejectedBeforeParsing(): void
    {
        $xml = "<?xml version=\"1.0\"?>\n<!DOCTYPE REGZEC [<!ENTITY x SYSTEM \"file:///etc/passwd\">]>\n"
            . '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees/></REGZEC>';

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->reader->read($xml);
    }

    /** @return iterable<string,array{string,string}> */
    public static function rejectedFiles(): iterable
    {
        yield 'foreign XML' => ['<?xml version="1.0"?><invoice xmlns="urn:example:invoice"/>', 'REGZEC25 ani PREZEC26'];
        yield 'malformed' => ['<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>', 'není platné XML'];
        yield 'schema violation' => [
            str_replace(' dep="111"', '', RegistrationXmlFixtures::regzecA1()),
            'neodpovídá schématu',
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function testUnreadableFilesAreRejectedWithReason(string $xml, string $reason): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage($reason);
        $this->reader->read($xml);
    }

    /** @return iterable<string,array{string,bool,?string}> */
    public static function activityCodes(): iterable
    {
        yield 'pracovní poměr' => ['1', false, 'employment'];
        yield 'malý rozsah' => ['2', true, 'small_scale_employment'];
        yield 'DPČ' => ['A', false, 'dpc'];
        yield 'DPP' => ['T', false, 'dpp'];
        yield 'DPP ZB' => ['ZB', false, 'dpp'];
        yield 'statutár' => ['S', false, 'statutory_body'];
        yield 'prokurista' => ['P', false, 'statutory_body'];
        yield 'likvidátor' => ['R', false, 'statutory_body'];
        yield 'neznámý' => ['K', false, null];
        yield 'pěstoun' => ['M', false, null];
    }

    #[DataProvider('activityCodes')]
    public function testActivityCodeMapsToRelationType(string $code, bool $smallScale, ?string $expected): void
    {
        $record = new RegistrationRecord(
            documentType: 'REGZEC25',
            position: 1,
            sequence: 1,
            actionCode: 1,
            activityCode: $code,
            smallScale: $smallScale,
        );

        self::assertSame($expected, $record->relationType());
    }
}
