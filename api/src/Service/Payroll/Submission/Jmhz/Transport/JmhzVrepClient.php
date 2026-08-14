<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * POX rozhraní VREP/APEP. Endpointy testovacího prostředí jsou ověřené,
 * produkční nikoli — proto tu nejsou vůbec a produkční pokus skončí výjimkou.
 * Odhad `https://epodani…` by v nejhorším případě odeslal ostré podání na
 * nesprávný cíl a lhůta by uplynula bez povšimnutí.
 */
final class JmhzVrepClient
{
    private const ENDPOINTS = [
        'test' => [
            'submission' => 'https://t-epodani.cssz.cz/VREP/submission',
            'poll' => 'https://t-epodani.cssz.cz/VREP/poll',
        ],
        'production' => null,
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
        $endpoints = self::ENDPOINTS[$this->environment];
        if ($endpoints === null) {
            throw new JmhzTransportException(
                'jmhz_vrep_production_endpoint_unknown',
                'Produkční endpoint VREP není doložený a nesmí se odhadovat.',
            );
        }

        return $endpoints[$operation];
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
