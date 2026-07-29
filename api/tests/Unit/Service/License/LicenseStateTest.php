<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\TestCase;

final class LicenseStateTest extends TestCase
{
    private function state(
        string $kind,
        ?int $maxCompanies = null,
        int $usersLicensed = 0,
        int $usersActive = 0,
        int $companiesActive = 0,
        ?string $key = null,
    ): LicenseState {
        return new LicenseState(
            $kind, 'iid-1', 'single', $maxCompanies, $usersLicensed, $usersActive,
            $companiesActive, null, null, null, $key, null, true,
        );
    }

    public function testCommercialFeatureStates(): void
    {
        self::assertFalse($this->state(LicenseState::DEGRADED)->hasCommercialFeatures());
        self::assertFalse($this->state(LicenseState::TRIAL_EXPIRED)->hasCommercialFeatures());
        self::assertTrue($this->state(LicenseState::TRIAL)->hasCommercialFeatures());
        self::assertTrue($this->state(LicenseState::ACTIVE)->hasCommercialFeatures());
        self::assertTrue($this->state(LicenseState::OVERAGE)->hasCommercialFeatures());
    }

    public function testTrialHasNoLimits(): void
    {
        $s = $this->state(LicenseState::TRIAL, maxCompanies: null, usersLicensed: 0, usersActive: 99, companiesActive: 99);
        self::assertTrue($s->allowsNewUser());
        self::assertTrue($s->allowsNewCompany());
    }

    public function testFreeCoreHasNoUserOrCompanyLimitsAfterLicenseExpires(): void
    {
        foreach ([LicenseState::TRIAL_EXPIRED, LicenseState::DEGRADED] as $kind) {
            $s = $this->state($kind, maxCompanies: 1, usersLicensed: 1, usersActive: 99, companiesActive: 99);
            self::assertTrue($s->allowsNewUser());
            self::assertTrue($s->allowsNewCompany());
        }
    }

    public function testActiveUserSeatLimit(): void
    {
        self::assertTrue($this->state(LicenseState::ACTIVE, usersLicensed: 5, usersActive: 4)->allowsNewUser());
        self::assertFalse($this->state(LicenseState::ACTIVE, usersLicensed: 5, usersActive: 5)->allowsNewUser());
        // usersLicensed = 0 => neomezeno
        self::assertTrue($this->state(LicenseState::ACTIVE, usersLicensed: 0, usersActive: 100)->allowsNewUser());
    }

    public function testActiveCompanyLimit(): void
    {
        self::assertTrue($this->state(LicenseState::ACTIVE, maxCompanies: 10, companiesActive: 9)->allowsNewCompany());
        self::assertFalse($this->state(LicenseState::ACTIVE, maxCompanies: 10, companiesActive: 10)->allowsNewCompany());
        // null => neomezeno
        self::assertTrue($this->state(LicenseState::ACTIVE, maxCompanies: null, companiesActive: 999)->allowsNewCompany());
    }

    public function testOverageBlocksNewUsersAndCompanies(): void
    {
        $s = $this->state(LicenseState::OVERAGE, maxCompanies: 10, usersLicensed: 5, usersActive: 3, companiesActive: 3);
        self::assertFalse($s->allowsNewUser());
        self::assertFalse($s->allowsNewCompany());
    }

    public function testMaskedKey(): void
    {
        self::assertNull($this->state(LicenseState::TRIAL)->maskedKey());
        self::assertSame('MYU-XXXX-…-ABCD', $this->state(LicenseState::ACTIVE, key: 'MYU-1234-5678-ABCD')->maskedKey());
    }

    public function testPerpetualDefaultsFalseAndSurfacesInSummary(): void
    {
        // Bez perpetual argumentu → false (běžné placené předplatné).
        $normal = $this->state(LicenseState::ACTIVE, key: 'MYU-1234-5678-ABCD');
        self::assertFalse($normal->perpetual);
        self::assertFalse($normal->toMeSummary()['perpetual']);
        self::assertFalse($normal->toArray('https://myucto.cz/objednavka')['perpetual']);

        // Doživotní licence → perpetual = true prosákne do /auth/me i /license/status.
        $perpetual = new LicenseState(
            LicenseState::ACTIVE, 'iid-1', 'unlimited', null, 0, 3, 2,
            time() + 14 * 86400, null, null, 'MYU-1234-5678-ABCD', null, true, true,
        );
        self::assertTrue($perpetual->perpetual);
        self::assertTrue($perpetual->toMeSummary()['perpetual']);
        self::assertTrue($perpetual->toArray('https://myucto.cz/objednavka')['perpetual']);
    }
}
