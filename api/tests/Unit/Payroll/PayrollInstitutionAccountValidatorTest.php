<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\PayrollInstitutionAccountValidator;
use PHPUnit\Framework\TestCase;

final class PayrollInstitutionAccountValidatorTest extends TestCase
{
    private PayrollInstitutionAccountValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayrollInstitutionAccountValidator(
            new IbanValidator(),
            new CzechBankAccountValidator(),
        );
    }

    public function testCreateNormalizesSyntheticCzechAccountAndMetadata(): void
    {
        $result = $this->validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => ' test-111 ',
            'institution_name' => ' Syntetická zdravotní pojišťovna ',
            'bank_account' => ' 1000000005 / 0100 ',
            'currency_code' => 'czk',
            'variable_symbol' => '001234',
            'specific_symbol' => '',
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-DOCUMENT-001',
            'verified_on' => '2026-01-01',
        ]);

        self::assertSame('TEST-111', $result['institution_code']);
        self::assertSame('1000000005/0100', $result['bank_account']);
        self::assertSame('CZK', $result['currency_code']);
        self::assertSame('001234', $result['variable_symbol']);
        self::assertNull($result['specific_symbol']);
    }

    public function testInvalidCzechAccountAndReversedIntervalAreRejected(): void
    {
        $payload = $this->payload();
        $payload['bank_account'] = '1000000006/0100';
        try {
            $this->validator->validateCreate($payload);
            self::fail('Účet s chybnou kontrolní číslicí nesmí projít.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('modulo 11', $e->getMessage());
        }

        $payload = $this->payload();
        $payload['valid_to'] = '2025-12-31';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Konec platnosti');
        $this->validator->validateCreate($payload);
    }

    public function testUpdateCannotRewriteBankOrInstitutionIdentity(): void
    {
        $payload = $this->updatePayload();
        $payload['bank_account'] = '1000000005/0100';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('historické');
        $this->validator->validateUpdate($payload);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'institution_type' => 'tax_office',
            'institution_code' => 'FU-TEST',
            'institution_name' => 'Syntetický finanční úřad',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-DOCUMENT-001',
            'verified_on' => '2026-01-01',
        ];
    }

    /** @return array<string,mixed> */
    private function updatePayload(): array
    {
        return [
            'institution_name' => 'Syntetický finanční úřad',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_to' => '2026-12-31',
            'source_kind' => 'user_verified',
            'source_reference' => 'SYNTHETIC-CHECK-002',
            'verified_on' => '2026-02-01',
        ];
    }
}
