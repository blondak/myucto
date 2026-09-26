<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnectionResolver;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessPayloadFactory;
use PHPUnit\Framework\TestCase;

/**
 * Mapování případu dávky na obsah NEMPRI: způsob výplaty mzdy, skutečný
 * den nástupu a žádost o dávku. Všechna data jsou syntetická.
 */
final class NempriPaymentAndPayloadTest extends TestCase
{
    public function testCzechAccountWithPrefixIsSplit(): void
    {
        $connection = (new NempriPaymentConnectionResolver())->resolve('bank', '19-1000000005/0100', null);

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_CZ, $connection->kind);
        self::assertSame('19', $connection->accountPrefix);
        self::assertSame('1000000005', $connection->accountNumber);
        self::assertSame('0100', $connection->bankCode);
    }

    public function testCzechIbanIsConvertedToNationalAccount(): void
    {
        $connection = (new NempriPaymentConnectionResolver())
            ->resolve('mixed', 'CZ04 0100 0000 1910 0000 0005', null);

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_CZ, $connection->kind);
        self::assertSame('19', $connection->accountPrefix);
        self::assertSame('1000000005', $connection->accountNumber);
        self::assertSame('0100', $connection->bankCode);
    }

    public function testForeignIbanGoesToForeignAccount(): void
    {
        $connection = (new NempriPaymentConnectionResolver())
            ->resolve('bank', 'DE89 3704 0044 0532 0130 00', null);

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_FOREIGN, $connection->kind);
        self::assertSame('DE', $connection->countryCode);
        self::assertSame('DE89370400440532013000', $connection->iban);
    }

    public function testCashPayoutUsesResidenceAddress(): void
    {
        $connection = (new NempriPaymentConnectionResolver())->resolve('cash', null, [
            'street_line' => 'Zkušební 123/4a',
            'city' => 'Testov',
            'postal_code' => '110 00',
            'country_code' => 'CZ',
        ]);

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ADDRESS, $connection->kind);
        self::assertSame('Zkušební', $connection->street);
        self::assertSame('123', $connection->houseNumber);
        self::assertSame('4a', $connection->orientationNumber);
        self::assertSame('11000', $connection->postalCode);
    }

    public function testPartnerSettlementIsNotInvented(): void
    {
        self::assertNull(
            (new NempriPaymentConnectionResolver())->resolve('partner_settlement', null, null),
        );
    }

    public function testMissingAccountStopsWithReason(): void
    {
        try {
            (new NempriPaymentConnectionResolver())->resolve('bank', null, null);
            self::fail('Bez účtu se způsob výplaty mzdy nesmí tiše vynechat.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_missing', $exception->validationCode);
        }
    }

    /**
     * `zamestnanOd` je den, kdy zaměstnání skutečně vzniklo. Nastoupil-li
     * zaměstnanec jindy, než sjednala smlouva, věta dřív nesla sjednaný den.
     */
    public function testEmploymentFromIsTheActualStartDate(): void
    {
        $payload = (new SicknessPayloadFactory())->nempri(
            $this->row(),
            SicknessBenefitKind::Nem,
            [
                'start_date' => '2026-03-01',
                'actual_start_date' => '2026-03-04',
                'end_date' => null,
                'employer_business_id' => '12345678',
                'employer_name' => 'Testovací zaměstnavatel s.r.o.',
                'employer_variable_symbol' => '1234567890',
                'activity_code' => '1',
            ],
            [
                'identity' => ['first_name' => 'Jan', 'last_name' => 'Testovací'],
                'identifiers' => ['birth_number' => '8001010008', 'ecp' => null],
            ],
            '1.0',
            'MyUcto',
            '1.0',
        );

        self::assertSame('2026-03-04', $payload->employmentFrom);
        self::assertSame('Mzdová Účetní', $payload->contactWorkerName);
    }

    /**
     * Hranice žádosti se berou z případu, pokud je účetní nepřepsala — žádá-li
     * zaměstnanec o dávku za celé trvání události, jsou to tytéž dny.
     */
    public function testApplicationPeriodDefaultsToCasePeriod(): void
    {
        $application = (new SicknessPayloadFactory())->application(
            [...$this->row(), 'incapacity_from' => '2026-09-07', 'incapacity_to' => '2026-09-11', 'action_start' => 1, 'action_end' => 1],
            null,
        );

        self::assertSame('2026-09-07', $application->fromDate);
        self::assertSame('2026-09-11', $application->toDate);
        self::assertTrue($application->actionEnd);
    }

    /**
     * Zásady NEMPRI: prohlášení, které zaměstnanec v žádosti nevyplnil,
     * zaměstnavatel uvede jako „NE“ — žádost kvůli němu nezadrží.
     */
    public function testUndeclaredCareStatementsAreSentAsNo(): void
    {
        $factory = new SicknessPayloadFactory();

        $care = $factory->application($this->row(), null, SicknessBenefitKind::Ose);
        self::assertFalse($care->sharedHousehold);
        self::assertFalse($care->loneCaregiver);
        self::assertFalse($care->caredPersonally);

        $paternity = $factory->application($this->row(), null, SicknessBenefitKind::Opp);
        self::assertNull($paternity->sharedHousehold);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return [
            'ossz_code' => 115,
            'correction' => 0,
            'decision_number' => 'A1234567',
            'foreign_case' => 0,
            'worked_on_decisive_day' => 0,
            'hours_worked' => null,
            'daily_working_hours' => '8.00',
            'small_scope_income_minor' => null,
            'receives_pension' => 0,
            'pension_kind' => null,
            'is_student' => 0,
            'within_school_holidays' => null,
            'first_employment_free_time' => 0,
            'unpaid_leave' => 0,
            'unpaid_leave_from' => null,
            'unpaid_leave_to' => null,
            'starts_maternity' => null,
            'child_birth_date' => null,
            'transferred_other_work' => 0,
            'transferred_on' => null,
            'enforcement' => 0,
            'insolvency' => 0,
            'additional_note' => null,
            'contact_worker_name' => 'Mzdová Účetní',
            'work_days' => [],
        ];
    }
}
