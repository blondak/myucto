<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxCompanyProfile;
use PHPUnit\Framework\TestCase;

final class StereoNxCompanyProfileTest extends TestCase
{
    public function testOnlyWhitelistedDifferencesForSameCompanyAreSuggested(): void
    {
        $identity = ['ico' => '00000000', 'name' => 'Testovací firma', 'dic' => 'CZ00000000',
            'company_profile' => ['street' => 'Testovací 1', 'city' => 'Testov', 'email' => 'test@example.invalid',
                'accounting_mode' => 'double_entry', 'is_vat_payer' => true, 'ic' => '11111111', 'country_id' => 1]];
        $target = ['ic' => '00 000 000', 'company_name' => 'Aktuální název', 'street' => 'Nová 2', 'email' => ' ', 'city' => null];
        self::assertSame(['company_name' => 'Testovací firma', 'dic' => 'CZ00000000', 'street' => 'Testovací 1', 'city' => 'Testov', 'email' => 'test@example.invalid'], StereoNxCompanyProfile::suggestions($identity, $target));
        self::assertSame([], StereoNxCompanyProfile::suggestions($identity, ['ic' => '99999999']));
        self::assertSame([], StereoNxCompanyProfile::suggestions([], []));
        $target['email'] = 'new@example.invalid';
        self::assertSame(['email' => 'test@example.invalid'], StereoNxCompanyProfile::selected($identity, $target, ['email'], ['email' => 'new@example.invalid']));
        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        StereoNxCompanyProfile::selected($identity, $target, ['email'], ['email' => 'stale@example.invalid']);
    }
    public function testAccountingSettingsCannotBeSelectedForProfileUpdate(): void
    {
        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        StereoNxCompanyProfile::selected(['ico' => '00000000'], ['ic' => '00000000'],
            ['accounting_mode'], ['accounting_mode' => 'tax_evidence']);
    }

    public function testSelectionRequiresExpectedCurrentValue(): void
    {
        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        StereoNxCompanyProfile::selected(['ico' => '00000000', 'name' => 'Firma'], ['ic' => '00000000'],
            ['company_name'], []);
    }
}
