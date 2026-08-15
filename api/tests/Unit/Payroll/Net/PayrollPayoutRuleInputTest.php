<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleInput;
use PHPUnit\Framework\TestCase;

/**
 * Tvarová validace výplatního pravidla při ZADÁNÍ.
 *
 * Klíčová vlastnost: co projde sem, musí projít i přes PayoutAllocationRequest
 * nad zmrazeným snapshotem. Kdyby se to rozešlo, uživatel by pravidlo uložil
 * a mzda by spadla až o měsíc později nad revizí, se kterou už nejde nic dělat.
 */
final class PayrollPayoutRuleInputTest extends TestCase
{
    public function testRemainderRuleHasNeitherAmountNorPercentage(): void
    {
        $input = PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
        ]);

        self::assertSame('cash', $input->destinationKind);
        self::assertNull($input->destinationReference);
        self::assertNull($input->amountMinor);
        self::assertNull($input->basisPoints);
        self::assertSame(100, $input->priorityNo);
        self::assertTrue($input->isActive);
    }

    public function testFixedRuleRequiresAmountAndRejectsBasisPoints(): void
    {
        $input = PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'bank',
            'destination_reference' => 'account:42',
            'allocation_kind' => 'fixed',
            'amount_minor' => 150000,
            'priority_no' => 10,
        ]);

        self::assertSame(150000, $input->amountMinor);
        self::assertNull($input->basisPoints);
        self::assertSame(42, $input->bankAccountId());

        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'bank',
            'destination_reference' => 'account:42',
            'allocation_kind' => 'fixed',
            'amount_minor' => 150000,
            'basis_points' => 5000,
        ]);
    }

    public function testFixedRuleWithoutAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'allocation_kind' => 'fixed',
        ]);
    }

    public function testPercentageRuleRejectsAmountAndBoundsBasisPoints(): void
    {
        $input = PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'allocation_kind' => 'percentage',
            'basis_points' => 10000,
        ]);
        self::assertSame(10000, $input->basisPoints);
        self::assertNull($input->amountMinor);

        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'allocation_kind' => 'percentage',
            'basis_points' => 10001,
        ]);
    }

    public function testPercentageRuleWithAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'allocation_kind' => 'percentage',
            'basis_points' => 2500,
            'amount_minor' => 100,
        ]);
    }

    public function testRemainderRuleWithAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'allocation_kind' => 'remainder',
            'amount_minor' => 1,
        ]);
    }

    public function testCashDestinationRefusesAnyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'cash',
            'destination_reference' => 'account:1',
            'allocation_kind' => 'remainder',
        ]);
    }

    public function testBankDestinationRequiresAccountIdReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'bank',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
        ]);
    }

    /**
     * Volný text jako číslo účtu je právě ten neauditovatelný fallback, který
     * MZ-17 zakazuje — materializer umí jen `account:<id>` do zmrazených účtů.
     */
    public function testBankDestinationRefusesFreeTextAccountNumber(): void
    {
        foreach ([
            '000000-0000000000/0000',
            'CZ0000000000000000000000',
            'account:0',
            'account:12x',
            'ucet:12',
        ] as $reference) {
            try {
                PayrollPayoutRuleInput::fromRequest([
                    'destination_kind' => 'bank',
                    'destination_reference' => $reference,
                    'allocation_kind' => 'remainder',
                ]);
                self::fail("Reference {$reference} měla být odmítnuta.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'account:<id>',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testPartnerSettlementReferenceIsChartAccountCode(): void
    {
        $input = PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'partner_settlement',
            'destination_reference' => ' 365.100 ',
            'allocation_kind' => 'remainder',
        ]);
        self::assertSame('365.100', $input->destinationReference);
        self::assertNull($input->bankAccountId());

        $this->expectException(\InvalidArgumentException::class);
        PayrollPayoutRuleInput::fromRequest([
            'destination_kind' => 'partner_settlement',
            'destination_reference' => 'account:7',
            'allocation_kind' => 'remainder',
        ]);
    }

    public function testUnknownDestinationOrAllocationKindIsRejected(): void
    {
        foreach ([
            ['destination_kind' => 'wallet', 'allocation_kind' => 'remainder'],
            ['destination_kind' => 'cash', 'allocation_kind' => 'leftover'],
        ] as $body) {
            try {
                PayrollPayoutRuleInput::fromRequest($body);
                self::fail('Neznámý druh měl být odmítnut.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'nepodporovanou hodnotu',
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * Kontraktová vazba na výpočet: každé zadání, které projde validací, musí
     * jít beze změny složit do PayoutAllocationRequest.
     */
    public function testEveryAcceptedShapeSurvivesAllocationRequest(): void
    {
        $bodies = [
            ['destination_kind' => 'cash', 'allocation_kind' => 'remainder'],
            [
                'destination_kind' => 'bank',
                'destination_reference' => 'account:7',
                'allocation_kind' => 'fixed',
                'amount_minor' => 50000,
            ],
            [
                'destination_kind' => 'partner_settlement',
                'destination_reference' => '365.100',
                'allocation_kind' => 'percentage',
                'basis_points' => 2500,
            ],
        ];
        foreach ($bodies as $index => $body) {
            $input = PayrollPayoutRuleInput::fromRequest($body);
            $request = match ($input->allocationKind) {
                'fixed' => PayoutAllocationRequest::fixed(
                    "unit-{$index}",
                    $input->destinationKind,
                    $input->destinationReference,
                    (int) $input->amountMinor,
                    $input->priorityNo,
                ),
                'percentage' => PayoutAllocationRequest::percentage(
                    "unit-{$index}",
                    $input->destinationKind,
                    $input->destinationReference,
                    (int) $input->basisPoints,
                    $input->priorityNo,
                ),
                default => PayoutAllocationRequest::remainder(
                    "unit-{$index}",
                    $input->destinationKind,
                    $input->destinationReference,
                    $input->priorityNo,
                ),
            };
            self::assertSame($input->destinationKind, $request->destinationKind);
            self::assertSame(
                $input->destinationReference,
                $request->destinationReference,
            );
        }
    }

    public function testGeneratedReferenceIsUniqueAndFitsColumn(): void
    {
        $input = PayrollPayoutRuleInput::remainder('cash', null);
        $first = $input->generateReference();
        $second = $input->generateReference();

        self::assertNotSame($first, $second);
        self::assertLessThanOrEqual(96, mb_strlen($first, 'UTF-8'));
        self::assertStringStartsWith('payout-cash-', $first);
    }
}
