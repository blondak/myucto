<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla zapnutí mezd převodem bez databáze: začátek vedení mezd za posledními
 * převzatými mzdami, poznání firmy, která mzdy už nevede, a zprávy do protokolu.
 */
final class PayrollMigrationModuleSetupTest extends TestCase
{
    public function testStartIsMonthAfterLastPayroll(): void
    {
        self::assertSame('2026-09', PayrollMigrationModuleSetup::startAfter('2026-08'));
        self::assertSame('2027-01', PayrollMigrationModuleSetup::startAfter('2026-12'));
        self::assertSame('2025-03', PayrollMigrationModuleSetup::startAfter('2025-02-28'));
    }

    public function testPayrollEndedMoreThanYearBeforeDataEnd(): void
    {
        self::assertFalse(PayrollMigrationModuleSetup::ended('2026-08', '2026-12'));
        self::assertFalse(PayrollMigrationModuleSetup::ended('2025-12', '2026-12'));
        self::assertTrue(PayrollMigrationModuleSetup::ended('2025-11', '2026-12'));
        self::assertTrue(PayrollMigrationModuleSetup::ended('2022-09', '2026-08'));
        self::assertFalse(PayrollMigrationModuleSetup::ended('2022-09', null), 'Bez konce dat se konec mezd neposuzuje.');
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollMigrationModuleSetup::startAfter('2026-13');
    }

    public function testReportListsWhatWasDoneAndWhatToComplete(): void
    {
        $protocol = new ImportProtocol('import');
        PayrollMigrationModuleSetup::report($protocol, 'payroll', [
            'outcome' => PayrollMigrationModuleSetup::OUTCOME_READY,
            'enabled_now' => true,
            'office_created' => true,
            'start_period' => '2026-09',
            'start_set' => true,
            'start_unsupported' => null,
            'last_period' => '2026-08',
            'todo' => [PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_SYMBOL, PayrollMigrationModuleSetup::TODO_INSTITUTION_ACCOUNTS],
        ], 'Money S3');
        $step = $protocol->toArray()['steps'][0];
        self::assertSame(['payroll_module_enabled', 'payroll_setup_incomplete'], array_column($step['messages'], 'code'));
        self::assertStringContainsString('8/2026', $step['messages'][0]['text']);
        self::assertStringContainsString('9/2026', $step['messages'][0]['text']);
        self::assertStringContainsString('ČSSZ', $step['messages'][1]['text']);
        self::assertSame('warning', $step['status']);
    }

    public function testReportNamesIdentifiersTakenFromCompanySettings(): void
    {
        $protocol = new ImportProtocol('import');
        PayrollMigrationModuleSetup::report($protocol, 'payroll', [
            'outcome' => PayrollMigrationModuleSetup::OUTCOME_READY,
            'enabled_now' => false,
            'office_created' => false,
            'start_period' => '2026-09',
            'start_set' => false,
            'start_unsupported' => null,
            'last_period' => '2026-08',
            'carried' => ['cssz_vsdp' => '0012345678', 'cssz_ossz_code' => '301'],
            'todo' => [PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_REGISTRATION],
        ], 'PREMIER');
        $messages = $protocol->toArray()['steps'][0]['messages'];
        self::assertSame(['payroll_module_enabled', 'payroll_setup_incomplete'], array_column($messages, 'code'));
        self::assertStringContainsString('převzal z Nastavení firmy variabilní symbol ČSSZ 0012345678 k mzdové účtárně a kód OSSZ 301', $messages[0]['text']);
        self::assertStringContainsString('registrace mzdové účtárny', $messages[1]['text']);
        self::assertStringNotContainsString('variabilní symbol plátce pojistného', $messages[1]['text']);
    }

    public function testNothingDoneNothingReported(): void
    {
        $protocol = new ImportProtocol('import');
        PayrollMigrationModuleSetup::report($protocol, 'payroll', [
            'outcome' => PayrollMigrationModuleSetup::OUTCOME_READY,
            'enabled_now' => false,
            'office_created' => false,
            'start_period' => '2026-02',
            'start_set' => false,
            'start_unsupported' => null,
            'last_period' => '2026-08',
            'todo' => [],
        ], 'PREMIER');
        self::assertSame([], $protocol->toArray()['steps']);
    }

    public function testEndedPayrollIsReportedAsInfo(): void
    {
        $protocol = new ImportProtocol('import');
        PayrollMigrationModuleSetup::report($protocol, 'payroll', [
            'outcome' => PayrollMigrationModuleSetup::OUTCOME_ENDED,
            'enabled_now' => false,
            'office_created' => false,
            'start_period' => null,
            'start_set' => false,
            'start_unsupported' => null,
            'last_period' => '2022-09',
            'todo' => [],
        ], 'Money S3');
        $step = $protocol->toArray()['steps'][0];
        self::assertSame('payroll_ended', $step['messages'][0]['code']);
        self::assertSame('running', $step['status']);
    }
}
