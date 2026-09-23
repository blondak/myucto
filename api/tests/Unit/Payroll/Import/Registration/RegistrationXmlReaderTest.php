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
            ['OIC' => RegistrationXmlFixtures::oic(8), 'Prijmeni' => 'Zkušební', 'Jmeno' => 'Petr', 'KodDruhuCinnosti' => 't'],
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

        $second = $read['records'][1];
        self::assertNull($second->birthNumber);
        self::assertSame('T', $second->activityCode);
        self::assertSame('dpp', $second->relationType());

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

    /** @return iterable<string,array{list<array<string,string|null>>,string}> */
    public static function rejectedExports(): iterable
    {
        yield 'krátké OIČ' => [[['OIC' => '123456789']], 'neplatný OIČ'];
        yield 'ZMR mimo A/N' => [[['OIC' => RegistrationXmlFixtures::oic(7), 'ZMR' => 'X']], 'ZMR'];
        yield 'VS s písmeny' => [[['OIC' => RegistrationXmlFixtures::oic(7), 'VariabilniSymbol' => '12AB']], 'variabilní symbol'];
        yield 'bez identifikátoru' => [[['Jmeno' => 'Jana', 'Prijmeni' => 'Testovací']], 'rodné číslo, OIČ ani ID PPV'];
    }

    /** @param list<array<string,string|null>> $employees */
    #[DataProvider('rejectedExports')]
    public function testInvalidExportIsRejectedAsWhole(array $employees, string $reason): void
    {
        $valid = ['OIC' => RegistrationXmlFixtures::oic(9), 'IdZamestnani' => '2000000000109'];

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
        yield 'neznámý' => ['K', false, null];
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
