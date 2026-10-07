<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Sickness;

use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessDocumentXmlReader;
use MyInvoice\Service\Payroll\Import\Sickness\SicknessImportRecord;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Čtečka NEMPRI a HZUPN předchozího programu (K3): syntetická podání ve tvaru
 * XSD ČSSZ a starého formátu NEMPRI 2020 od Money S3.
 */
#[Group('unit')]
final class SicknessDocumentXmlReaderTest extends TestCase
{
    private const BIRTH_NUMBER = '7001010126';

    public function testNempri25OfSicknessCarriesNoIncapacityStartButEventMonth(): void
    {
        $result = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri25('NEM', self::BIRTH_NUMBER, ['decision' => 'a1234567']),
        );

        self::assertSame('NEMPRI25', $result['document_type']);
        self::assertSame([], $result['warnings']);
        self::assertCount(1, $result['records']);
        $record = $result['records'][0];
        self::assertSame(SicknessBenefitKind::Nem, $record->kind);
        self::assertSame(SicknessDocumentKind::Nempri, $record->document);
        self::assertNull($record->incapacityFrom, 'NEMPRI nemocenského den vzniku nenese, nesmí se odhadnout.');
        // Rozhodné období končí 31. 8., událost leží v září.
        self::assertSame('2026-09-01', $record->eventMonthStart());
        self::assertSame('2026-09-30', $record->eventMonthEnd());
        self::assertSame('A1234567', $record->decisionNumber, 'Číslo rozhodnutí se normalizuje na velká písmena.');
        self::assertSame(self::BIRTH_NUMBER, $record->birthNumber);
        self::assertSame(112, $record->caseFields['ossz_code']);
        self::assertSame(30000, $record->caseFields['probable_income_czk']);
        self::assertSame(0, $record->caseFields['worked_on_decisive_day']);
        self::assertSame('8', $record->caseFields['daily_working_hours']);
    }

    public function testNempri25OfCareCarriesDatesAndApplication(): void
    {
        $record = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri25('OSE', self::BIRTH_NUMBER, ['decision' => '10278000600075284N']),
        )['records'][0];

        self::assertSame(SicknessBenefitKind::Ose, $record->kind);
        self::assertSame('2026-09-14', $record->incapacityFrom);
        self::assertSame('2026-09-20', $record->incapacityTo);
        self::assertSame(1, $record->caseFields['action_start']);
        self::assertSame(0, $record->caseFields['action_continuation']);
        self::assertSame(1, $record->caseFields['action_end']);
        self::assertSame('ill', $record->caseFields['care_reason']);
        self::assertSame('PL', $record->caseFields['relationship_code']);
        self::assertSame(1, $record->caseFields['shared_household']);
        self::assertSame(0, $record->caseFields['lone_caregiver']);
        self::assertSame([['from' => '2026-09-14', 'to' => '2026-09-20']], $record->caseFields['care_days']);
        self::assertSame('Dítě', $record->caseFields['cared_last_name']);
    }

    public function testNempri25OfPaternityCarriesReasonAndChild(): void
    {
        $record = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri25('OPP', self::BIRTH_NUMBER, ['from' => '2026-07-12']),
        )['records'][0];

        self::assertSame(SicknessBenefitKind::Opp, $record->kind);
        self::assertSame('2026-07-12', $record->incapacityFrom);
        self::assertNull($record->incapacityTo);
        self::assertSame('OTC', $record->caseFields['paternity_reason']);
        self::assertSame('2026-07-12', $record->caseFields['cared_birth_date']);
    }

    public function testHzupnDerivesLastDayOfIncapacityFromReturnToWork(): void
    {
        $result = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::hzupn20(self::BIRTH_NUMBER),
        );

        self::assertSame('HZUPN20', $result['document_type']);
        $record = $result['records'][0];
        self::assertSame(SicknessDocumentKind::Hzupn, $record->document);
        self::assertSame(SicknessBenefitKind::Nem, $record->kind);
        self::assertSame('2026-10-14', $record->incapacityTo, 'Posledním dnem neschopnosti je den před návratem.');
        self::assertSame('2026-10-15', $record->returnedOn);
        self::assertSame('2026-10-16', $record->issuedOn);
        self::assertSame('A1234567', $record->decisionNumber);
        self::assertSame(1, $record->caseFields['returned_to_work']);
        self::assertSame('4', $record->caseFields['hours_worked_last_day']);
        self::assertSame('8', $record->caseFields['shift_hours_last_day']);
        self::assertFalse($record->personReport);
    }

    public function testHzupnOfPersonNotReturnedHasNoEndOfIncapacity(): void
    {
        $record = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::hzupn20(self::BIRTH_NUMBER, ['returned' => 'N']),
        )['records'][0];

        self::assertNull($record->incapacityTo);
        self::assertSame(0, $record->caseFields['returned_to_work']);
    }

    public function testHzupnOfPersonInsuredVoluntarilyIsFlagged(): void
    {
        $record = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::hzupn20(self::BIRTH_NUMBER, ['personReport' => 'A', 'employerReport' => 'N']),
        )['records'][0];

        self::assertTrue($record->personReport);
    }

    public function testOldNempri20IsReadWithWarningAboutMissingSchema(): void
    {
        $result = (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri20(self::BIRTH_NUMBER),
        );

        self::assertSame(SicknessImportRecord::NEMPRI20, $result['document_type']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('2020', $result['warnings'][0]);
        $record = $result['records'][0];
        self::assertSame('103600000000000001', $record->decisionNumber);
        self::assertSame('2026-08-01', $record->eventMonthStart());
        self::assertSame(0, $record->caseFields['worked_on_decisive_day']);
        self::assertSame('ucetni@example.invalid', $record->caseFields['contact_worker_email']);
    }

    public function testFileThatDoesNotMatchTheSchemaIsRefusedWhole(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('neodpovídá schématu ČSSZ NEMPRI25');

        (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri25('NEM', self::BIRTH_NUMBER, ['withActivity' => false]),
        );
    }

    public function testDoctypeIsRefused(): void
    {
        $this->expectException(RegistrationImportFileException::class);
        $this->expectExceptionMessage('DOCTYPE');

        (new SicknessDocumentXmlReader())->read(
            SicknessImportXmlFixtures::nempri25('NEM', self::BIRTH_NUMBER, ['doctype' => '<!DOCTYPE NEMPRI [<!ENTITY x "y">]>']),
        );
    }

    public function testLooksLikeRecognisesOnlyTheseDocuments(): void
    {
        self::assertTrue(SicknessDocumentXmlReader::looksLike(SicknessImportXmlFixtures::nempri25('NEM', self::BIRTH_NUMBER)));
        self::assertTrue(SicknessDocumentXmlReader::looksLike(SicknessImportXmlFixtures::nempri20(self::BIRTH_NUMBER)));
        self::assertTrue(SicknessDocumentXmlReader::looksLike(SicknessImportXmlFixtures::hzupn20(self::BIRTH_NUMBER)));
        self::assertFalse(SicknessDocumentXmlReader::looksLike('<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"/>'));
        self::assertFalse(SicknessDocumentXmlReader::looksLike('<podaniOzuspoj xmlns="http://schemas.cssz.cz/POJ/OZUSPOJ23"/>'));
    }
}
