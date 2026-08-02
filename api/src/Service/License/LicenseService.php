<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stav a životní cyklus licence instance (E4).
 *
 * - `current()` načte řádek `license`, ověří podpis tokenu a spočítá stav.
 * - `activate()` / `deactivate()` volají licenční server a ukládají klíč+token.
 * - `renewIfDue()` jednou denně obnoví token (chráněno atomickým UPDATE mutexem).
 *
 * Síťové chyby serveru se tolerují — stav se řídí platností posledního tokenu.
 */
final class LicenseService
{
    /** Zabudovaný veřejný klíč (base64, dev). Přepsatelný přes cfg license.public_key. */
    public const DEFAULT_PUBLIC_KEY = 'lDwgisBH87eegfc95Z3dvc9FhMpZz/sQtat8JMd+KdE=';

    private const TRIAL_DAYS = 60;

    private readonly LoggerInterface $logger;

    /**
     * Licenční řádek načtený v tomhle requestu. Zahazuje ho každý zápis přes
     * {@see writeLicense()} — viz komentář u {@see loadRow()}.
     *
     * @var array<string,mixed>|null
     */
    private ?array $rowCache = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly LicenseTokenVerifier $verifier,
        private readonly LicenseClient $client,
        ?LoggerInterface $logger = null,
        ?EntityCache $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        // Volitelná kvůli testovacím dvojníkům, které službu staví ručně.
        // NullEntityCache je průchozí, takže bez ní se chování nemění.
        $this->cache = $cache ?? EntityCache::disabled();
    }

    private readonly EntityCache $cache;

    public function current(): LicenseState
    {
        if (!$this->db->hasTable('license')) {
            // Před spuštěním migrace 1139 — fail-open do trialu, ať upgrade neuzamkne app.
            return new LicenseState(
                LicenseState::TRIAL, '', null, null, 0, 0, 0, null,
                time() + self::TRIAL_DAYS * 86400, null, null, null, true,
            );
        }
        $row = $this->loadRow();
        $this->ensureFingerprint($row);
        return $this->computeState($row);
    }

    /**
     * Aktivace licenčním klíčem — zavolá server, ověří podpis vráceného tokenu
     * a uloží klíč+token lokálně.
     *
     * @param bool $takeover Vynucený přenos vazby z jiné instalace (po `already_bound`).
     *                       Počítá se do limitu přenosů 2/30 dní; při jeho vyčerpání
     *                       server vrátí `transfer_limit`.
     * @return array{ok:bool,error?:string,transfers_remaining?:int,state?:LicenseState}
     */
    public function activate(string $licenseKey, bool $takeover = false): array
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'invalid_key'];
        }
        $row = $this->loadRow();
        $fingerprint = $this->ensureFingerprint($row);

        try {
            $resp = $this->client->activate($licenseKey, (string) $row['instance_id'], $fingerprint, $this->appVersion(), $takeover);
        } catch (LicenseNetworkException $e) {
            $this->logger->info('license.activate.network_error', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'server_unreachable'];
        }

        if (($resp['ok'] ?? false) !== true || empty($resp['token'])) {
            $result = ['ok' => false, 'error' => (string) ($resp['error'] ?? 'activation_failed')];
            // Zbývající přenosy propagujeme do UI (nabídka „přenést" u already_bound).
            if (isset($resp['transfers_remaining'])) {
                $result['transfers_remaining'] = (int) $resp['transfers_remaining'];
            }
            return $result;
        }

        $token = (string) $resp['token'];
        $payload = $this->verifier->verify($token, $this->publicKey());
        if ($payload === null) {
            $this->logger->warning('license.activate.bad_signature');
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        $this->writeLicense(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    counter = 0, last_check_at = NOW(), last_check_ok = 1
              WHERE id = 1',
            [
                $licenseKey,
                $token,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $this->nonceOf($payload),
            ],
        );

        return ['ok' => true, 'state' => $this->current()];
    }

    /**
     * Deaktivace — uvolní vazbu na serveru a smaže klíč/token lokálně. Lokální
     * smazání proběhne i když je server nedostupný (uživatel se nesmí zaseknout).
     *
     * @return array{ok:bool,transfers_remaining:?int,state:LicenseState}
     */
    public function deactivate(): array
    {
        $row = $this->loadRow();
        $key = $this->keyOf($row);
        $transfersRemaining = null;

        if ($key !== null) {
            try {
                $resp = $this->client->deactivate($key, (string) $row['instance_id']);
                if (isset($resp['transfers_remaining'])) {
                    $transfersRemaining = (int) $resp['transfers_remaining'];
                }
            } catch (LicenseNetworkException $e) {
                $this->logger->info('license.deactivate.network_error', ['error' => $e->getMessage()]);
            }
        }

        $this->writeLicense(
            'UPDATE license
                SET license_key = NULL, token = NULL, token_payload = NULL,
                    last_nonce = NULL, counter = 0, last_check_ok = 1
              WHERE id = 1'
        );

        return ['ok' => true, 'transfers_remaining' => $transfersRemaining, 'state' => $this->current()];
    }

    /**
     * Denní obnova tokenu. Atomický UPDATE mutex zajistí, že renew provede jen
     * první request dne; ostatní se vrátí bez akce. Síťovou chybu jen zaloguje.
     */
    public function renewIfDue(): void
    {
        if (!$this->db->hasTable('license')) {
            return;
        }
        $row = $this->loadRow();
        $key = $this->keyOf($row);
        if ($key === null) {
            return; // trial → není co obnovovat
        }

        // Předfiltr nad už načteným řádkem: mutex níž je zápis a bere zámek na
        // řádku license id=1, ale drtivá většina requestů ho poslala jen proto,
        // aby dostala „0 dotčených řádků". Při souběhu se na tom všechny requesty
        // instalace potkávaly. Když z dat vidíme, že dnes už kontrola proběhla,
        // nemá smysl na DB sahat vůbec.
        //
        // Není to náhrada mutexu, jen zkratka: samotný mutex zůstává atomický
        // a rozhoduje o právu obnovit. Session time_zone je nastavená z PHP
        // (viz Connection::pdo()), takže CURDATE() a date('Y-m-d') mluví o témž dni;
        // kdyby se přesto rozešly, dopad je nanejvýš o request odložená obnova.
        $lastCheck = $row['last_check_at'] ?? null;
        if (is_string($lastCheck) && $lastCheck !== '' && str_starts_with($lastCheck, date('Y-m-d'))) {
            return;
        }

        $pdo = $this->db->pdo();
        $mutex = $pdo->prepare(
            'UPDATE license SET last_check_at = NOW()
              WHERE id = 1 AND (last_check_at IS NULL OR DATE(last_check_at) <> CURDATE())'
        );
        $mutex->execute();
        $this->rowCache = null; // mutex právě přepsal last_check_at
        if ($mutex->rowCount() === 0) {
            return; // dnes už proběhlo (jiný request / cron)
        }

        $counter = (int) $row['counter'] + 1;
        $usersActive = $this->countActiveUsers();
        $companiesActive = $this->countCompanies();

        try {
            $resp = $this->client->renew(
                $key,
                (string) $row['instance_id'],
                $counter,
                $this->nonceOf($row['token_payload'] ?? null) ?? ($row['last_nonce'] ?? null),
                $usersActive,
                $companiesActive,
                $this->appVersion(),
            );
        } catch (LicenseNetworkException $e) {
            $this->writeLicense('UPDATE license SET last_check_ok = 0, counter = ? WHERE id = 1', [$counter]);
            $this->logger->info('license.renew.network_error', ['error' => $e->getMessage()]);
            return;
        }

        if (($resp['ok'] ?? false) === true && !empty($resp['token'])) {
            $token = (string) $resp['token'];
            $payload = $this->verifier->verify($token, $this->publicKey());
            if ($payload === null) {
                $this->writeLicense('UPDATE license SET last_check_ok = 0, counter = ? WHERE id = 1', [$counter]);
                $this->logger->warning('license.renew.bad_signature');
                return;
            }
            $this->writeLicense(
                'UPDATE license
                    SET token = ?, token_payload = ?, last_nonce = ?, counter = ?,
                        last_check_at = NOW(), last_check_ok = 1
                  WHERE id = 1',
                [$token, json_encode($payload, JSON_UNESCAPED_UNICODE), $this->nonceOf($payload), $counter],
            );
            return;
        }

        // Server odmítl (not_bound / clone_suspected / subscription_expired / overage_expired) —
        // stávající token necháme doběhnout, stav se degraduje až vyprší.
        $this->writeLicense('UPDATE license SET last_check_ok = 0, counter = ? WHERE id = 1', [$counter]);
        $this->logger->warning('license.renew.rejected', ['error' => (string) ($resp['error'] ?? 'unknown')]);
    }

    /**
     * Kalkulace poměrného doplatku za navýšení počtu uživatelů (in-place upgrade).
     * Nic nestrhává — jen dotáhne od serveru {current_users, new_users, amount, currency, period_end}.
     *
     * @return array{ok:bool,error?:string,current_users?:int,new_users?:int,amount?:mixed,currency?:string,period_end?:mixed}
     */
    public function upgradeQuote(int $users): array
    {
        $row = $this->loadRow();
        $key = $this->keyOf($row);
        if ($key === null) {
            return ['ok' => false, 'error' => 'invalid_key'];
        }

        try {
            $resp = $this->client->upgradeQuote($key, $users);
        } catch (LicenseNetworkException $e) {
            $this->logger->info('license.upgrade_quote.network_error', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'server_unreachable'];
        }

        if (($resp['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'not_upgradable')];
        }

        return $resp;
    }

    /**
     * Navýšení počtu uživatelů (in-place) — server strhne poměrný doplatek z uložené
     * karty a ihned navýší místa. Po úspěchu vynutíme obnovu tokenu, ať se lokálně
     * promítne nový `users` (vyšší limit).
     *
     * @return array{ok:bool,error?:string,new_users?:int,amount_charged?:mixed,state?:LicenseState}
     */
    public function upgrade(int $users): array
    {
        $row = $this->loadRow();
        $key = $this->keyOf($row);
        if ($key === null) {
            return ['ok' => false, 'error' => 'invalid_key'];
        }

        try {
            $resp = $this->client->upgrade($key, $users);
        } catch (LicenseNetworkException $e) {
            $this->logger->info('license.upgrade.network_error', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'server_unreachable'];
        }

        if (($resp['ok'] ?? false) !== true) {
            $this->logger->warning('license.upgrade.rejected', ['error' => (string) ($resp['error'] ?? 'unknown')]);
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'upgrade_failed')];
        }

        // Vynuť obnovu tokenu — přijde nový token s vyšším limitem uživatelů.
        $this->forceRenew();

        return [
            'ok'             => true,
            'new_users'      => (int) ($resp['new_users'] ?? $users),
            'amount_charged' => $resp['amount_charged'] ?? null,
            'state'          => $this->current(),
        ];
    }

    /**
     * Okamžitá obnova tokenu mimo denní cyklus — resetuje mutex (`last_check_at`)
     * a spustí `renewIfDue()`. Používá se po in-place upgradu, ať se nový limit
     * promítne hned, ne až při další denní kontrole.
     */
    public function forceRenew(): void
    {
        if (!$this->db->hasTable('license')) {
            return;
        }
        $this->writeLicense('UPDATE license SET last_check_at = NULL WHERE id = 1');
        $this->renewIfDue();
    }

    public function buyUrl(): string
    {
        return rtrim((string) $this->config->get('license.server_url', 'https://myucto.cz'), '/') . '/objednavka';
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function loadRow(): array
    {
        // Licenční řádek se v rámci JEDNOHO requestu čte opakovaně: renewIfDue()
        // i current() si ho oba načítaly zvlášť, takže LicenseMiddleware posílal
        // `SELECT * FROM license` dvakrát na každý přihlášený request.
        //
        // Memo je vázané na instanci služby, tedy na request (PHP-DI vrací singleton
        // per kontejner). Každý zápis do řádku ho zahodí — viz writeLicense().
        if ($this->rowCache !== null) {
            return $this->rowCache;
        }

        // Mezi requesty přes EntityCache (skupina `license`); zápisy do tabulky
        // ji přetáčejí automaticky na úrovni PDO, takže po activate/deactivate/renew
        // se čte znovu. Uvnitř requestu pak platí $rowCache výše.
        $row = $this->cache->remember(
            EntityCache::GROUP_LICENSE,
            'row',
            function (): array {
                $row = $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    // Řádek chybí (seed migrace neproběhl) — vytvoř ho lazily.
                    $this->writeLicense("INSERT INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())");
                    $row = $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
                }

                return is_array($row) ? $row : [];
            },
        );

        return $this->rowCache = (is_array($row) ? $row : []);
    }

    /**
     * JEDINÁ cesta, kterou se smí zapisovat do tabulky `license`.
     *
     * Důvod je memo v {@see loadRow()}: kdyby zápis šel mimo tuhle metodu, služba
     * by ve zbytku requestu pracovala se zastaralým řádkem — a u licence to nejsou
     * kosmetické následky (počet míst, platnost, degradovaný stav). Invariant hlídá
     * architektonický test, ne jen tenhle komentář.
     *
     * @param list<mixed> $params
     * @return int počet dotčených řádků
     */
    private function writeLicense(string $sql, array $params = []): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $this->rowCache = null;

        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $row */
    private function computeState(array $row): LicenseState
    {
        $now = time();
        $instanceId = (string) ($row['instance_id'] ?? '');
        $key = $this->keyOf($row);
        $usersActive = $this->countActiveUsers();
        $companiesActive = $this->countCompanies();
        $lastCheckAt = isset($row['last_check_at']) ? (string) $row['last_check_at'] : null;
        $lastCheckOk = (bool) ($row['last_check_ok'] ?? 1);

        if ($key === null) {
            $trialStarted = strtotime((string) ($row['trial_started_at'] ?? 'now')) ?: $now;
            $trialEndsAt = $trialStarted + self::TRIAL_DAYS * 86400;
            $state = $now <= $trialEndsAt ? LicenseState::TRIAL : LicenseState::TRIAL_EXPIRED;
            return new LicenseState(
                $state, $instanceId, null, null, 0, $usersActive, $companiesActive,
                null, $trialEndsAt, null, null, $lastCheckAt, $lastCheckOk,
            );
        }

        $token = (string) ($row['token'] ?? '');
        $payload = $token !== '' ? $this->verifier->verify($token, $this->publicKey()) : null;

        // Klíč je, ale token chybí / má neplatný podpis / patří jiné instanci → degraded.
        if ($payload === null || (string) ($payload['iid'] ?? '') !== $instanceId) {
            return new LicenseState(
                LicenseState::DEGRADED, $instanceId, null, null, 0, $usersActive, $companiesActive,
                null, null, null, $key, $lastCheckAt, $lastCheckOk,
            );
        }

        $validUntil = (int) ($payload['valid_until'] ?? 0);
        $tier = isset($payload['tier']) ? (string) $payload['tier'] : null;
        $maxCompanies = array_key_exists('max_companies', $payload) && $payload['max_companies'] !== null
            ? (int) $payload['max_companies']
            : null;
        $usersLicensed = (int) ($payload['users'] ?? 0);
        $overageDeadline = isset($payload['overage_deadline']) && $payload['overage_deadline'] !== null
            ? (int) $payload['overage_deadline']
            : null;
        // Doživotní licence — server přidal do payloadu bool `perpetual`. Neomezená platnost;
        // valid_until je jen 14denní TTL tokenu, který se denně obnovuje (renew u perpetual vždy projde).
        $perpetual = (bool) ($payload['perpetual'] ?? false);

        if ($now > $validUntil) {
            return new LicenseState(
                LicenseState::DEGRADED, $instanceId, $tier, $maxCompanies, $usersLicensed,
                $usersActive, $companiesActive, $validUntil, null, $overageDeadline, $key, $lastCheckAt, $lastCheckOk, $perpetual,
            );
        }

        $state = ((string) ($payload['status'] ?? 'ok')) === 'overage'
            ? LicenseState::OVERAGE
            : LicenseState::ACTIVE;

        return new LicenseState(
            $state, $instanceId, $tier, $maxCompanies, $usersLicensed,
            $usersActive, $companiesActive, $validUntil, null, $overageDeadline, $key, $lastCheckAt, $lastCheckOk, $perpetual,
        );
    }

    /**
     * Fingerprint = sha256(hostname + DB name + app URL). Uloží se lazily do řádku,
     * pokud ještě chybí (např. po seedu migrace).
     *
     * @param array<string,mixed> $row
     */
    private function ensureFingerprint(array $row): string
    {
        $existing = isset($row['fingerprint']) ? (string) $row['fingerprint'] : '';
        if ($existing !== '') {
            return $existing;
        }
        $fingerprint = $this->fingerprint();
        try {
            $this->writeLicense('UPDATE license SET fingerprint = ? WHERE id = 1', [$fingerprint]);
        } catch (\Throwable) {
            // nekritické — fingerprint dopočítáme příště
        }
        return $fingerprint;
    }

    private function fingerprint(): string
    {
        $host = gethostname() ?: php_uname('n');
        $dbName = (string) $this->config->get('db.name', '');
        $appUrl = (string) $this->config->get('app.url', '');
        return hash('sha256', $host . '|' . $dbName . '|' . $appUrl);
    }

    public function countActiveUsers(): int
    {
        if (!$this->db->hasTable('users')) {
            return 0;
        }

        // Licenční místa se přepočítávají na každý request, ale mění se jen při
        // zásahu do users/roles — a ten cache invaliduje na úrovni PDO.
        return (int) $this->cache->remember(
            EntityCache::GROUP_USER,
            'license:active_users',
            fn (): int => $this->queryActiveUsers(),
        );
    }

    private function queryActiveUsers(): int
    {
        // Aktivní uživatelé s rolí != readonly (a != client — portálové účty
        // zákazníků nejsou provozní licenční místa). Deaktivované se nepočítají.
        // Přes roles JOIN, protože vlastní staff role mají legacy `role`='readonly'
        // (coarse bucket) — počítat podle legacy sloupce by je chybně vynechalo.
        if ($this->db->hasTable('roles') && $this->db->hasColumn('users', 'role_id')) {
            $sql = "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                     WHERE u.is_active = 1 AND r.role_type <> 'client'
                       AND (r.system_key IS NULL OR r.system_key <> 'readonly')";
            return (int) $this->db->pdo()->query($sql)->fetchColumn();
        }
        $sql = "SELECT COUNT(*) FROM users WHERE is_active = 1 AND role NOT IN ('readonly', 'client')";
        return (int) $this->db->pdo()->query($sql)->fetchColumn();
    }

    private function countCompanies(): int
    {
        if (!$this->db->hasTable('supplier')) {
            return 0;
        }

        return (int) $this->cache->remember(
            EntityCache::GROUP_SUPPLIER,
            'license:companies',
            fn (): int => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn(),
        );
    }

    private function publicKey(): string
    {
        $key = trim((string) $this->config->get('license.public_key', ''));
        return $key !== '' ? $key : self::DEFAULT_PUBLIC_KEY;
    }

    private function appVersion(): string
    {
        $path = Bootstrap::rootDir() . '/VERSION';
        if (is_file($path)) {
            return trim((string) file_get_contents($path));
        }
        return '0.0.0';
    }

    /** @param array<string,mixed> $row */
    private function keyOf(array $row): ?string
    {
        $key = isset($row['license_key']) ? trim((string) $row['license_key']) : '';
        return $key !== '' ? $key : null;
    }

    /**
     * Vytáhne nonce z payloadu (pole nebo JSON string z DB cache).
     *
     * @param array<string,mixed>|string|null $payload
     */
    private function nonceOf(array|string|null $payload): ?string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) {
            return null;
        }
        $nonce = isset($payload['nonce']) ? (string) $payload['nonce'] : '';
        return $nonce !== '' ? $nonce : null;
    }
}
