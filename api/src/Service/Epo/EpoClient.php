<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Podporovaný handoff nepodepsaného XML do interaktivního formuláře EPO.
 *
 * Krátkodobé URL je záměrně pouze návratová hodnota: nesmí se logovat ani ukládat.
 */
final class EpoClient
{
    private const ENDPOINT_BASE = 'https://adisspr.mfcr.cz/dpr';
    private const MAX_RESPONSE_BYTES = 262144;

    private readonly Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'connect_timeout' => 5.0,
            'timeout' => 20.0,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => true,
        ]);
    }

    public function environment(): string
    {
        return 'production';
    }

    /**
     * @return array{url:string,http_status:int,expires_at:string}
     */
    public function createHandoff(string $xml): array
    {
        $endpoint = self::ENDPOINT_BASE . '/epo_podani?otevriFormular=1';
        try {
            $response = $this->http->post($endpoint, [
                'headers' => [
                    'Accept' => 'application/xml, text/xml',
                    'Content-Type' => 'application/octet-stream',
                    'User-Agent' => 'MyUcto-EPO-Handoff/1.0',
                ],
                'body' => $xml,
            ]);
        } catch (GuzzleException) {
            throw new EpoException(
                'epo_unavailable',
                'EPO je dočasně nedostupné. Zkuste předání znovu později.',
                503,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new EpoException(
                'epo_http_error',
                'EPO odmítlo vytvořit formulář.',
                502,
                $status,
            );
        }

        $stream = $response->getBody();
        $body = $stream->read(self::MAX_RESPONSE_BYTES + 1);
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new EpoException(
                'epo_invalid_response',
                'EPO vrátilo neočekávanou odpověď.',
                502,
                $status,
            );
        }

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            throw new EpoException(
                'epo_invalid_response',
                'EPO vrátilo nečitelnou odpověď.',
                502,
                $status,
            );
        }

        $xpath = new \DOMXPath($dom);
        $urlNode = $xpath->query('//*[translate(local-name(), "URL", "url") = "url"]')->item(0);
        $url = trim((string) ($urlNode?->textContent ?? ''));
        if ($url === '') {
            $errors = [];
            foreach ($xpath->query('//*[local-name()="Chyba"]') ?: [] as $node) {
                $text = trim((string) $node->textContent);
                if ($text !== '') {
                    $errors[] = preg_replace('/\s+/u', ' ', $text) ?: $text;
                }
            }
            $message = $errors !== []
                ? implode(' ', array_slice($errors, 0, 3))
                : 'EPO nevytvořilo odkaz na formulář.';
            throw new EpoException('epo_rejected', mb_substr($message, 0, 500), 422, $status);
        }

        $parts = parse_url($url);
        $allowedHost = (string) parse_url(self::ENDPOINT_BASE, PHP_URL_HOST);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $allowedHost
        ) {
            throw new EpoException(
                'epo_invalid_url',
                'EPO vrátilo neplatný odkaz na formulář.',
                502,
                $status,
            );
        }

        return [
            'url' => $url,
            'http_status' => $status,
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
        ];
    }
}
