<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

/**
 * „Právě jeden aktivní zbytek" musí být záruka DATABÁZE, ne jen aplikace.
 *
 * PayoutAllocationService bez zbytkového pravidla neví, kam poslat zbytek
 * výplaty, a se dvěma není rozdělení jednoznačné. Dokud to hlídala jen
 * aplikace, obešel by kontrolu import i ruční SQL a chyba by se projevila až
 * nad zmrazenou revizí. Test drží mechaniku migrace 1378 na místě.
 */
final class PayrollPayoutRuleWriteApiMigrationTest extends TestCase
{
    private function sql(): string
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1378_payroll_payout_rule_write_api.sql';
        self::assertFileExists($path);
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }

    public function testGuardColumnIsNullOutsideActiveRemainderRules(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS remainder_guard',
            $sql,
        );
        self::assertStringContainsString('GENERATED ALWAYS AS', $sql);
        self::assertStringContainsString(
            "WHEN allocation_kind = 'remainder' AND is_active = 1 THEN employee_id",
            $sql,
        );
        // ELSE NULL je celý trik: NULL hodnoty v unikátním indexu nekolidují,
        // takže neaktivní a nezbytková pravidla omezení vůbec nevidí.
        self::assertStringContainsString('ELSE NULL', $sql);
    }

    public function testUniqueKeyIsTenantScopedAndIdempotent(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'ADD UNIQUE KEY IF NOT EXISTS uq_payroll_payout_rule_single_remainder',
            $sql,
        );
        self::assertStringContainsString('(supplier_id, remainder_guard)', $sql);
    }

    public function testExistingDuplicatesAreDeactivatedNotDeleted(): void
    {
        $sql = $this->sql();

        // Řádek se nesmí mazat — odkazují na něj zmrazené alokace
        // (payroll_payout_allocations.payout_rule_id) cizím klíčem.
        self::assertStringNotContainsString('DELETE FROM payroll_payout_rules', $sql);
        self::assertStringContainsString('SET rule_row.is_active = 0', $sql);
    }
}
