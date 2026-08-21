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
        bool $commercial = true,
    ): LicenseState {
        return new LicenseState(
            $kind, 'iid-1', 'single', $maxCompanies, $usersLicensed, $usersActive,
            $companiesActive, null, null, null, $key, null, true,
            false, null, $commercial,
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

    /**
     * ⚠️ Bez platné licence nevznikne další uživatel na licencovaném MÍSTĚ.
     *
     * Netýká se to účtů s právem jen pro čtení a klientských účtů — ty licenční
     * místo nezabírají a otázka se na ně vůbec nepokládá
     * ({@see \MyInvoice\Action\Admin\UserAdminAction::roleCountsAsSeat()}).
     * Stávající uživatelé pracují dál; blokuje se jen přírůstek.
     */
    public function testExpiredLicenseBlocksNewSeatUsers(): void
    {
        foreach ([LicenseState::TRIAL_EXPIRED, LicenseState::DEGRADED] as $kind) {
            $s = $this->state($kind, maxCompanies: 1, usersLicensed: 1, usersActive: 99, companiesActive: 99);
            self::assertFalse($s->allowsNewUser(), $kind);
            self::assertSame(LicenseState::BLOCK_NO_LICENSE, $s->newUserBlockReason(), $kind);
        }
    }

    /**
     * Firmy zůstávají bez licence neomezené. Je to jiná osa než uživatelé:
     * bezplatný základ má vést účetnictví dál, včetně založení další agendy —
     * omezit to by znamenalo sáhnout na data, která už zákazník má.
     */
    public function testFreeCoreKeepsCompaniesUnlimited(): void
    {
        foreach ([LicenseState::TRIAL_EXPIRED, LicenseState::DEGRADED] as $kind) {
            $s = $this->state($kind, maxCompanies: 1, usersLicensed: 1, usersActive: 99, companiesActive: 99);
            self::assertTrue($s->allowsNewCompany(), $kind);
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
    // ─────────────────────────────────────────────────────────────────────────
    //  Bezplatný tarif: licence PLATÍ, ale placené moduly neodemyká
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ Jádro položky: klíč se vydává i na bezplatný tarif, protože je to
     * jediný kanál k instanci (kvóta, stav platby, počty). Samotná platná
     * licence proto účetnictví odemykat nesmí.
     */
    public function testFreeTierLicenseDoesNotUnlockCommercialModules(): void
    {
        $s = $this->state(LicenseState::ACTIVE, commercial: false);

        self::assertFalse($s->hasCommercialFeatures());
    }

    /**
     * ⚠️ A zároveň: na bezplatném tarifu LIMITY PLATÍ. Kdyby se strop uživatelů
     * ptal na přístup k modulům místo na platnost licence, byl by tenhle tarif
     * jediný s neomezeným počtem uživatelů — a přitom se za ně platí.
     */
    public function testFreeTierStillEnforcesSeatAndCompanyLimits(): void
    {
        $full = $this->state(LicenseState::ACTIVE, maxCompanies: 1, usersLicensed: 2, usersActive: 2, companiesActive: 1, commercial: false);
        self::assertFalse($full->allowsNewUser());
        self::assertFalse($full->allowsNewCompany());

        $room = $this->state(LicenseState::ACTIVE, maxCompanies: 2, usersLicensed: 2, usersActive: 1, companiesActive: 1, commercial: false);
        self::assertTrue($room->allowsNewUser());
        self::assertTrue($room->allowsNewCompany());
    }

    /** Propadlá licence na bezplatném tarifu: moduly zavřené, další místo se neprodá. */
    public function testExpiredFreeTierClosesModulesAndSeats(): void
    {
        $s = $this->state(LicenseState::DEGRADED, maxCompanies: 1, usersLicensed: 1, usersActive: 99, companiesActive: 99, commercial: false);

        self::assertFalse($s->hasCommercialFeatures());
        self::assertFalse($s->allowsNewUser());
        self::assertSame(LicenseState::BLOCK_NO_LICENSE, $s->newUserBlockReason());
        self::assertTrue($s->allowsNewCompany());
    }

    /**
     * Důvody se nesmí slít do jednoho: „nemáte licenci" vede k aktivaci,
     * „došla místa" k navýšení. Kdyby to byla jedna hláška, polovina adminů
     * by dělala špatnou věc.
     */
    public function testBlockReasonsAreDistinguishable(): void
    {
        $noLicense = $this->state(LicenseState::DEGRADED, usersLicensed: 1, usersActive: 0);
        $seatsFull = $this->state(LicenseState::ACTIVE, usersLicensed: 2, usersActive: 2);

        self::assertSame(LicenseState::BLOCK_NO_LICENSE, $noLicense->newUserBlockReason());
        self::assertSame(LicenseState::BLOCK_SEAT_LIMIT, $seatsFull->newUserBlockReason());
        self::assertNull($this->state(LicenseState::TRIAL, usersLicensed: 1, usersActive: 99)->newUserBlockReason());
    }

    /**
     * ⚠️ Zpětná kompatibilita: token vydaný před zavedením příznaku ho nenese.
     * Výchozí hodnota proto musí být „odemyká" — opačný default by zavřel
     * účetnictví každému platícímu zákazníkovi až do příští obnovy tokenu.
     */
    public function testMissingFlagDefaultsToCommercial(): void
    {
        self::assertTrue($this->state(LicenseState::ACTIVE)->hasCommercialFeatures());
    }

    /** Příznak jde ven, aby obrazovka rozlišila „propadlo" od „tarif to nemá". */
    public function testTierFlagIsExposedToTheFrontend(): void
    {
        $paid = $this->state(LicenseState::ACTIVE)->toMeSummary();
        $free = $this->state(LicenseState::ACTIVE, commercial: false)->toMeSummary();

        self::assertTrue($paid['tier_commercial']);
        self::assertTrue($paid['commercial_features']);
        self::assertFalse($free['tier_commercial']);
        self::assertFalse($free['commercial_features']);
    }
}