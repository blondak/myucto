<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use PHPUnit\Framework\TestCase;

final class JmhzReportReaderTest extends TestCase
{
    private JmhzReportReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JmhzReportReader();
    }

    /**
     * Kruh serializér → čtení: co aplikace do hlášení napíše, to import přečte
     * zpátky na stejné hodnoty (celé koruny, nárok na slevu, děti, pracoviště).
     */
    public function testSerializedReportIsReadBackToTheSourceValues(): void
    {
        $person = JmhzReportFixtures::person(['ztp_p_credit' => 4_790, 'social_discount' => 2_600]);
        $xml = JmhzReportFixtures::report([$person], 2026, 2);
        self::assertTrue(JmhzReportReader::isJmhz($xml));

        $file = $this->reader->read($xml);

        self::assertSame('2026-02', $file->period());
        self::assertSame('R', $file->submissionType);
        self::assertFalse($file->lenient);
        self::assertSame(JmhzReportFixtures::guid(1, 0), $file->submissionGuid);
        self::assertCount(1, $file->forms);
        $form = $file->forms[0];
        self::assertSame('bezPriznaku', $form->variant);
        self::assertSame(JmhzReportFixtures::guid(1, 101), $form->formGuid);
        self::assertTrue($form->primary);
        self::assertSame(RegistrationXmlFixtures::oic(7), $form->personIdentifier);
        self::assertSame('200000000000000000101', $form->employmentIdentifier);
        self::assertTrue($form->hasSummary);
        self::assertSame(40_000, $form->incomeTotal);
        self::assertSame(['base' => 40_000, 'computed' => 6_000, 'after_credits' => 2_163, 'bonus' => 0], $form->advance);
        self::assertTrue($form->declarationSigned);
        self::assertSame(['taxpayer' => 2_570, 'ztp-p' => 4_790], $form->credits);
        self::assertSame(1_267, $form->childCredit['monthly']);
        self::assertSame(1_267, $form->childCredit['applied']);
        self::assertFalse($form->childCredit['other_caregiver']);
        self::assertSame([[
            'given_name' => 'Eliška',
            'family_name' => 'Testovací',
            'birth_date' => '2018-05-20',
            'birth_number' => null,
            'ztp_p' => false,
            'order' => '1',
        ]], $form->childCredit['children']);
        self::assertSame(40_000, $form->socialBase);
        self::assertTrue($form->socialDiscount);
        self::assertFalse($form->orchardDiscount);
        self::assertSame(['city' => 'Brno', 'municipality_code' => '582786', 'country_code' => 'CZ'], $form->workplace);
        self::assertFalse($form->apz);
        self::assertFalse($form->functionalBenefits);
        self::assertFalse($form->temporaryAssignment);
        self::assertSame(168_000, $form->workedMillihours);
        self::assertSame(21, $form->workedDays);
        self::assertSame(40_000, $form->taxableIncome);
        self::assertSame(40_000, $form->wage);
        self::assertSame(0, $form->irregularBonuses);
        self::assertSame(23_810, $form->averageHourlyMinor());
        self::assertSame('2026-02-01', $form->insuranceFrom);
    }

    public function testInsuranceStartInsideMonthIsRead(): void
    {
        $xml = JmhzReportFixtures::report([JmhzReportFixtures::person(['insurance_from' => '2026-03-16'])], 2026, 3);

        self::assertSame('2026-03-16', $this->reader->read($xml)->forms[0]->insuranceFrom);
    }

    public function testErrorsOnlyInsideUnreadBlocksAreAcceptedWithWarning(): void
    {
        $xml = preg_replace('#\s*<form:pocetDnu>\d+</form:pocetDnu>#', '', JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2), 1);

        $file = $this->reader->read((string) $xml);

        self::assertTrue($file->lenient);
        self::assertCount(1, $file->warnings);
        self::assertStringContainsString('převzal s varováním', $file->warnings[0]);
        self::assertSame(40_000, $file->forms[0]->wage);
    }

    public function testSchemaErrorInsideReadBlockRejectsTheWholeFile(): void
    {
        $xml = preg_replace('#\s*<form:mzdaZuctovana>\d+</form:mzdaZuctovana>#', '', JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2), 1);

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('neodpovídá schématu');
        $this->reader->read((string) $xml);
    }

    public function testOneLineDocumentWithSchemaErrorCannotBeLocalizedAndIsRejected(): void
    {
        $xml = (string) preg_replace('#\s*<form:pocetDnu>\d+</form:pocetDnu>#', '', JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2), 1);
        $oneLine = (string) preg_replace('/>\s+</', '><', $xml);

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('neodpovídá schématu');
        $this->reader->read($oneLine);
    }

    public function testDoctypeIsRejectedAndNotRecognisedAsReport(): void
    {
        $xml = "<?xml version=\"1.0\"?>\n<!DOCTYPE jmhz [<!ENTITY x SYSTEM \"file:///etc/passwd\">]>\n"
            . '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"/>';
        self::assertFalse(JmhzReportReader::isJmhz($xml));

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->reader->read($xml);
    }

    public function testForeignRootIsNotRecognised(): void
    {
        self::assertFalse(JmhzReportReader::isJmhz('<?xml version="1.0"?><jmhz xmlns="urn:example:other"/>'));
        self::assertFalse(JmhzReportReader::isJmhz('<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"/>'));
    }

    /** Rozbitý soubor s kořenem hlášení patří tomuhle čtení a odmítne se jako neplatné XML. */
    public function testMalformedReportIsRoutedHereAndRejected(): void
    {
        $xml = '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"><hlavicka>';
        self::assertTrue(JmhzReportReader::isJmhz($xml));

        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('není platné XML');
        $this->reader->read($xml);
    }

    /** Useknutý soubor se pozná podle úvodní značky nezávisle na verzi libxml2. */
    public function testTruncatedReportRootIsRecognisedByItsStartTag(): void
    {
        self::assertTrue(JmhzReportReader::isJmhz(
            "<?xml version=\"1.0\"?>\n<j:jmhz xmlns:j=\"http://schemas.cssz.cz/JMHZ/podani/1.0\"><j:hlavicka>",
        ));
        self::assertFalse(JmhzReportReader::isJmhz('<jmhz xmlns="urn:example:other"><hlavicka>'));
        self::assertFalse(JmhzReportReader::isJmhz('<j:jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"><x>'));
        self::assertFalse(JmhzReportReader::isJmhz('<REGZEC xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"><x>'));
    }

    public function testCancellationFormsCarryNoBody(): void
    {
        $file = $this->reader->read(JmhzReportFixtures::componentCancellation(2026, 2, 1, 101, '2026-03-20T08:00:00Z'));

        self::assertSame('O', $file->submissionType);
        self::assertSame('S', $file->forms[0]->formType);
        self::assertFalse($file->forms[0]->hasBody());

        $storno = $this->reader->read(JmhzReportFixtures::submissionCancellation(2026, 2, 1, '2026-03-21T08:00:00Z'));
        self::assertSame('S', $storno->submissionType);
        self::assertSame([], $storno->forms);
    }
}
