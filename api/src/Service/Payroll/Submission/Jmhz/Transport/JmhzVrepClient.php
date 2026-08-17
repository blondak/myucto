<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * POX rozhraní VREP/APEP.
 *
 * ── Produkční adresy jsou DOLOŽENÉ, ne odhadnuté ────────────────────────────
 * Dřív tu produkční větev chyběla a pokus končil `jmhz_vrep_production_endpoint_unknown`,
 * protože odhad `https://epodani…` odvozený z testovací adresy by v nejhorším
 * případě odeslal ostré podání na nesprávný cíl a lhůta by uplynula bez
 * povšimnutí. Ten důvod padl — adresy jsou doložené ze čtyř nezávislých zdrojů:
 *
 * 1. **Podávací a dotazovací protokol ČSSZ, verze 1.47 z 11. 2. 2025**, kapitola
 *    „Prostředí → Produkční prostředí → VREP → POX", strana 47 z 51. Uvádí
 *    doslova `https://epodani.cssz.cz/VREP/submission` a `.../VREP/poll`.
 *    Testovací adresy jsou v téže kapitole „Testovací prostředí" na straně 48.
 * 2. **Oficiální stránka ČSSZ „Komunikační kanály e-Podání"**
 *    (<https://www.cssz.gov.cz/komunikacni-kanaly-e-podani>, staženo 17. 8. 2026,
 *    uloženo v `private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/`).
 * 3. **TLS certifikát hostu**: `CN=epodani.cssz.cz, O=Česká správa sociálního
 *    zabezpečení, SERIALNUMBER=00006963` (sériové číslo = IČO ČSSZ), EV od
 *    GeoTrust EV RSA CA G2, platnost 21. 7. 2025 – 22. 8. 2026.
 * 4. **Brána si adresu deklaruje sama**: GET bez těla vrací GovTalk chybovou
 *    obálku s `<ResponseEndPoint PollInterval="60">https://epodani.cssz.cz/VREP/Submission</ResponseEndPoint>`.
 *
 * ── Proč malá počáteční písmena ─────────────────────────────────────────────
 * Brána vrací vlastní adresu s velkým písmenem (`/VREP/Submission`, `/VREP/Poll`),
 * dokumentace i tenhle klient používají malé (`/VREP/submission`). Zvítězila
 * dokumentovaná varianta, protože právě ta je v testovacím prostředí OVĚŘENÁ
 * PROVOZEM (viz `private/Mzdy/19-JMHZ-OVERENO-PROVOZEM.md`) — obě prostředí mají
 * shodný tvar rozhraní, takže co projde na `t-epodani`, projde i na `epodani`.
 * Adresa z potvrzení se navíc nepřebírá naslepo: {@see JmhzAcknowledgementParser}
 * ji jen čte a poll obálku posílá volající na náš vlastní endpoint.
 *
 * Rozdíl mezi prostředími je JEDINÝ prefix `t-`, proto se adresy nesmějí
 * zaměnit ani jedna z druhé odvozovat — obě větve jsou tu proto vypsané zvlášť.
 */
final class JmhzVrepClient
{
    private const ENDPOINTS = [
        'test' => [
            'submission' => 'https://t-epodani.cssz.cz/VREP/submission',
            'poll' => 'https://t-epodani.cssz.cz/VREP/poll',
        ],
        'production' => [
            'submission' => 'https://epodani.cssz.cz/VREP/submission',
            'poll' => 'https://epodani.cssz.cz/VREP/poll',
        ],
    ];

    /**
     * WS (SOAP) rozhraní VREP. Modul ho nepoužívá — jede přes POX — ale je tu
     * zapsané jako doložený údaj z téže kapitoly protokolu (strana 47 pro
     * produkci, strana 48 pro test), aby se příště nehledalo.
     */
    public const WS_ENDPOINTS = [
        'test' => 'https://t-epodani.cssz.cz/VREP/ws/public.svc',
        'production' => 'https://epodani.cssz.cz/VREP/ws/public.svc',
    ];
    private const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;
    private const USER_AGENT = 'MyUcto-JMHZ-VREP/1.0';

    private readonly Client $http;
    private readonly string $environment;

    public function __construct(?Client $http = null, string $environment = 'test')
    {
        $this->environment = $this->normalizeEnvironment($environment);
        $this->http = $http ?? new Client([
            'connect_timeout' => 5.0,
            'timeout' => 60.0,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => true,
        ]);
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function submit(string $envelopeXml): JmhzVrepSubmitResult
    {
        if (trim($envelopeXml) === '') {
            throw new JmhzTransportException(
                'jmhz_vrep_request_empty',
                'Do VREP nelze odeslat prázdnou obálku.',
            );
        }
        $response = $this->post($this->endpoint('submission'), $envelopeXml);

        return new JmhzVrepSubmitResult(
            $response['http_status'],
            $response['content_type'],
            $response['body'],
        );
    }

    /**
     * Tvar poll požadavku není doložený, takže si ho klient nevymýšlí — musí
     * přijít hotový z `JmhzGovTalkEnvelope::pollRequest()`. Klient jen ověří,
     * že opravdu nese ten CorrelationID, na který se ptáme.
     */
    public function poll(string $correlationId, ?string $requestXml = null): JmhzVrepPollResult
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $correlationId) !== 1) {
            throw new JmhzTransportException(
                'jmhz_vrep_correlation_invalid',
                'CorrelationID podání není v přípustném tvaru.',
            );
        }
        if ($requestXml === null || trim($requestXml) === '') {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_unverified',
                'Tvar poll požadavku VREP není doložený; obálku musí dodat volající.',
            );
        }
        if (!str_contains($requestXml, $correlationId)) {
            throw new JmhzTransportException(
                'jmhz_vrep_poll_request_mismatch',
                'Poll obálka neodkazuje na dotazovaný CorrelationID.',
            );
        }
        $response = $this->post($this->endpoint('poll'), $requestXml);

        return new JmhzVrepPollResult(
            $response['http_status'],
            $response['content_type'],
            $response['body'],
            $correlationId,
        );
    }

    /**
     * @return array{body:string,http_status:int,content_type:string}
     */
    private function post(string $url, string $body): array
    {
        try {
            $response = $this->http->post($url, [
                'headers' => [
                    'Accept' => 'application/xml, text/xml',
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'User-Agent' => self::USER_AGENT,
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException) {
            throw new JmhzTransportException(
                'jmhz_vrep_unavailable',
                'VREP je dočasně nedostupné nebo odpověď nebyla doručena.',
            );
        }

        $status = $response->getStatusCode();
        $payload = $response->getBody()->read(self::MAX_RESPONSE_BYTES + 1);
        if ($payload === '' || strlen($payload) > self::MAX_RESPONSE_BYTES) {
            throw new JmhzTransportException(
                'jmhz_vrep_invalid_response',
                'VREP vrátilo prázdnou nebo příliš velkou odpověď.',
                $status,
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new JmhzTransportException(
                'jmhz_vrep_http_error',
                'VREP vrátilo chybu HTTP.',
                $status,
            );
        }

        return [
            'body' => $payload,
            'http_status' => $status,
            'content_type' => strtolower($response->getHeaderLine('Content-Type')),
        ];
    }

    private function endpoint(string $operation): string
    {
        $url = self::ENDPOINTS[$this->environment][$operation] ?? null;
        // Pojistka pro případ, že by někdo přidal prostředí a zapomněl na
        // operaci: raději hlasité odmítnutí než dotaz na prázdnou adresu.
        if (!is_string($url) || !str_starts_with($url, 'https://')) {
            throw new JmhzTransportException(
                'jmhz_vrep_endpoint_missing',
                'Pro tohle prostředí VREP není doložená adresa operace.',
            );
        }

        return $url;
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (!array_key_exists($environment, self::ENDPOINTS)) {
            throw new \InvalidArgumentException('Neplatné prostředí VREP.');
        }

        return $environment;
    }
}
