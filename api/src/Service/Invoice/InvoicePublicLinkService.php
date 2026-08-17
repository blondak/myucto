<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Tenant\TenantUrlResolver;

/**
 * Jediný vlastník URL formátu web faktury `/invoice/{token}` (vzor
 * WorkReportLinkService::publicUrl) — používá ho management endpoint
 * (PublicLinkAction) i e-mail (InvoiceEmailVarsBuilder). Cesta musí ladit
 * s Vue routou ve web/src/router/index.ts.
 */
final class InvoicePublicLinkService
{
    public function __construct(
        private readonly Config $config,
        private readonly InvoiceRepository $invoices,
        private readonly ?TenantUrlResolver $tenantUrls = null,
    ) {}

    /**
     * URL pro daný token. Bez nakonfigurovaného app.url vrací relativní cestu —
     * UI („kopírovat odkaz") tak vždy něco dostane; e-mailová cesta absolutnost
     * hlídá v ensureUrl().
     */
    public function url(string $token, int $supplierId = 0): string
    {
        if ($this->tenantUrls !== null && $supplierId > 0) {
            return $this->tenantUrls->forSupplier($supplierId, 'public_links', '/invoice/' . $token);
        }
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/invoice/' . $token;
    }

    /**
     * Absolutní URL web faktury pro e-mail; token vytvoří lazy při prvním
     * použití. Null pro draft (veřejná stránka koncepty nezobrazuje) a bez
     * app.url (relativní odkaz je v e-mailu k ničemu).
     */
    public function ensureUrl(array $invoice): ?string
    {
        if (($invoice['status'] ?? '') === 'draft') {
            return null;
        }
        if (rtrim((string) $this->config->get('app.url', ''), '/') === '') {
            return null;
        }
        $token = (string) ($invoice['public_token'] ?? '');
        if ($token === '') {
            $token = $this->invoices->ensurePublicToken((int) $invoice['id']);
        }
        return $this->url($token, (int) ($invoice['supplier_id'] ?? 0));
    }
}
