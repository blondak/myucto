<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Pohoda;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use MyInvoice\Service\Payroll\Import\Pohoda\PohodaPersonnelOicImportService;
use MyInvoice\Service\Payroll\Import\Pohoda\PohodaPersonnelReader;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\TestCase;

final class PohodaPersonnelReaderTest extends TestCase
{
    private PohodaPersonnelReader $reader;

    protected function setUp(): void
    {
        $this->reader = new PohodaPersonnelReader(new AttendanceWorkbookReader());
    }

    public function testFindsHeaderBelowTitleBlockAndReadsSpreadColumns(): void
    {
        $birth = AttendanceFixture::birthNumber('1990-05-01', 'female', 1);
        $oic = RegistrationXmlFixtures::oic(1);
        $read = $this->reader->read([PohodaPersonnelFixture::file([
            ['last' => 'Testovací', 'first' => 'Jana', 'birth' => $birth, 'personal' => 'Z9101', 'oic' => $oic],
            ['last' => 'Zkušební', 'first' => 'Petr', 'birth' => '850312/0013', 'personal' => 'Z9102', 'oic' => null],
        ])]);

        self::assertCount(1, $read['files']);
        self::assertNull($read['files'][0]['error']);
        self::assertSame('Personalistika', $read['files'][0]['sheet']);
        self::assertSame(2, $read['files'][0]['row_count']);
        self::assertSame(PohodaPersonnelFixture::COMPANY_ICO, $read['files'][0]['company_ico']);

        self::assertCount(2, $read['rows']);
        $jana = $read['rows'][0];
        self::assertSame(6, $jana['row']);
        self::assertSame('Testovací', $jana['last_name']);
        self::assertSame('Jana', $jana['first_name']);
        self::assertSame($birth, $jana['birth_number']);
        self::assertSame('Z9101', $jana['personal_number']);
        self::assertSame($oic, $jana['oic']);
        self::assertSame('', $read['rows'][1]['oic']);
        self::assertSame(7, $read['rows'][1]['row']);
    }

    public function testNumericOicCellKeepsLeadingZeros(): void
    {
        $read = $this->reader->read([PohodaPersonnelFixture::file([
            ['last' => 'Pokusná', 'first' => 'Eva', 'birth' => '905501/0005', 'personal' => 'Z1', 'oic' => 12345678],
        ])]);

        self::assertSame('0012345678', $read['rows'][0]['oic']);
    }

    public function testReadsCsvWithTheSameHeaders(): void
    {
        $csv = "Příjmení;Jméno;Rodné číslo;Osobní číslo;OIC;Vzdělání (ISPV)\r\n"
            . "Testovací;Jana;905501/0005;Z9101;" . RegistrationXmlFixtures::oic(2) . ";\r\n"
            . ";;;;;\r\n";
        $read = $this->reader->read([AttendanceFixture::file('personalistika.csv', $csv)]);

        self::assertNull($read['files'][0]['error']);
        self::assertNull($read['files'][0]['company_ico']);
        self::assertCount(1, $read['rows']);
        self::assertSame(2, $read['rows'][0]['row']);
        self::assertSame(RegistrationXmlFixtures::oic(2), $read['rows'][0]['oic']);
    }

    public function testFileWithoutOicColumnIsReportedPerFile(): void
    {
        $content = PohodaPersonnelFixture::workbook([
            ['last' => 'Testovací', 'first' => 'Jana', 'birth' => '905501/0005', 'personal' => 'Z1', 'oic' => null],
        ], false);
        $read = $this->reader->read([AttendanceFixture::file('bez-oic.xlsx', $content)]);

        self::assertSame([], $read['rows']);
        self::assertStringContainsString('„OIC“', (string) $read['files'][0]['error']);
    }

    public function testHeaderNormalizationIgnoresDiacriticsCaseAndPunctuation(): void
    {
        self::assertSame('oic', PohodaPersonnelReader::normalizeHeader('  OIČ: '));
        self::assertSame('rodne cislo', PohodaPersonnelReader::normalizeHeader("RODNÉ\u{00A0}ČÍSLO"));
        self::assertSame('osobni cislo', PohodaPersonnelReader::normalizeHeader('Osobní číslo*'));
    }

    public function testOicValidationIsTheSharedRegistrationRule(): void
    {
        $valid = RegistrationXmlFixtures::oic(3);
        self::assertSame($valid, PayrollRegistrationIdentityService::oic($valid));
        self::assertSame($valid, PayrollRegistrationIdentityService::oic(substr($valid, 0, 5) . ' ' . substr($valid, 5)));

        $wrongCheck = substr($valid, 0, 9) . (((int) $valid[9] + 1) % 10);
        foreach ([$wrongCheck, substr($valid, 0, 9), 'A' . substr($valid, 1)] as $invalid) {
            try {
                PayrollRegistrationIdentityService::oic($invalid);
                self::fail("OIČ {$invalid} nemělo projít.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('OIČ', $e->getMessage());
            }
        }
    }

    public function testChoosesEmploymentValidTodayThenLatest(): void
    {
        $employment = static fn (int $id, string $status, ?string $start, ?string $end, bool $primary = false): array => [
            'id' => $id, 'code' => "V{$id}", 'status' => $status, 'is_primary' => $primary,
            'start_date' => $start, 'end_date' => $end,
        ];
        $today = '2026-09-14';

        $chosen = PohodaPersonnelOicImportService::chooseEmployment([
            $employment(1, 'ended', '2019-01-01', '2020-12-31'),
            $employment(2, 'active', '2021-01-01', null, true),
            $employment(3, 'active', '2024-01-01', null),
            $employment(4, 'planned', '2026-12-01', null),
        ], $today);
        self::assertSame(2, $chosen['id'] ?? null, 'Platný hlavní vztah má přednost.');

        $chosen = PohodaPersonnelOicImportService::chooseEmployment([
            $employment(1, 'ended', '2019-01-01', '2020-12-31'),
            $employment(5, 'ended', '2022-01-01', '2023-06-30'),
            $employment(6, 'archived', '2025-01-01', null),
            $employment(7, 'no_show', '2025-06-01', null),
        ], $today);
        self::assertSame(5, $chosen['id'] ?? null, 'Bez platného vztahu poslední podle nástupu, archiv a nenastoupení se vynechají.');

        self::assertNull(PohodaPersonnelOicImportService::chooseEmployment([
            $employment(8, 'planned', null, null),
            $employment(9, 'archived', '2020-01-01', null),
        ], $today));
    }
}
