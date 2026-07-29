<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Politika pro TSA URL (RFC 3161 časové razítko) — SEC-04.
 *
 * Jediné místo, které zná konfigurační klíč allowlistu, aby validace při ukládání
 * profilu ({@see \MyInvoice\Action\Settings\SigningProfilesAction}) a validace před
 * odesláním ({@see \MyInvoice\Service\Pdf\PdfSigner}) nemohly rozejít.
 *
 * `signing.tsa_allowed_hosts` (pole hostů) je volitelný administrátorský allowlist.
 * Když je prázdný, platí jen obecná pravidla {@see OutboundUrlGuard} (https, veřejná IP,
 * bez userinfo/fragmentu/redirectů). Když je vyplněný, projde pouze přesná shoda hostu.
 *
 * Uložené TSA credentials (Basic auth) se posílají výhradně na cíl, který prošel plnou
 * validací těsně před spojením — nikdy na host z redirectu ani na neveřejnou adresu.
 */
final class TsaUrlPolicy
{
    public const CONFIG_KEY = 'signing.tsa_allowed_hosts';

    /** TSA odpověď je token, ne dokument — 1 MiB je víc než dost. */
    private const MAX_RESPONSE_BYTES = 1024 * 1024;
    private const TIMEOUT = 5;

    /**
     * Oba parametry jsou POVINNÉ záměrně: PHP-DI autowiring optional class-param
     * nikdy nevyplní (ReflectionBasedAutowiring přeskakuje `isOptional()` parametry),
     * takže `?Config $config = null` by znamenalo, že allowlist je trvale prázdný
     * a `signing.tsa_allowed_hosts` mrtvý konfigurační klíč. Povinný parametr je
     * fail-closed — chybějící bind spadne při buildu kontejneru, ne tiše v runtime.
     */
    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly Config $config,
    ) {}

    /** @return list<string> Prázdné pole = allowlist není nakonfigurovaný. */
    public function allowedHosts(): array
    {
        $raw = $this->config->get(self::CONFIG_KEY, []) ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $hosts = [];
        foreach ($raw as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = trim($host);
            }
        }
        return $hosts;
    }

    /**
     * Validace při ukládání podpisového profilu — bez DNS dotazu, aby výpadek
     * resolveru nezablokoval uložení. Plná kontrola proběhne znovu před použitím.
     *
     * @throws OutboundRequestException
     */
    public function assertStorable(string $url): void
    {
        $this->guard->assertSyntax($url, $this->allowedHosts());
    }

    /**
     * POST TimeStampReq na TSA. Před spojením proběhne plná validace včetně
     * rozřešení všech A/AAAA adres; spojení jde na ověřenou IP.
     *
     * @param string|null $basicAuth `user:heslo`, jen pro ověřený cíl
     * @throws OutboundRequestException
     */
    public function requestTimestamp(string $url, string $timeStampReq, ?string $basicAuth = null): OutboundResponse
    {
        return $this->guard->request(
            method: 'POST',
            url: $url,
            headers: [
                'Content-Type' => 'application/timestamp-query',
                'Accept'       => 'application/timestamp-reply',
            ],
            body: $timeStampReq,
            allowedHosts: $this->allowedHosts(),
            timeout: self::TIMEOUT,
            maxBytes: self::MAX_RESPONSE_BYTES,
            basicAuth: $basicAuth,
        );
    }
}
