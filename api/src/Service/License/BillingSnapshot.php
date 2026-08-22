<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Co instalace ví o (ne)uhrazení — na jednom místě pro obě cesty ven.
 *
 * Vzniklo proto, že ta samá odpověď je potřeba ve dvou rozsazích:
 *  - {@see full()} pro `/api/license/status` (superadmin) — celý obraz,
 *  - {@see dunning()} pro `/api/license/billing` (běžný admin) — jen to, co
 *    potřebuje k tomu, aby dluh viděl a mohl ho doplatit.
 *
 * ⚠️ Dvě pravidla, na kterých to stojí:
 *
 *  1. **Nic se nedopočítává.** Termíny, částku i fázi počítá licenční server;
 *     instalace je jen podává dál. Termín, který si aplikace vymyslí, je slib,
 *     který nikdo nedodrží — chybějící údaj zůstane `null`.
 *  2. **Užší rozsah je PODMNOŽINA širšího**, ne druhá implementace. Kdyby se
 *     počítal zvlášť, rozešly by se a admin s běžnými právy by viděl jiný stav
 *     než superadmin nad stejnou instalací.
 */
final class BillingSnapshot
{
    /**
     * Co smí ven i BEZ superadmina.
     *
     * ⚠️ Seznam je úmyslně krátký a rozšiřovat ho jde jen vědomě: je to jediná
     * díra v jinak superadminské bráně licenčního API. Patří sem výhradně to,
     * co člověk potřebuje k rozhodnutí „mám zaplatit a kolik" — nikdy licenční
     * klíč, fakturační údaje ani počty míst.
     *
     * @var list<string>
     */
    private const DUNNING_KEYS = [
        'unpaid',
        'license_state',
        'subscription_state',
        'phase',
        'attempt',
        'max_attempts',
        'next_attempt_at',
        'suspend_at',
        'access_until',
        'data_until',
        'amount_due',
        'currency',
        'pay_url',
    ];

    public function __construct(private readonly Config $config) {}

    /**
     * Plný obraz pro obrazovku Hostingu (superadmin).
     *
     * Dva nezávislé zdroje, oba z licenčního serveru:
     *  - STAV LICENCE — `degraded` (token propadl / chybí) a `trial_expired`
     *    znamenají zavřené komerční moduly. To je tvrdý dopad, na který uživatel
     *    narazí, a proto je to hlavní signál.
     *  - STAV PŘEDPLATNÉHO — `past_due` / `expired` hlásí server dřív, než
     *    licence propadne. Je to jediné pole, které o platbě mluví přímo, takže
     *    ho posíláme ven i tehdy, když licence zatím běží.
     *
     * @return array<string,mixed>
     */
    public function full(LicenseState $state): array
    {
        $subscriptionState = isset($state->subscription['state'])
            ? (string) $state->subscription['state']
            : null;

        $licenseUnpaid = $state->state === LicenseState::DEGRADED
            || $state->state === LicenseState::TRIAL_EXPIRED;
        $subscriptionUnpaid = $subscriptionState === 'past_due' || $subscriptionState === 'expired';

        $sub = is_array($state->subscription) ? $state->subscription : [];

        return [
            // Jediná otázka, na kterou obrazovka i linka smí odpovídat ano/ne.
            'unpaid'             => $licenseUnpaid || $subscriptionUnpaid,
            'license_state'      => $state->state,
            'subscription_state' => $subscriptionState,
            'valid_until'        => $state->validUntil,
            // Kdy se instalace naposledy ptala serveru — bez toho by „neuhrazeno"
            // mohlo znamenat jen „týden jsme se nedovolali".
            'last_check_at'      => $state->lastCheckAt,
            'last_check_ok'      => $state->lastCheckOk,

            // ── V JAKÉ FÁZI JSME A CO SE STANE DÁL ────────────────────────
            'phase'              => $this->subValue($sub, 'phase'),
            'attempt'            => $this->subInt($sub, 'attempt'),
            'max_attempts'       => $this->subInt($sub, 'max_attempts'),
            'next_attempt_at'    => $this->subInt($sub, 'next_attempt_at'),
            // Kdy se pozastaví provoz instance, když se nezaplatí.
            'suspend_at'         => $this->subInt($sub, 'suspend_at'),
            // Dokdy fungují placené funkce (konec období + odklad).
            'access_until'       => $this->subInt($sub, 'access_until'),
            // Dokdy po pozastavení držíme data.
            'data_until'         => $this->subInt($sub, 'data_until'),

            // ── ČÍM SE TO DÁ ZAPLATIT ROVNOU Z APLIKACE ───────────────────
            // Dlužná částka a podepsaný odkaz na úhradu. Obojí počítá a podepisuje
            // licenční server; když je neposlal, zůstane `null` a obrazovka
            // o částce mlčí — ale tlačítko „Zaplatit" má pořád kam vést, protože
            // `pay_url` padá zpátky na správu předplatného.
            'amount_due'         => $this->subAmount($sub, 'amount_due'),
            'currency'           => $this->subCurrency($sub),
            'pay_url'            => $this->payUrl($sub),
        ];
    }

    /**
     * Minimální výřez pro běžného admina. Podmnožina {@see full()}.
     *
     * @return array<string,mixed>
     */
    public function dunning(LicenseState $state): array
    {
        $full = $this->full($state);
        $out  = [];
        foreach (self::DUNNING_KEYS as $key) {
            $out[$key] = $full[$key];
        }

        return $out;
    }

    /**
     * Adresa správy předplatného. Prázdná konfigurace → null: obrazovka pak
     * ukáže kontakt na podporu místo tlačítka, které nikam nevede.
     */
    public function subscriptionUrl(): ?string
    {
        $portal = $this->stringOrNull('instance.portal_url');
        if ($portal !== null) {
            return $portal;
        }

        $server = $this->stringOrNull('license.server_url');

        return $server === null ? null : rtrim($server, '/') . '/predplatne';
    }

    /**
     * Kam vede „Zaplatit".
     *
     * Když licenční server pošle podepsaný odkaz přímo na úhradu, jde se tam —
     * zákazník tak zaplatí jedním kliknutím místo tří skoků přes web. Bez něj
     * zůstává dnešní cesta na správu předplatného; to je horší zážitek, ale
     * pořád funkční, a hlavně se nikdy nevymýšlí adresa, která nemusí existovat.
     *
     * ⚠️ Přijímá se JEN http(s). Odkaz jde do `href` v aplikaci, takže cokoli
     * jiného (`javascript:`, `data:`) by ze zkompromitovaného nebo podvrženého
     * stavu předplatného udělalo skript v kontextu aplikace.
     *
     * @param array<string,mixed> $sub
     */
    private function payUrl(array $sub): ?string
    {
        $raw = $this->subValue($sub, 'pay_url');
        if ($raw !== null && $this->isWebUrl($raw)) {
            return $raw;
        }

        return $this->subscriptionUrl();
    }

    private function isWebUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || $scheme === 'http';
    }

    /**
     * Hodnota z bloku předplatného, jak ji poslal server. Nic se nedopočítává
     * ani nenahrazuje výchozí hodnotou — chybějící údaj je `null`, ne nula.
     *
     * @param array<string,mixed> $sub
     */
    private function subValue(array $sub, string $key): ?string
    {
        $value = $sub[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string,mixed> $sub */
    private function subInt(array $sub, string $key): ?int
    {
        $value = $sub[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * Dlužná částka. Záporná ani nečíselná hodnota se nepodává dál — „dlužíte
     * −250 Kč" je nesmysl, který obrazovka nemá jak vyložit.
     *
     * @param array<string,mixed> $sub
     */
    private function subAmount(array $sub, string $key): ?float
    {
        $value = $sub[$key] ?? null;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $amount = (float) $value;

        return $amount >= 0.0 ? $amount : null;
    }

    /**
     * Měna dlužné částky. Bez částky nemá smysl a chybějící se NEDOPLŇUJE
     * korunami — instalace může být zahraniční a vymyšlená měna je horší lež
     * než chybějící údaj.
     *
     * @param array<string,mixed> $sub
     */
    private function subCurrency(array $sub): ?string
    {
        $value = $this->subValue($sub, 'currency');
        if ($value === null) {
            return null;
        }
        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }

    private function stringOrNull(string $key): ?string
    {
        $value = trim((string) $this->config->get($key, ''));

        return $value === '' ? null : $value;
    }
}
