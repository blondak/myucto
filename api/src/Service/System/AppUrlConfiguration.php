<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\WebAuthnConfig;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Bezpečný, znovupoužitelný verdikt nad canonical `app.url`.
 *
 * Do výstupu záměrně nikdy nepřenáší původní hodnotu ani její části. `app.url`
 * může omylem obsahovat userinfo, query token nebo jiný citlivý údaj a health
 * endpoint je veřejný. Detail pro uživatele proto tvoří stabilní stav a reason
 * code, ne echo konfigurace ani text výjimky z parseru.
 */
final class AppUrlConfiguration
{
    private const SETUP_PLACEHOLDERS = [
        'http://localhost:8080',
        'https://dev.example.com',
        'https://example.com',
    ];

    public const STATE_MISSING = 'missing';
    public const STATE_INVALID = 'invalid';
    public const STATE_ROUTING_ONLY = 'routing_only';
    public const STATE_WEBAUTHN_READY = 'webauthn_ready';
    public const STATE_HOSTNAME_CONFLICT = 'hostname_conflict';

    public const REASON_MISSING = 'app_url_missing';
    public const REASON_INVALID_ORIGIN = 'app_url_invalid_origin';
    public const REASON_WEBAUTHN_INCOMPATIBLE = 'app_url_webauthn_incompatible';
    public const REASON_VALID = 'app_url_valid';
    public const REASON_HOSTNAME_CONFLICT = 'app_url_hostname_conflict';

    /**
     * @var array{
     *     state:string,
     *     reason_code:string,
     *     routing_compatible:bool,
     *     webauthn_compatible:bool
     * }|null
     */
    private ?array $cached = null;

    public function __construct(
        private readonly Config $config,
        private readonly HostnameNormalizer $hostnames,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *     state:string,
     *     reason_code:string,
     *     routing_compatible:bool,
     *     webauthn_compatible:bool
     * }
     */
    public function status(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $configured = $this->config->get('app.url');
        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return $this->cached = $this->result(
                self::STATE_MISSING,
                self::REASON_MISSING,
                false,
                false,
            );
        }
        if (!is_string($configured)) {
            return $this->cached = $this->result(
                self::STATE_INVALID,
                self::REASON_INVALID_ORIGIN,
                false,
                false,
            );
        }
        $raw = $configured;

        try {
            $parts = parse_url($raw);
        } catch (\ValueError) {
            $parts = false;
        }

        if (!$this->isRoutingOrigin($parts)) {
            return $this->cached = $this->result(
                self::STATE_INVALID,
                self::REASON_INVALID_ORIGIN,
                false,
                false,
            );
        }

        try {
            new WebAuthnConfig($this->config);
        } catch (\InvalidArgumentException) {
            return $this->cached = $this->result(
                self::STATE_ROUTING_ONLY,
                self::REASON_WEBAUTHN_INCOMPATIBLE,
                true,
                false,
            );
        }

        return $this->cached = $this->result(
            self::STATE_WEBAUTHN_READY,
            self::REASON_VALID,
            true,
            true,
        );
    }

    public function needsHealthHostBypass(): bool
    {
        return !$this->status()['routing_compatible'];
    }

    /**
     * Resolver jako jediný zná současně canonical host a uložené tenant domény.
     * Jakmile zjistí kolizi, přepíše syntakticky platný verdict tímto bezpečným
     * stavem. Opakované čtení už znovu neloguje a health nikdy neechoje hostname.
     *
     * @return array{
     *     state:string,
     *     reason_code:string,
     *     routing_compatible:bool,
     *     webauthn_compatible:bool
     * }
     */
    public function hostnameConflictStatus(): array
    {
        if (($this->cached['state'] ?? null) === self::STATE_HOSTNAME_CONFLICT) {
            return $this->cached;
        }

        return $this->cached = $this->result(
            self::STATE_HOSTNAME_CONFLICT,
            self::REASON_HOSTNAME_CONFLICT,
            false,
            false,
        );
    }

    /**
     * Setup smí nahradit jen chybějící/prázdnou hodnotu a známé distribuční
     * placeholdery. Neprázdný chybný origin je explicitní konfigurace: preflight
     * ho ukáže jako problém, ale automaticky ho nepřepíše.
     */
    public function shouldSetupUseDetectedOrigin(): bool
    {
        if ($this->status()['state'] === self::STATE_MISSING) {
            return true;
        }

        $configured = $this->config->get('app.url');
        if (!is_string($configured)) {
            return false;
        }

        return in_array(rtrim($configured, '/'), self::SETUP_PLACEHOLDERS, true);
    }

    /** @param array<string,mixed>|false $parts */
    private function isRoutingOrigin(array|false $parts): bool
    {
        if (!is_array($parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ($path !== '' && $path !== '/')
            || ($port !== null && ($port < 1 || $port > 65535))
        ) {
            return false;
        }

        try {
            $this->hostnames->normalizeRequestHost($host);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     state:string,
     *     reason_code:string,
     *     routing_compatible:bool,
     *     webauthn_compatible:bool
     * }
     */
    private function result(
        string $state,
        string $reasonCode,
        bool $routingCompatible,
        bool $webAuthnCompatible,
    ): array {
        $result = [
            'state' => $state,
            'reason_code' => $reasonCode,
            'routing_compatible' => $routingCompatible,
            'webauthn_compatible' => $webAuthnCompatible,
        ];

        if (!$routingCompatible) {
            // Nikdy sem nepřidávat původní app.url ani odvozené části. Chybná
            // hodnota může obsahovat heslo či token a log je samostatný únikový
            // kanál vedle veřejného health payloadu.
            $this->logger->warning('configuration.app_url_unusable', [
                'state' => $state,
                'reason_code' => $reasonCode,
            ]);
        }

        return $result;
    }
}
