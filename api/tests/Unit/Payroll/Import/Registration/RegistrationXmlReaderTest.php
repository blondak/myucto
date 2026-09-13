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
