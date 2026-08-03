<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use PHPUnit\Framework\TestCase;

final class PayrollPersonProfileValidatorTest extends TestCase
{
    private PayrollPersonProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayrollPersonProfileValidator(new IbanValidator());
    }

    public function testNormalizesCompleteSyntheticProfile(): void
    {
        $data = $this->validator->validate([
            'profile_status' => 'setup',
            'payout_method' => 'mixed',
            'cash_allocation_basis_points' => 2500,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => '  Jana Testovací  ',
                'birth_surname' => 'Příkladová',
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]],
            'addresses' => [[
                'address_type' => 'residence',
                'street_line' => 'Testovací 1',
                'city' => 'Praha',
                'postal_code' => '100 00',
                'country_code' => 'cz',
                'effective_from' => '2026-01-01',
            ]],
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'jana.testovaci@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => '123456789',
            ]],
            'accounts' => [[
                'label' => 'Testovací účet',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 7500,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ]],
        ]);

        self::assertSame('Jana Testovací', $data['identity_history'][0]['full_name']);
        self::assertSame('CZ', $data['addresses'][0]['country_code']);
        self::assertSame(2500, $data['cash_allocation_basis_points']);
        self::assertSame('123456789', $data['identifiers'][0]['value']);
    }

    public function testRejectsOverlappingIdentityAndAddressIntervals(): void
    {
        foreach ([
            [
                'identity_history' => [
                    [
                        'full_name' => 'Jana První',
                        'effective_from' => '2026-01-01',
                        'effective_to' => '2026-06-30',
                    ],
                    [
                        'full_name' => 'Jana Druhá',
                        'effective_from' => '2026-06-30',
                    ],
                ],
            ],
            [
                'addresses' => [
                    [
                        'address_type' => 'residence',
                        'street_line' => 'První 1',
                        'city' => 'Praha',
                        'postal_code' => '100 00',
                        'country_code' => 'CZ',
                        'effective_from' => '2026-01-01',
                    ],
                    [
                        'address_type' => 'residence',
                        'street_line' => 'Druhá 2',
                        'city' => 'Praha',
                        'postal_code' => '100 00',
                        'country_code' => 'CZ',
                        'effective_from' => '2026-07-01',
                    ],
                ],
            ],
        ] as $payload) {
            try {
                $this->validator->validate($this->payload($payload));
                self::fail('Překryv účinnosti musí být odmítnut.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('překrývají', $e->getMessage());
            }
        }
    }

    public function testExistingSecretsCanBePreservedButNewOnesRequirePlaintext(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identifiers' => [[
                'id' => 10,
                'identifier_type' => 'birth_number',
            ]],
            'accounts' => [[
                'id' => 20,
                'label' => 'Zachovaný účet',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));

        self::assertNull($normalized['identifiers'][0]['value']);
        self::assertNull($normalized['accounts'][0]['bank_account']);

        foreach ([
            ['identifiers' => [['identifier_type' => 'birth_number']]],
            ['accounts' => [[
                'label' => 'Chybějící účet',
                'effective_from' => '2026-01-01',
            ]]],
        ] as $payload) {
            $this->expectInvalid($payload);
        }
    }

    public function testRejectsInvalidContactsAndDuplicatePrimaryContact(): void
    {
        $this->expectInvalid([
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'neni-email',
            ]],
        ]);
        $this->expectInvalid([
            'contacts' => [
                [
                    'contact_type' => 'phone',
                    'value' => '+420 111 222 333',
                    'is_primary' => true,
                ],
                [
                    'contact_type' => 'phone',
                    'value' => '+420 444 555 666',
                    'is_primary' => true,
                ],
            ],
        ]);
    }

    public function testRejectsMaskedPlaintextAndAcceptsMaskedReadModelForExistingRows(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'birth_surname_masked' => 'P••••••••',
                'effective_from' => '2026-01-01',
            ]],
            'addresses' => [[
                'id' => 2,
                'address_type' => 'residence',
                'address_masked' => '••••••, P••••, ••• ••, CZ',
                'effective_from' => '2026-01-01',
            ]],
            'contacts' => [[
                'id' => 3,
                'contact_type' => 'email',
                'value_masked' => 'j•••@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
        ]));

        self::assertFalse($normalized['identity_history'][0]['birth_surname_present']);
        self::assertFalse($normalized['addresses'][0]['address_present']);
        self::assertNull($normalized['contacts'][0]['value']);

        foreach ([
            ['identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'birth_surname' => 'P••••••••',
                'effective_from' => '2026-01-01',
            ]]],
            ['addresses' => [[
                'id' => 2,
                'address_type' => 'residence',
                'street_line' => '••••••',
                'city' => 'Praha',
                'postal_code' => '100 00',
                'country_code' => 'CZ',
                'effective_from' => '2026-01-01',
            ]]],
            ['contacts' => [[
                'id' => 3,
                'contact_type' => 'email',
                'value' => 'j•••@example.invalid',
            ]]],
        ] as $payload) {
            $this->expectInvalid($payload);
        }
    }

    public function testNullSensitiveValuePreservesExistingBirthSurname(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identity_history' => [[
                'id' => 1,
                'full_name' => 'Jana Testovací',
                'birth_surname' => null,
                'effective_from' => '2026-01-01',
            ]],
        ]));

        self::assertFalse($normalized['identity_history'][0]['birth_surname_present']);
        self::assertNull($normalized['identity_history'][0]['birth_surname']);
    }

    public function testNormalizesTypedIdentifiersAndRejectsArbitraryValues(): void
    {
        $normalized = $this->validator->validate($this->payload([
            'identifiers' => [
                ['identifier_type' => 'birth_number', 'value' => '000101 / 0009'],
                ['identifier_type' => 'ecp', 'value' => '123 456 789'],
                ['identifier_type' => 'vcp', 'value' => '654 321 987'],
                ['identifier_type' => 'foreign_tax_identifier', 'value' => 'de: ab 12 34'],
            ],
        ]));

        self::assertSame('000101/0009', $normalized['identifiers'][0]['value']);
        self::assertSame('123456789', $normalized['identifiers'][1]['value']);
        self::assertSame('654321987', $normalized['identifiers'][2]['value']);
        self::assertSame('DE:AB1234', $normalized['identifiers'][3]['value']);

        foreach ([
            ['identifier_type' => 'birth_number', 'value' => '1234567890'],
            ['identifier_type' => 'birth_number', 'value' => '0041010002'],
            ['identifier_type' => 'ecp', 'value' => '12345678'],
            ['identifier_type' => 'vcp', 'value' => '1234567890'],
            ['identifier_type' => 'foreign_tax_identifier', 'value' => 'bez-zeme'],
        ] as $identifier) {
            $this->expectInvalid(['identifiers' => [$identifier]]);
        }
    }

    public function testValidatesCzechAndIbanBankAccounts(): void
    {
        $czech = $this->validator->validate($this->payload([
            'accounts' => [[
                'label' => 'Český účet',
                'bank_account' => '1000000005 / 0100',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));
        self::assertSame('1000000005/0100', $czech['accounts'][0]['bank_account']);

        $iban = $this->validator->validate($this->payload([
            'accounts' => [[
                'label' => 'IBAN',
                'bank_account' => 'CZ65 0800 0000 1920 0014 5399',
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]],
        ]));
        self::assertSame('CZ6508000000192000145399', $iban['accounts'][0]['bank_account']);

        foreach (['1000000006/0100', 'CZ6508000000192000145398'] as $account) {
            $this->expectInvalid(['accounts' => [[
                'label' => 'Neplatný účet',
                'bank_account' => $account,
                'allocation_basis_points' => 0,
                'effective_from' => '2026-01-01',
                'is_active' => false,
            ]]]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function expectInvalid(array $payload): void
    {
        try {
            $this->validator->validate($this->payload($payload));
            self::fail('Neplatný profil musí být odmítnut.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides): array
    {
        return array_replace([
            'profile_status' => 'setup',
            'payout_method' => 'cash',
            'cash_allocation_basis_points' => 10000,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
        ], $overrides);
    }
}
