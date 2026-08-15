<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator;
use PHPUnit\Framework\TestCase;

final class PayrollEmployerSettingsValidatorTest extends TestCase
{
    public function testAcceptsInsurerFromTheCodebook(): void
    {
        $result = $this->validator()->validate(1, $this->input('205'));

        self::assertSame('205', $result['default_health_insurer_code']);
    }

    public function testEmptyInsurerStaysUnset(): void
    {
        self::assertNull($this->validator()->validate(1, $this->input(''))['default_health_insurer_code']);
        self::assertNull($this->validator()->validate(1, $this->input(null))['default_health_insurer_code']);
    }

    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kód zdravotní pojišťovny 999 neexistuje.');
        $this->validator()->validate(1, $this->input('999'));
    }

    public function testRejectsFreeTextThatOnlyFitsTheLengthLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('111 VZP');
        $this->validator()->validate(1, $this->input('ABCDEFGH'));
    }

    private function validator(): PayrollEmployerSettingsValidator
    {
        $accounts = $this->createMock(ChartOfAccountsRepository::class);
        $map = [];
        foreach (PayrollAccountingDefaults::ACCOUNTS as $definition) {
            $map[$definition['code']] = [
                'id' => 1,
                'is_active' => true,
                'account_type' => $definition['type'],
            ];
        }
        $accounts->method('codeToIdMap')->willReturn($map);

        return new PayrollEmployerSettingsValidator($accounts);
    }

    /** @return array<string,mixed> */
    private function input(?string $insurerCode): array
    {
        return [
            'default_office_code' => 'MAIN',
            'employer_registration_number' => '12345678',
            'social_security_office_code' => 'P',
            'default_health_insurer_code' => $insurerCode,
            'payroll_contact_name' => 'Testovací účetní',
            'payroll_contact_email' => 'payroll@example.test',
            'payroll_contact_phone' => '+420 000 000 000',
            'offices' => [[
                'code' => 'MAIN',
                'name' => 'Hlavní účtárna',
                'social_security_variable_symbol' => '0012345678',
                'is_active' => true,
            ]],
            'accounts' => PayrollAccountingDefaults::codes(),
        ];
    }
}
