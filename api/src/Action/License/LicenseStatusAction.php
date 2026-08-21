<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\System\InstanceEntitlement;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageQuotaPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/license/status — přehled stavu licence (admin only).
 *
 * Vrací i objekt `company` s fakturačními údaji aktuální firmy (X-Supplier-Id) —
 * FE jimi předvyplní checkout na webu (nevynucené výchozí hodnoty).
 *
 * ── Spravovaná instalace (SaaS) ───────────────────────────────────────────
 * Ve spravovaném režimu (`app.managed`) přibývá blok `instance`: zaplacený
 * rozsah služby a obsazení místa. Obrazovka aktivace z něj místo nabídky koupit
 * licenci staví STAV SLUŽBY.
 *
 * ⚠️ Tři pravidla, na kterých ten blok stojí:
 *
 *  1. Na self-hosted instalaci se klíč `instance` NEOBJEVÍ VŮBEC. Ne `null`,
 *     ne prázdný objekt — self-hosted odpověď musí zůstat bajt po bajtu ta
 *     samá, protože je to hlavní cesta k nákupu licence.
 *  2. Ven jde jen to, co obrazovka potřebuje, a NIKDY nic o dodavateli
 *     (`app.managed_provider` zůstává diagnostikou v /api/health).
 *  3. Obsazení místa je údaj pro přihlášeného admina — action proto začíná
 *     kontrolou {@see RequestAuthorization::isSuperadmin()} a anonymní volající
 *     dostane 403 dřív, než se cokoli změří.
 */
final class LicenseStatusAction
{
    public function __construct(
        private readonly LicenseService $license,
        private readonly Connection $db,
        private readonly ManagedModeGuard $managed,
        private readonly StorageQuotaPolicy $quota,
        private readonly Config $config,
        private readonly InstanceEntitlement $entitlement,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $state = LicenseMiddleware::state($request) ?? $this->license->current();
        $payload = $state->toArray($this->license->buyUrl());
        $payload['company'] = $this->company($request);

        // Pozor na pořadí: klíč se přidává JEN ve spravovaném režimu, aby
        // self-hosted odpověď zůstala nezměněná.
        if ($this->managed->isManaged()) {
            $payload['instance'] = $this->instance($state);
        }

        return Json::ok($response, $payload);
    }

    /**
     * Zaplacený rozsah služby + obsazení místa. Nic o dodavateli.
     *
     * @return array<string,mixed>
     */
    private function instance(LicenseState $state): array
    {
        $status     = $this->quota->evaluate();
        $usageBytes = $status->usageBytes;              // ⚠️ null = neměřeno, ne nula
        $contracted = $this->quota->contractedBytes();  // ⚠️ null = neznámý objem
        $raw        = $this->entitlement->deliveredRaw();

        return [
            'billing'          => $this->billing($state),
            'links'            => $this->links(),
            'managed'          => true,
            // ⚠️ Ne z konfigurace: po dokoupení místa nebo změně tarifu je
            // čerstvý údaj ten, který doručil licenční server.
            'plan'             => $this->entitlement->plan(),
            'managed_since'    => $this->entitlement->managedSince(),
            'subscription_url' => $this->subscriptionUrl(),
            'storage'          => [
                // `measured` je jediná legální otázka na „máme čím počítat".
                // Bez ní by prázdná a nezměřená instalace vypadaly stejně.
                'measured'    => $status->snapshot->isMeasured(),
                'measured_at' => $status->snapshot->measuredAt?->format(\DateTimeInterface::ATOM),
                'usage_bytes' => $usageBytes,
                // ZAPLACENÝ objem, ne provozní limit: disková kvóta hostingu je
                // „zaplacený objem + rezerva na dumpy" a zákazníkovi by hlásila
                // víc, než si koupil.
                'quota_bytes'       => $contracted,
                // null, dokud neznáme obojí — procenta se nedopočítávají.
                'percent'           => $this->quota->contractedPercent($usageBytes),
                'warn_percent'      => $status->warnPercent,
                'read_only_percent' => $status->readOnlyPercent,
                // Skutečný stav vynucení (z provozního limitu) — obrazovka podle
                // něj neslibuje zápis, který middleware odmítne.
                'blocks_writes'     => $status->blocksWrites(),

                // ⚠️ Zaplacené rozšíření, které provozovatel ještě nezavedl.
                // Dokud to platí, obrazovka NESMÍ nabízet dokoupení znovu —
                // zákazník už zaplatil a druhé kliknutí by strhlo podruhé.
                'change_pending'    => (bool) ($raw['quota_change_pending'] ?? false),
                // Objem, který si zákazník objednal (může být větší než ten,
                // proti kterému se dnes měří).
                'quota_gb_ordered'  => isset($raw['quota_gb_ordered']) ? (int) $raw['quota_gb_ordered'] : null,
                // Odkud se ví, kolik má zaplaceno: `license` / `config` / `none`.
                'quota_source'      => $this->entitlement->quotaSource(),
            ],
        ];
    }

    /**
     * Co instalace SKUTEČNĚ ví o (ne)uhrazení. Nic víc — pole, které by muselo
     * vzniknout dohadem (částka, splatnost, kdy nám platba dojde), tu záměrně
     * není: červená linka nad aplikací se o něj nesmí opřít a tvrdit číslo,
     * které jsme si vymysleli.
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
    private function billing(LicenseState $state): array
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
            // Všechno počítá licenční server; instalace to jen podává dál.
            // ⚠️ Žádné z těch dat se tu NESMÍ dopočítávat: termín, který si
            // aplikace vymyslí, je slib, který nikdo nedodrží. Když ho server
            // neposlal, zůstane null a obrazovka řekne „ozveme se".
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
        ];
    }

    /**
     * Adresy na myucto.cz. Berou se z `license.server_url`, aby šly přepnout
     * konfigurací (test/staging) — v kódu ani v šabloně nesmí být zadrátované.
     * null = adresa není nakonfigurovaná; obrazovka pak odkaz nekreslí.
     *
     * @return array<string,?string>
     */
    private function links(): array
    {
        $server = $this->stringOrNull('license.server_url');
        $at = static fn(?string $base, string $path): ?string
            => $base === null ? null : rtrim($base, '/') . $path;

        return [
            'subscription' => $this->subscriptionUrl(),
            // Rozšíření prostoru se objednává tam, kde se spravuje předplatné.
            'expand_storage' => $at($server, '/predplatne'),
            'support'      => $at($server, '/support'),
            'terms'        => $at($server, '/obchodni-podminky'),
            'privacy'      => $at($server, '/ochrana-osobnich-udaju'),
        ];
    }

    /**
     * Adresa správy předplatného. Prázdná konfigurace → null: obrazovka pak
     * ukáže kontakt na podporu místo tlačítka, které nikam nevede.
     */
    private function subscriptionUrl(): ?string
    {
        $portal = $this->stringOrNull('instance.portal_url');
        if ($portal !== null) {
            return $portal;
        }

        $server = $this->stringOrNull('license.server_url');

        return $server === null ? null : rtrim($server, '/') . '/predplatne';
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
    private function stringOrNull(string $key): ?string
    {
        $value = trim((string) $this->config->get($key, ''));

        return $value === '' ? null : $value;
    }

    /**
     * Fakturační údaje aktuální firmy (předvyplnění webového checkoutu). Fakturační
     * e-mail firmy; fallback na e-mail přihlášeného admina.
     *
     * @return array<string,string>
     */
    private function company(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $adminEmail = trim((string) ($user['email'] ?? ''));

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $supplier = null;
        if ($supplierId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT company_name, ic, dic, street, city, zip, email FROM supplier WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $email = trim((string) ($supplier['email'] ?? ''));
        if ($email === '') {
            $email = $adminEmail;
        }

        return [
            'name'   => (string) ($supplier['company_name'] ?? ''),
            'ic'     => (string) ($supplier['ic'] ?? ''),
            'dic'    => (string) ($supplier['dic'] ?? ''),
            'street' => (string) ($supplier['street'] ?? ''),
            'city'   => (string) ($supplier['city'] ?? ''),
            'zip'    => (string) ($supplier['zip'] ?? ''),
            'email'  => $email,
        ];
    }
}
