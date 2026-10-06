<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Web faktura (trvalý veřejný odkaz) je ve výchozím stavu ZAPNUTÁ; vypíná ji
 * provozovatel přes `invoices.public_links` v cfg.php (ENV
 * MYINVOICE_INVOICE_PUBLIC_LINKS). Je to vlastnost instalace, ne firmy:
 * odkaz má smysl jen tehdy, když je `app.url` dosažitelné od klienta. Server
 * dostupný jen z LAN/VPN by do e-mailů posílal odkazy, které klient neotevře.
 *
 * Vypnuté vypíná celou funkci naráz — odkaz v e-mailu, správu odkazu v detailu
 * faktury i veřejné endpointy. Kdyby zůstaly veřejné endpointy, dřív rozeslané
 * tokeny by dál zpřístupňovaly fakturu, i když ji provozovatel vypnul.
 */
final class InvoicePublicLinkFeature
{
    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return filter_var(
            $this->config->get('invoices.public_links', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) !== false;
    }
}
