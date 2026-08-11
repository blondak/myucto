<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Vypočtený stav licence instance — snapshot pro middleware, Actions i /auth/me.
 *
 * Stavy:
 *   trial         — bez klíče, < 60 dní od trial_started_at (plný provoz, bez limitů)
 *   trial_expired — bez klíče, po 60 dnech (MIT základ zůstává plně funkční)
 *   active        — platný token, status ok, now <= valid_until
 *   overage       — token status overage (plný provoz + výzva, ale blok tvorby uživatelů/firem)
 *   degraded      — token expirovaný / neplatný podpis / chybí (bez komerčních funkcí)
 */
final class LicenseState
{
    public const TRIAL         = 'trial';
    public const TRIAL_EXPIRED = 'trial_expired';
    public const ACTIVE        = 'active';
    public const OVERAGE       = 'overage';
    public const DEGRADED      = 'degraded';

    public function __construct(
        public readonly string $state,
        public readonly string $instanceId,
        public readonly ?string $tier,
        /** null = neomezeno; 0+ = limit počtu firem */
        public readonly ?int $maxCompanies,
        /** licencovaný počet aktivních uživatelů; 0 = neurčeno (trial → bez limitu) */
        public readonly int $usersLicensed,
        public readonly int $usersActive,
        public readonly int $companiesActive,
        public readonly ?int $validUntil,
        public readonly ?int $trialEndsAt,
        public readonly ?int $overageDeadline,
        public readonly ?string $licenseKey,
        public readonly ?string $lastCheckAt,
        public readonly bool $lastCheckOk,
        /** Doživotní (perpetual) licence — neomezená platnost; valid_until je jen 14denní TTL tokenu. */
        public readonly bool $perpetual = false,
        /**
         * Poslední známý stav předplatného z licenčního serveru — automatické
         * prodlužování a datum dalšího stržení. `null` = server ho nehlásil
         * (doživotní licence, trial, starší server).
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $subscription = null,
    ) {}

    /** Prodlužuje se licence automaticky (chystá se další stržení)? */
    public function autoRenews(): bool
    {
        return (bool) ($this->subscription['auto_renew'] ?? false);
    }

    /** Je dostupná komerční účetní nadstavba MyÚčto? */
    public function hasCommercialFeatures(): bool
    {
        return $this->state !== self::DEGRADED && $this->state !== self::TRIAL_EXPIRED;
    }

    /** Smí vzniknout nový (nebo aktivovaný) uživatel s rolí != readonly? */
    public function allowsNewUser(): bool
    {
        if ($this->state === self::TRIAL || !$this->hasCommercialFeatures()) {
            return true;
        }
        if ($this->state === self::ACTIVE) {
            return $this->usersLicensed <= 0 || $this->usersActive < $this->usersLicensed;
        }
        // Overage přes limit → blok do navýšení nebo srovnání počtů.
        return false;
    }

    /** Smí vzniknout nová firma (supplier)? */
    public function allowsNewCompany(): bool
    {
        if ($this->state === self::TRIAL || !$this->hasCommercialFeatures()) {
            return true;
        }
        if ($this->state === self::ACTIVE) {
            return $this->maxCompanies === null || $this->companiesActive < $this->maxCompanies;
        }
        return false;
    }

    /** Maskovaný klíč pro UI: MYU-XXXX-…-poslední 4 znaky. */
    public function maskedKey(): ?string
    {
        if ($this->licenseKey === null || $this->licenseKey === '') {
            return null;
        }
        $key = $this->licenseKey;
        $last4 = substr($key, -4);
        $prefix = str_contains($key, '-') ? explode('-', $key, 2)[0] : substr($key, 0, 3);
        return $prefix . '-XXXX-…-' . $last4;
    }

    /** Serializace pro /api/license/status. */
    public function toArray(string $buyUrl): array
    {
        return [
            'state'           => $this->state,
            'instance_id'     => $this->instanceId,
            'tier'            => $this->tier,
            'max_companies'   => $this->maxCompanies,
            'users_licensed'  => $this->usersLicensed,
            'users_active'    => $this->usersActive,
            'companies_active' => $this->companiesActive,
            'valid_until'     => $this->validUntil,
            'trial_ends_at'   => $this->trialEndsAt,
            'overage_deadline' => $this->overageDeadline,
            'perpetual'       => $this->perpetual,
            'license_key_masked' => $this->maskedKey(),
            'last_check_at'   => $this->lastCheckAt,
            'last_check_ok'   => $this->lastCheckOk,
            'commercial_features' => $this->hasCommercialFeatures(),
            'buy_url'         => $buyUrl,
            // Stav předplatného ze serveru: {state, period, auto_renew, next_charge_at,
            // cancelled_at, valid_until}. null = licence se automaticky neprodlužuje
            // (doživotní, trial) nebo to server nehlásí.
            'subscription'    => $this->subscription,
        ];
    }

    /** Kompaktní shrnutí pro /auth/me (FE bannery). */
    public function toMeSummary(): array
    {
        return [
            'state'            => $this->state,
            'tier'             => $this->tier,
            'trial_ends_at'    => $this->trialEndsAt,
            'valid_until'      => $this->validUntil,
            'overage_deadline' => $this->overageDeadline,
            'perpetual'        => $this->perpetual,
            'commercial_features' => $this->hasCommercialFeatures(),
            // Počty pro přehledný overage banner (aktivní vs. licencováno).
            'users_active'     => $this->usersActive,
            'users_licensed'   => $this->usersLicensed,
            'companies_active' => $this->companiesActive,
            'max_companies'    => $this->maxCompanies,
        ];
    }
}
