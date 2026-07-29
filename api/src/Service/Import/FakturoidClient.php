<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;
use Psr\Log\LoggerInterface;

/**
 * Fakturoid API v3 client — podporuje dva auth flow side-by-side:
 *
 *  1) **OAuth2 Client Credentials** (issue #31, povinné pro účty založené po 2024):
 *     POST https://app.fakturoid.cz/api/v3/oauth/token
 *       Authorization: Basic base64(client_id:client_secret)
 *       Content-Type:  application/x-www-form-urlencoded
 *       Body:          grant_type=client_credentials
 *     → { access_token, expires_in (~7200), token_type: "Bearer" }
 *     Bearer token se cachuje v `supplier.fakturoid_access_token_enc` + expires_at.
 *
 *  2) **Legacy BasicAuth** (personal API token + email — pro starší účty):
 *     Authorization: Basic base64(email:api_key)
 *
 * URL pattern: https://app.fakturoid.cz/api/v3/accounts/{slug}/...
 * Priorita: pokud má supplier `fakturoid_client_id` → OAuth2; jinak BasicAuth.
 * User-Agent: REQUIRED header (jinak 403) — `MyUcto.cz/<version> (radek@hulan.cz)`.
 *
 * Rate limit: 240 req/min hard, naše soft 200/min → throttle při >180.
 */
final class FakturoidClient
{
    private const API_HOST = 'app.fakturoid.cz';
    private const API_BASE = 'https://app.fakturoid.cz/api/v3/accounts';
    private const TOKEN_URL = 'https://app.fakturoid.cz/api/v3/oauth/token';
    /** SEC-13 — přesné hosty, ze kterých smíme stahovat přílohy výdajů (fail-closed). */
    private const ATTACHMENT_HOSTS = ['app.fakturoid.cz', 'files.fakturoid.cz'];
    /** Strop staženého souboru přílohy (scan dokladu se do 20 MiB vejde). */
    private const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;
    private const USER_AGENT = 'MyUcto.cz Import (https://github.com/radekhulan/myucto; radek@hulan.cz)';
    private const TIMEOUT = 30;
    private const RATE_LIMIT_THRESHOLD = 180; // req/min
    /** Fixní velikost stránky Fakturoid API v3 (invoices/expenses/subjects). */
    private const PAGE_SIZE = 40;

    private Client $http;
    /** @var array<int, list<int>>  supplier_id → list timestamps (rolling 60s) */
    private array $requestLog = [];

    /**
     * `$urlGuard` i `$config` jsou POVINNÉ (SEC-13): PHP-DI autowiring optional
     * class-param nikdy nevyplní, takže `?Config $config = null` by znamenalo,
     * že `import.fakturoid.attachment_hosts` je mrtvý klíč a záchranná brzda
     * (doplnění storage hostu bez zásahu do kódu) by nefungovala.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
        private readonly OutboundUrlGuard $urlGuard,
        private readonly Config $config,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * Načti všechny dostupné credentials pro daný supplier.
     *
     * Vrátí asociativní pole s těmito klíči (každý nullable):
     *   - slug          — account slug (povinné pro API path)
     *   - email         — legacy BasicAuth username (jen pro legacy flow)
     *   - api_key       — legacy BasicAuth password (jen pro legacy flow)
     *   - client_id     — OAuth2 client_id (jen pro OAuth2 flow)
     *   - client_secret — OAuth2 client_secret (jen pro OAuth2 flow)
     *
     * Vrátí null pokud není nastaven ani slug, ani žádný auth materiál.
     *
     * @return array{slug:string, email:?string, api_key:?string, client_id:?string, client_secret:?string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_slug, fakturoid_email, fakturoid_api_key_enc,
                    fakturoid_client_id, fakturoid_client_secret_enc
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['fakturoid_slug'])) {
            return null;
        }
        $slug = (string) $row['fakturoid_slug'];

        $apiKey = null;
        if (!empty($row['fakturoid_api_key_enc'])) {
            try {
                $apiKey = $this->crypto->decrypt((string) $row['fakturoid_api_key_enc']);
            } catch (\Throwable $e) {
                $this->logger->error('Fakturoid api_key decryption failed', ['supplier_id' => $supplierId]);
            }
        }

        $clientSecret = null;
        if (!empty($row['fakturoid_client_secret_enc'])) {
            try {
                $clientSecret = $this->crypto->decrypt((string) $row['fakturoid_client_secret_enc']);
            } catch (\Throwable $e) {
                $this->logger->error('Fakturoid client_secret decryption failed', ['supplier_id' => $supplierId]);
            }
        }

        $hasOAuth = !empty($row['fakturoid_client_id']) && $clientSecret !== null && $clientSecret !== '';
        $hasBasic = !empty($row['fakturoid_email']) && $apiKey !== null && $apiKey !== '';
        if (!$hasOAuth && !$hasBasic) {
            return null;
        }

        return [
            'slug'          => $slug,
            'email'         => !empty($row['fakturoid_email']) ? (string) $row['fakturoid_email'] : null,
            'api_key'       => $apiKey,
            'client_id'     => !empty($row['fakturoid_client_id']) ? (string) $row['fakturoid_client_id'] : null,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * Zda má supplier nastavený OAuth2 flow (priorita před BasicAuth).
     */
    public function hasOAuthCredentials(int $supplierId): bool
    {
        $creds = $this->getCredentials($supplierId);
        return $creds !== null
            && $creds['client_id'] !== null && $creds['client_id'] !== ''
            && $creds['client_secret'] !== null && $creds['client_secret'] !== '';
    }

    /**
     * Set legacy BasicAuth credentials (email + personal API token).
     * Maže OAuth2 token cache, aby další request nepřežil za starou identitu.
     */
    public function setCredentials(int $supplierId, string $slug, string $email, string $apiKey): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = ?, fakturoid_email = ?, fakturoid_api_key_enc = ?,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$slug ?: null, $email ?: null, $enc, $supplierId]);
    }

    /**
     * Set OAuth2 credentials (client_id + client_secret). Slug zůstává sdílený.
     * Maže OAuth2 token cache, aby další request fetch fresh token.
     */
    public function setOAuthCredentials(int $supplierId, string $slug, string $clientId, string $clientSecret): void
    {
        $enc = $clientSecret === '' ? null : $this->crypto->encrypt($clientSecret);
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = ?, fakturoid_client_id = ?, fakturoid_client_secret_enc = ?,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$slug ?: null, $clientId ?: null, $enc, $supplierId]);
    }

    /**
     * Vyčistí všechny Fakturoid credentials (BasicAuth i OAuth2 + token cache).
     */
    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = NULL, fakturoid_email = NULL, fakturoid_api_key_enc = NULL,
                    fakturoid_client_id = NULL, fakturoid_client_secret_enc = NULL,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$supplierId]);
    }

    /**
     * Test connectivity — GET /account.json (jednoduchý endpoint, vrací jméno účtu).
     * Vrací stejnou strukturu pro BasicAuth i OAuth2.
     */
    public function testConnection(int $supplierId): array
    {
        try {
            $creds = $this->getCredentials($supplierId);
            if ($creds === null) {
                return ['ok' => false, 'error' => 'Credentials nenastaveny'];
            }
            $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/account.json';
            $this->throttle($supplierId);
            $resp = $this->http->get($url, [
                'headers' => $this->authHeaders($supplierId, $creds),
            ]);
            $code = $resp->getStatusCode();
            if ($code !== 200) {
                return ['ok' => false, 'error' => "HTTP {$code}: " . substr((string) $resp->getBody(), 0, 200)];
            }
            $data = json_decode((string) $resp->getBody(), true);
            return ['ok' => true, 'account_name' => $data['name'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /subjects.json (nebo invoices.json, expenses.json) s pagination.
     * Fakturoid používá Link header pro next page (nikoliv page/total v body).
     *
     * @return array{items: list<array<string,mixed>>, next_page: ?string}
     */
    public function get(int $supplierId, string $endpoint, int $page = 1, array $extraQuery = []): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            throw new \RuntimeException('Fakturoid credentials nejsou nastaveny.');
        }
        $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/' . ltrim($endpoint, '/');
        $query = array_merge(['page' => $page], $extraQuery);

        $this->throttle($supplierId);
        $resp = $this->http->get($url, [
            'headers' => $this->authHeaders($supplierId, $creds),
            'query'   => $query,
        ]);
        $code = $resp->getStatusCode();

        // OAuth2 token mid-flight expired — vyhoď cache + retry once
        if ($code === 401 && $this->isUsingOAuth($creds)) {
            $this->logger->info('Fakturoid 401 — refreshing OAuth2 token', ['supplier_id' => $supplierId, 'endpoint' => $endpoint]);
            $this->invalidateToken($supplierId);
            $resp = $this->http->get($url, [
                'headers' => $this->authHeaders($supplierId, $creds),
                'query'   => $query,
            ]);
            $code = $resp->getStatusCode();
        }

        if ($code === 429) {
            // Hit rate limit — sleep podle Retry-After + retry once
            $retry = (int) ($resp->getHeader('Retry-After')[0] ?? 5);
            $this->logger->info('Fakturoid 429 — sleeping', ['retry_after' => $retry]);
            sleep(min($retry, 30));
            $resp = $this->http->get($url, ['headers' => $this->authHeaders($supplierId, $creds), 'query' => $query]);
            $code = $resp->getStatusCode();
        }
        if ($code !== 200) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} failed (HTTP {$code}): " . substr((string) $resp->getBody(), 0, 200));
        }
        $body = (string) $resp->getBody();
        $items = json_decode($body, true);
        if (!is_array($items)) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} returned invalid JSON.");
        }
        return ['items' => $items, 'next_page' => $this->parseNextPage($resp->getHeader('Link'))];
    }

    /**
     * Generator přes všechny stránky.
     *
     * @return iterable<array<string,mixed>>
     */
    public function getAll(int $supplierId, string $endpoint, array $extraQuery = []): iterable
    {
        $page = 1;
        do {
            $res = $this->get($supplierId, $endpoint, $page, $extraQuery);
            foreach ($res['items'] as $item) {
                yield $item;
            }
            // Fakturoid API v3 neposílá RFC 5988 Link header (oproti komentáři u parseNextPage)
            // — `next_page` je vždy null, takže původní podmínka ukončila smyčku po první stránce.
            // Pokračujeme tedy dokud dostáváme plnou stránku (Fakturoid používá per_page=40).
            $hasMore = count($res['items']) >= self::PAGE_SIZE;
            $page++;
        } while ($hasMore);
    }

    /**
     * Stáhne Fakturoidem vygenerované PDF vydané faktury (GET …/invoices/{id}/download.pdf).
     * Vrátí binární obsah, nebo null (204 = PDF se ještě generuje, nebo chyba).
     */
    public function downloadInvoicePdf(int $supplierId, int $invoiceId): ?string
    {
        return $this->binaryGet($supplierId, 'invoices/' . $invoiceId . '/download.pdf');
    }

    /**
     * Stáhne přílohu výdaje (originální doklad od dodavatele). Fakturoid vrací
     * v expense JSON pole `attachment` jako absolutní URL — tedy hodnotu, kterou
     * neurčujeme my, ale poskytovatel.
     *
     * SEC-13: proto NEJDE přes {@see binaryGet} (Guzzle + Authorization hlavička),
     * ale přes {@see OutboundUrlGuard}:
     *   - host musí být na allowlistu Fakturoid download hostů,
     *   - Authorization se posílá jen na vlastní API origin (jinde by šlo o exfiltraci
     *     OAuth/Basic tokenu při kompromitované odpovědi poskytovatele),
     *   - žádné redirecty, jen https, jen veřejné IP, spojení na ověřenou IP,
     *   - tělo se stahuje streamovaně s tvrdým limitem,
     *   - výsledek musí být PDF podle Content-Type i magic bytes.
     */
    public function downloadAttachment(int $supplierId, string $attachmentUrl): ?string
    {
        if ($attachmentUrl === '') return null;

        $creds = $this->getCredentials($supplierId);
        if ($creds === null) return null;

        try {
            $target = $this->urlGuard->validate($attachmentUrl, $this->attachmentHosts());
        } catch (OutboundRequestException $e) {
            $this->logger->warning('Fakturoid: příloha odmítnuta guardem', [
                'supplier_id' => $supplierId,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }

        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/pdf'];
        // Authorization výhradně na vlastní API origin — nikdy na CDN/storage host.
        if (self::mayReceiveAuthorization($target->host)) {
            $headers = $this->authHeaders($supplierId, $creds) + $headers;
            $headers['Accept'] = 'application/pdf';
        }

        $this->throttle($supplierId);
        try {
            $resp = $this->urlGuard->request(
                method: 'GET',
                url: $attachmentUrl,
                headers: $headers,
                allowedHosts: $this->attachmentHosts(),
                timeout: self::TIMEOUT,
                maxBytes: self::MAX_ATTACHMENT_BYTES,
            );
        } catch (OutboundRequestException $e) {
            $this->logger->warning('Fakturoid: stažení přílohy selhalo', [
                'supplier_id' => $supplierId,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }

        if ($resp->status !== 200 || $resp->body === '') return null;

        if (!self::isAcceptablePdf($resp->body, $resp->mimeType())) {
            $this->logger->warning('Fakturoid: příloha není platné PDF', [
                'supplier_id' => $supplierId,
                'content_type' => $resp->mimeType(),
            ]);
            return null;
        }

        return $resp->body;
    }

    /**
     * Smí na tento host odejít Authorization hlavička? Jen vlastní API origin —
     * storage/CDN host by dostal OAuth Bearer nebo Basic token k účtu (SEC-13).
     */
    public static function mayReceiveAuthorization(string $host): bool
    {
        return rtrim(strtolower(trim($host)), '.') === self::API_HOST;
    }

    /**
     * Obsah přílohy je přijatelný jen jako PDF — Content-Type musí sedět (prázdný
     * a `application/octet-stream` tolerujeme, servery bývají nepřesné) a magic bytes
     * musí být `%PDF-` po odstranění BOM/whitespace.
     */
    public static function isAcceptablePdf(string $body, string $mime): bool
    {
        if ($body === '' || strlen($body) > self::MAX_ATTACHMENT_BYTES) return false;
        $mime = strtolower(trim($mime));
        if ($mime !== '' && $mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
            return false;
        }
        return str_starts_with(ltrim($body, "\x00\x09\x0a\x0d\x20\xef\xbb\xbf"), '%PDF-');
    }

    /** @return list<string> Vestavěné download hosty (bez konfiguračních doplňků). */
    public static function defaultAttachmentHosts(): array
    {
        return self::ATTACHMENT_HOSTS;
    }

    /**
     * Přesné hosty, ze kterých smíme stahovat přílohy. Default jsou domény Fakturoidu;
     * `import.fakturoid.attachment_hosts` umožní doplnit storage host bez zásahu do kódu.
     * Fail-closed: cokoli mimo seznam se nestahuje.
     *
     * @return list<string>
     */
    private function attachmentHosts(): array
    {
        $hosts = self::ATTACHMENT_HOSTS;
        $extra = $this->config->get('import.fakturoid.attachment_hosts', []) ?? [];
        if (is_string($extra)) {
            $extra = preg_split('/[\s,]+/', $extra) ?: [];
        }
        if (is_array($extra)) {
            foreach ($extra as $host) {
                if (is_string($host) && trim($host) !== '') {
                    $hosts[] = trim($host);
                }
            }
        }
        return array_values(array_unique($hosts));
    }

    /**
     * GET binárního obsahu z vlastního API (PDF vydané faktury). Endpoint je vždy
     * relativní slug — absolutní URL od poskytovatele sem nesmí (viz downloadAttachment).
     * 401 → refresh + retry.
     *
     * SEC-13 (sesterská cesta k downloadAttachment): jde přes {@see OutboundUrlGuard},
     * ne přes syrový Guzzle. Guzzle totiž ve výchozím stavu následuje redirecty —
     * odpověď Fakturoidu (nebo kohokoli, kdo by jeho odpověď ovlivnil) by tak mohla
     * poslat naši Authorization hlavičku na cizí nebo vnitřní host. Guard navíc
     * vynutí https, veřejnou IP, spojení na ověřenou IP a tvrdý strop velikosti.
     */
    private function binaryGet(int $supplierId, string $endpoint): ?string
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) return null;

        $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/' . ltrim($endpoint, '/');

        $headers = $this->authHeaders($supplierId, $creds);
        $headers['Accept'] = '*/*'; // ne JSON — chceme binárku

        $resp = $this->guardedApiGet($supplierId, $url, $headers);
        if ($resp === null) return null;

        if ($resp->status === 401 && $this->isUsingOAuth($creds)) {
            $this->invalidateToken($supplierId);
            $headers = $this->authHeaders($supplierId, $creds);
            $headers['Accept'] = '*/*';
            $resp = $this->guardedApiGet($supplierId, $url, $headers);
            if ($resp === null) return null;
        }

        // Fakturoid může PDF servírovat přesměrováním na storage host. Guard redirecty
        // zásadně nenásleduje (hop by obešel allowlist i IP kontrolu), takže jediný hop
        // obsloužíme ručně a znovu přes guard: cíl musí projít allowlistem download
        // hostů a Authorization se na něj UŽ NEPOSÍLÁ (jinak bychom token k účtu
        // vystavili CDN/storage provozovateli).
        if (in_array($resp->status, [301, 302, 303, 307, 308], true)) {
            $location = $resp->header('location');
            if ($location === null || $location === '') return null;

            $resp = $this->followBinaryRedirect($supplierId, $location);
            if ($resp === null) return null;
        }

        if ($resp->status !== 200) return null; // 204 = PDF not ready, 404 = bez přílohy
        return $resp->body !== '' ? $resp->body : null;
    }

    /**
     * Jediný povolený hop za binárkou. Cíl prochází plnou validací guardu proti
     * allowlistu download hostů; bez Authorization hlavičky a bez dalšího hopu.
     */
    private function followBinaryRedirect(int $supplierId, string $location): ?OutboundResponse
    {
        $this->throttle($supplierId);
        try {
            return $this->urlGuard->request(
                method: 'GET',
                url: $location,
                headers: ['User-Agent' => self::USER_AGENT, 'Accept' => '*/*'],
                allowedHosts: $this->attachmentHosts(),
                timeout: self::TIMEOUT,
                maxBytes: self::MAX_ATTACHMENT_BYTES,
            );
        } catch (OutboundRequestException $e) {
            $this->logger->warning('Fakturoid: přesměrování binárky odmítnuto guardem', [
                'supplier_id' => $supplierId,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * GET na vlastní API origin přes guard. Allowlist je tvrdě jen `API_HOST` —
     * na tuhle cestu chodí výhradně URL, které skládáme my z API_BASE, takže
     * konfigurační doplňky download hostů se sem záměrně nepromítají.
     *
     * @param array<string,string> $headers
     */
    private function guardedApiGet(int $supplierId, string $url, array $headers): ?OutboundResponse
    {
        $this->throttle($supplierId);
        try {
            return $this->urlGuard->request(
                method: 'GET',
                url: $url,
                headers: $headers,
                allowedHosts: [self::API_HOST],
                timeout: self::TIMEOUT,
                maxBytes: self::MAX_ATTACHMENT_BYTES,
            );
        } catch (OutboundRequestException $e) {
            $this->logger->warning('Fakturoid: binární GET selhal', [
                'supplier_id' => $supplierId,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sestaví auth header podle dostupných credentials.
     * Priorita: OAuth2 (pokud client_id + client_secret) → BasicAuth.
     *
     * @param array{slug:string, email:?string, api_key:?string, client_id:?string, client_secret:?string} $creds
     * @return array<string,string>
     */
    private function authHeaders(int $supplierId, array $creds): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'application/json',
        ];

        if ($this->isUsingOAuth($creds)) {
            $token = $this->getAccessToken($supplierId, $creds);
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            // Legacy BasicAuth — email + personal API token
            if ($creds['email'] === null || $creds['api_key'] === null) {
                throw new \RuntimeException('Fakturoid credentials neúplné (chybí email/api_key i client_id/client_secret).');
            }
            $basic = base64_encode($creds['email'] . ':' . $creds['api_key']);
            $headers['Authorization'] = 'Basic ' . $basic;
        }

        return $headers;
    }

    /**
     * @param array{client_id:?string, client_secret:?string, ...} $creds
     */
    private function isUsingOAuth(array $creds): bool
    {
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    /**
     * Vrátí valid OAuth2 Bearer token. Pokud je cached a expires_at > now + 60s, vrátí z cache.
     * Jinak fetch fresh + uloží encrypted cache.
     *
     * @param array{client_id:?string, client_secret:?string, ...} $creds
     */
    public function getAccessToken(int $supplierId, ?array $creds = null): string
    {
        if ($creds === null) {
            $creds = $this->getCredentials($supplierId);
            if ($creds === null || !$this->isUsingOAuth($creds)) {
                throw new \RuntimeException('Fakturoid OAuth2 credentials nejsou nastaveny.');
            }
        }

        // Pokus o cache hit
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_access_token_enc, fakturoid_access_token_expires_at
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['fakturoid_access_token_enc']) && !empty($row['fakturoid_access_token_expires_at'])) {
            $expires = strtotime((string) $row['fakturoid_access_token_expires_at']);
            if ($expires !== false && $expires > time() + 60) {
                try {
                    return $this->crypto->decrypt((string) $row['fakturoid_access_token_enc']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Fakturoid token cache decrypt failed — refreshing', ['supplier_id' => $supplierId]);
                }
            }
        }

        return $this->fetchToken($supplierId, (string) $creds['client_id'], (string) $creds['client_secret']);
    }

    /**
     * POST /api/v3/oauth/token — OAuth2 Client Credentials grant.
     */
    private function fetchToken(int $supplierId, string $clientId, string $clientSecret): string
    {
        $this->throttle($supplierId);
        $resp = $this->http->post(self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'User-Agent'    => self::USER_AGENT,
                'Accept'        => 'application/json',
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        if ($code !== 200) {
            $this->logger->error('Fakturoid OAuth2 token request failed', [
                'supplier_id' => $supplierId,
                'http_code'   => $code,
                'body'        => substr($body, 0, 500),
            ]);
            // invalid_client = nejčastěji špatný ZDROJ credentials: Fakturoid v3 rozlišuje
            // "Client Credentials Flow" (Nastavení → Uživatelský profil → API v3 přístupové
            // údaje — tohle používáme) a "Authorization Code Flow" (OAuth integrace). Credentials
            // ze stránky OAuth integrací s grant_type=client_credentials Fakturoid odmítne.
            $hint = str_contains($body, 'invalid_client')
                ? ' — Zkontroluj, že Client ID i Secret pocházejí z "Nastavení → Uživatelský profil →'
                  . ' API v3 přístupové údaje" (Client Credentials Flow), NE ze stránky OAuth integrací.'
                : '';
            throw new \RuntimeException("Fakturoid OAuth2 token request failed (HTTP {$code}): " . substr($body, 0, 200) . $hint);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Fakturoid OAuth2 response neobsahuje access_token.');
        }
        $accessToken = (string) $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 7200); // default 2h per docs
        $expiresAt = (new \DateTimeImmutable('+' . $expiresIn . ' seconds'))->format('Y-m-d H:i:s');

        // Cache do DB (šifrovaný)
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_access_token_enc = ?, fakturoid_access_token_expires_at = ?
              WHERE id = ?'
        )->execute([$this->crypto->encrypt($accessToken), $expiresAt, $supplierId]);

        return $accessToken;
    }

    private function invalidateToken(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$supplierId]);
    }

    /**
     * Fakturoid používá RFC 5988 Link header pro pagination.
     * Format: <url>; rel="next", <url>; rel="last"
     */
    private function parseNextPage(array $linkHeaders): ?string
    {
        $line = $linkHeaders[0] ?? null;
        if ($line === null) return null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private function throttle(int $supplierId): void
    {
        $now = time();
        $log = $this->requestLog[$supplierId] ?? [];
        $log = array_values(array_filter($log, fn ($t) => $t > $now - 60));
        if (count($log) >= self::RATE_LIMIT_THRESHOLD) {
            $this->logger->info('Fakturoid throttle — sleep 1s', ['supplier_id' => $supplierId]);
            sleep(1);
        }
        $log[] = $now;
        $this->requestLog[$supplierId] = $log;
    }
}
