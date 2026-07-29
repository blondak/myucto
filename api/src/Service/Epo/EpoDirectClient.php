<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class EpoDirectClient
{
    private const ENDPOINT_BASES = [
        'production' => 'https://adisspr.mfcr.cz/dpr',
        'test' => 'https://zkus.mojedane.gov.cz/dpr',
    ];
    private const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;

    private readonly Client $http;
    private readonly string $environment;

    public function __construct(?Client $http = null, string $environment = 'production')
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

    /**
     * @return array{body:string,http_status:int,content_type:string}
     */
    public function submit(
        string $signedData,
        bool $test,
        ?string $environment = null,
    ): array
    {
        $endpoint = $this->endpointBase($environment) . '/epo_podani' . ($test ? '?test=1' : '');
        return $this->post($endpoint, [
            'headers' => [
                'Accept' => 'application/pkcs7-signature, application/xml, text/xml',
                'Content-Type' => 'application/pkcs7-signature',
                'User-Agent' => 'MyUcto-EPO-Direct/1.0',
            ],
            'body' => $signedData,
        ]);
    }

    /**
     * @return array{body:string,http_status:int,content_type:string}
     */
    public function status(
        string $reference,
        string $password,
        ?string $environment = null,
    ): array
    {
        return $this->post($this->endpointBase($environment) . '/epo_stav', [
            'headers' => [
                'Accept' => 'application/xml, text/xml',
                'User-Agent' => 'MyUcto-EPO-Direct/1.0',
            ],
            'form_params' => ['C' => $reference, 'H' => $password],
        ]);
    }

    /**
     * @return array{body:string,http_status:int,content_type:string}
     */
    public function receiveOffline(
        string $transferId,
        string $password,
        ?string $environment = null,
    ): array
    {
        return $this->post($this->endpointBase($environment) . '/epo_prijeti', [
            'headers' => [
                'Accept' => 'application/pkcs7-signature, application/xml, text/xml',
                'User-Agent' => 'MyUcto-EPO-Direct/1.0',
            ],
            'form_params' => ['C' => $transferId, 'H' => $password],
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{body:string,http_status:int,content_type:string}
     */
    private function post(string $url, array $options): array
    {
        try {
            $response = $this->http->post($url, $options);
        } catch (GuzzleException $e) {
            throw new EpoException(
                'epo_unavailable',
                'EPO je dočasně nedostupné nebo odpověď nebyla doručena.',
                503,
            );
        }

        $status = $response->getStatusCode();
        $stream = $response->getBody();
        $body = $stream->read(self::MAX_RESPONSE_BYTES + 1);
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new EpoException(
                'epo_invalid_response',
                'EPO vrátilo prázdnou nebo příliš velkou odpověď.',
                502,
                $status,
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new EpoException(
                'epo_http_error',
                'EPO vrátilo chybu HTTP.',
                502,
                $status,
            );
        }
        return [
            'body' => $body,
            'http_status' => $status,
            'content_type' => strtolower($response->getHeaderLine('Content-Type')),
        ];
    }

    private function endpointBase(?string $environment): string
    {
        return self::ENDPOINT_BASES[
            $environment === null
                ? $this->environment
                : $this->normalizeEnvironment($environment)
        ];
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (!isset(self::ENDPOINT_BASES[$environment])) {
            throw new \InvalidArgumentException('Neplatné prostředí EPO.');
        }
        return $environment;
    }
}
