<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleWarnings;
use PHPUnit\Framework\TestCase;

/**
 * Varování jsou čistá funkce STAVU pravidla, ne události zápisu.
 *
 * Díky tomu je dostane i prosté načtení karty — kdyby vznikala jen ve
 * validátoru při zápisu, viděl by je jen ten, kdo pravidlo právě uložil, a
 * pravidlo založené minulý měsíc by tiše čekalo, až spadne materializace.
 */
final class PayrollPayoutRuleWarningsTest extends TestCase
{
    public function testUnverifiedBankDestinationIsReported(): void
    {
        $warnings = PayrollPayoutRuleWarnings::forRules([
            $this->rule([
                'id' => 7,
                'destination_kind' => 'bank',
                'destination_reference' => 'account:42',
                'destination_verified' => false,
            ]),
        ]);

        self::assertCount(1, $warnings);
        self::assertSame(
            PayrollPayoutRuleWarnings::UNVERIFIED_DESTINATION,
            $warnings[0]['code'],
        );
        self::assertSame(7, $warnings[0]['rule_id']);
        self::assertSame(42, $warnings[0]['account_id']);
        self::assertStringContainsString(
            'není ověřený',
            $warnings[0]['message'],
        );
    }

    public function testVerifiedBankDestinationIsSilent(): void
    {
        self::assertSame([], PayrollPayoutRuleWarnings::forRules([
            $this->rule([
                'destination_kind' => 'bank',
                'destination_reference' => 'account:42',
                'destination_verified' => true,
            ]),
        ]));
    }

    /**
     * U hotovosti a zápočtu je `destination_verified` NULL, protože ověření
     * tam nedává smysl — `false` by se četlo jako vada a hlásilo by se navždy.
     */
    public function testCashAndPartnerSettlementNeverWarn(): void
    {
        self::assertSame([], PayrollPayoutRuleWarnings::forRules([
            $this->rule([
                'destination_kind' => 'cash',
                'destination_reference' => null,
                'destination_verified' => null,
            ]),
            $this->rule([
                'destination_kind' => 'partner_settlement',
                'destination_reference' => '365.100',
                'destination_verified' => null,
            ]),
        ]));
    }

    /** Neaktivní pravidlo do výplaty nevstupuje, takže ho neověřený účet nepálí. */
    public function testInactiveRuleIsNotReported(): void
    {
        self::assertSame([], PayrollPayoutRuleWarnings::forRules([
            $this->rule([
                'destination_kind' => 'bank',
                'destination_reference' => 'account:42',
                'destination_verified' => false,
                'is_active' => false,
            ]),
        ]));
    }

    public function testEachOffendingRuleGetsItsOwnWarning(): void
    {
        $warnings = PayrollPayoutRuleWarnings::forRules([
            $this->rule([
                'id' => 1,
                'destination_kind' => 'bank',
                'destination_reference' => 'account:11',
                'destination_verified' => false,
            ]),
            $this->rule([
                'id' => 2,
                'destination_kind' => 'cash',
                'destination_reference' => null,
                'destination_verified' => null,
            ]),
            $this->rule([
                'id' => 3,
                'destination_kind' => 'bank',
                'destination_reference' => 'account:12',
                'destination_verified' => false,
            ]),
        ]);

        self::assertSame([1, 3], array_column($warnings, 'rule_id'));
        self::assertSame([11, 12], array_column($warnings, 'account_id'));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function rule(array $overrides): array
    {
        return [
            'id' => 1,
            'destination_kind' => 'cash',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
            'is_active' => true,
            'destination_verified' => null,
            ...$overrides,
        ];
    }
}
