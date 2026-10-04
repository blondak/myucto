<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/** Ověřený cíl Ollamy — výstup {@see OllamaEndpointGuard::resolve()}. */
final readonly class OllamaEndpoint
{
    public function __construct(
        public string $baseUrl,  // scheme://host[:port], bez koncového lomítka
        public string $host,     // bez hranatých závorek u IPv6
        public int $port,
        public string $ip,       // ověřená adresa, na kterou se klient připojí
        public string $region,   // 'eu' = loopback/privátní síť, jinak 'us'
    ) {}

    /** Hodnota pro CURLOPT_RESOLVE; null, když je host přímo IP literál. */
    public function curlResolve(): ?string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $ip = str_contains($this->ip, ':') ? '[' . $this->ip . ']' : $this->ip;
        return $this->host . ':' . $this->port . ':' . $ip;
    }
}
