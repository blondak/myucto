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
            $payload['instance'] = $this->instance();
        }

        return Json::ok($response, $payload);
    }

    /**
     * Zaplacený rozsah služby + obsazení místa. Nic o dodavateli.
     *
     * @return array<string,mixed>
     */
    private function instance(): array
    {
        $status     = $this->quota->evaluate();
        $usageBytes = $status->usageBytes;              // ⚠️ null = neměřeno, ne nula
        $contracted = $this->quota->contractedBytes();  // ⚠️ null = neznámý objem

        return [
            'managed'          => true,
            'plan'             => $this->stringOrNull('instance.plan'),
            'managed_since'    => $this->stringOrNull('instance.managed_since'),
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
            ],
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
