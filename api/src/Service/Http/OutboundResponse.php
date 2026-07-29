<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

/** Odpověď z {@see OutboundUrlGuard::request()} — tělo je vždy omezené na `maxBytes`. */
final class OutboundResponse
{
    /**
     * @param array<string,string> $headers Hlavičky odpovědi s klíči v lowercase.
     *                                      Guard redirecty nenásleduje, takže volající,
     *                                      který 3xx umí bezpečně obsloužit, potřebuje
     *                                      přečíst `location` (viz FakturoidClient).
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType,
        public readonly array $headers = [],
    ) {}

    /** Hodnota hlavičky (case-insensitive), nebo null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? null;
    }

    /** Content-Type bez parametrů (`application/pdf; charset=…` → `application/pdf`). */
    public function mimeType(): string
    {
        $ct = strtolower(trim($this->contentType));
        $semi = strpos($ct, ';');
        return $semi === false ? $ct : trim(substr($ct, 0, $semi));
    }
}
