<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP klient licenčního serveru (myucto.cz). Volání jsou JSON POST s timeoutem
 * 5 s; server nemusí při vývoji běžet, proto síťové chyby vyhazujeme jako
 * `LicenseNetworkException` a volající je toleruje (stav se řídí platností tokenu).
 *
 * Endpointy:
 *   POST {server}/api/license/activate
 *   POST {server}/api/license/renew
 *   POST {server}/api/license/deactivate
 *   POST {server}/api/license/upgrade
 *   POST {server}/api/license/cancel-renewal
 */
final class LicenseClient
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Config $config,
        ?LoggerInterface $logger = null,
        private readonly ?Client $http = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param bool $takeover Vynucený přenos vazby z jiné instalace (počítá se do limitu
     *                       přenosů 2/30 dní). Bez něj server u obsazeného klíče vrátí
     *                       `already_bound`.
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function activate(string $licenseKey, string $instanceId, string $fingerprint, string $appVersion, bool $takeover = false): array
    {
        return $this->post('/api/license/activate', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'fingerprint' => $fingerprint,
            'app_version' => $appVersion,
            'takeover'    => $takeover,
        ]);
    }

    /**
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function renew(
        string $licenseKey,
        string $instanceId,
        int $counter,
        ?string $prevNonce,
        int $usersActive,
        int $companiesActive,
        string $appVersion,
    ): array {
        return $this->post('/api/license/renew', [
            'license_key'      => $licenseKey,
            'instance_id'      => $instanceId,
            'counter'          => $counter,
            'prev_nonce'       => $prevNonce,
            'users_active'     => $usersActive,
            'companies_active' => $companiesActive,
            'app_version'      => $appVersion,
        ]);
    }

    /**
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function deactivate(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/license/deactivate', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Vypnutí automatického prodlužování předplatného. NENÍ to deaktivace —
     * licence doběhne do konce zaplaceného období, jen se nestrhne další platba.
     *
     * @return array<string,mixed> {ok,already_cancelled,valid_until,subscription} / {error}
     * @throws LicenseNetworkException
     */
    public function cancelRenewal(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/license/cancel-renewal', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Poměrný doplatek za navýšení počtu uživatelů (quote — jen kalkulace, nestrhává).
     *
     * @return array<string,mixed> {ok,current_users,new_users,amount,currency,period_end} / {error}
     * @throws LicenseNetworkException
     */
    public function upgradeQuote(string $licenseKey, int $users): array
    {
        return $this->post('/api/license/upgrade', [
            'license_key' => $licenseKey,
            'users'       => $users,
            'quote'       => true,
        ]);
    }

    /**
     * Navýšení počtu uživatelů (in-place) — strhne poměrný doplatek z uložené karty.
     * Delší timeout (10 s) — jde o platbu, server může čekat na platební bránu.
     *
     * @return array<string,mixed> {ok,new_users,amount_charged} / {error}
     * @throws LicenseNetworkException
     */
    public function upgrade(string $licenseKey, int $users): array
    {
        return $this->post('/api/license/upgrade', [
            'license_key' => $licenseKey,
            'users'       => $users,
        ], 10);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    private function post(string $path, array $body, int $timeout = 5): array
    {
        $client = $this->http ?? new Client([
            'base_uri'        => rtrim((string) $this->config->get('license.server_url', 'https://myucto.cz'), '/') . '/',
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
            // U .web dev domény lze ověření certifikátu vypnout (license.verify_tls=false).
            'verify'          => (bool) $this->config->get('license.verify_tls', true),
        ]);

        try {
            $response = $client->post(ltrim($path, '/'), ['json' => $body]);
        } catch (GuzzleException $e) {
            $this->logger->info('license.client.network_error', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LicenseNetworkException($e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger->info('license.client.bad_response', ['path' => $path, 'status' => $status]);
            throw new LicenseNetworkException("Neplatná odpověď licenčního serveru (HTTP {$status}).");
        }

        return $data;
    }
}
