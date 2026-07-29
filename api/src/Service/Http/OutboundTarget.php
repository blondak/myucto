<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

/**
 * Ověřený cíl odchozího požadavku — URL prošla {@see OutboundUrlGuard::validate()}
 * a všechny A/AAAA adresy hostu byly vyhodnoceny jako veřejné.
 *
 * `ips` se předává curlu přes CURLOPT_RESOLVE, aby se mezi kontrolou a spojením
 * nedal podstrčit jiný záznam (DNS rebinding).
 */
final class OutboundTarget
{
    /** @param list<string> $ips */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $ips,
        public readonly bool $hostIsIpLiteral,
    ) {}
}
