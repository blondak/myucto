<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice\Approval;

use MyInvoice\Action\PurchaseInvoice\DownloadPurchaseInvoicePdfAction;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\SafeLogoPath;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseApprovalException;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Schválení přijatého dokladu z e-mailu — veřejné endpointy bez přihlášení.
 *
 *   GET  /api/public/purchase-approval/{token}          — náhled dokladu a stav
 *   GET  /api/public/purchase-approval/{token}/pdf      — PDF dokladu (inline)
 *   GET  /api/public/purchase-approval/{token}/logo     — logo firmy (je-li branding)
 *   POST /api/public/purchase-approval/{token}/decide   — {decision, comment?}
 *
 * Token = 64 hex znaků (256 bitů), v DB jen SHA-256; vyhledání podle hashe
 * a porovnání `hash_equals`. Rozhodnutí je jednorázové (atomický UPDATE nad
 * čekajícím řádkem), platnost odkazu 14 dní. Po rozhodnutí odkaz dál ukazuje
 * výsledek — schvalovatel se k e-mailu běžně vrací. Neznámý token, cizí doména
 * tenantu i neplatný formát = shodné 404, ať nejde poznat, zda odkaz existoval.
 *
 * Rate limit: RateLimitMiddleware (prefix /api/public/purchase-approval/), session
 * výjimka: SessionLockMiddleware::PUBLIC_PATH_PATTERNS. Token se nikdy neloguje.
 */
final class PublicPurchaseApprovalAction
{
    private const LOGO_MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly PurchaseInvoiceApprovalService $service,
        private readonly Connection $db,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly IpMatcher $ipMatcher,
        private readonly DownloadPurchaseInvoicePdfAction $pdf,
    ) {}

    /** @param array<string,string> $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $row = $this->resolve($request, $token);
        if ($row === null) {
            return $this->notFound($response);
        }
        return $this->noStore(Json::ok($response, $this->payload($row, $token)));
    }

    /** @param array<string,string> $args */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $row = $this->resolve($request, (string) ($args['token'] ?? ''));
        if ($row === null || (string) ($row['invoice_pdf_path'] ?? '') === '') {
            return $this->notFound($response);
        }
        // Stejné čtení archivu jako v aplikaci (path traversal guard, case-insensitive
        // na Windows) — jen s firmou a dokladem odvozenými z tokenu, ne z požadavku.
        $scoped = $request
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, (int) $row['supplier_id'])
            ->withQueryParams(['inline' => '1']);
        return ($this->pdf)($scoped, $response, ['id' => (string) $row['purchase_invoice_id']]);
    }

    /** @param array<string,string> $args */
    public function logo(Request $request, Response $response, array $args): Response
    {
        $row = $this->resolve($request, (string) ($args['token'] ?? ''));
        if ($row === null) {
            return $this->notFound($response);
        }
        $supplierId = (int) $row['supplier_id'];
        $path = $this->logoPath($supplierId);
        $ext = $path !== null ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';
        $mime = self::LOGO_MIME[$ext] ?? null;
        if ($path === null || $mime === null || !is_file($path) || ($handle = fopen($path, 'rb')) === false) {
            return $this->notFound($response);
        }
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody(new Stream($handle));
    }

    /** @param array<string,string> $args */
    public function decide(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $row = $this->resolve($request, $token);
        if ($row === null) {
            return $this->notFound($response);
        }
        if ((string) $row['status'] === 'pending' && PurchaseInvoiceApprovalService::isExpired($row)) {
            return $this->noStore(Json::error($response, 'token_expired',
                'Platnost odkazu vypršela. Požádejte účetní o nový, nebo rozhodněte v aplikaci.', 410));
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->service->decide(
                (int) $row['supplier_id'],
                (int) $row['id'],
                (string) ($body['decision'] ?? ''),
                isset($body['comment']) && is_scalar($body['comment']) ? (string) $body['comment'] : null,
                'email',
                null,
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PurchaseApprovalException $e) {
            return $this->noStore(Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus));
        }
        $fresh = $this->service->findByToken($token);
        return $this->noStore(Json::ok($response, $this->payload($fresh ?? $row, $token)));
    }

    /** @return array<string,mixed>|null */
    private function resolve(Request $request, string $token): ?array
    {
        $row = $this->service->findByToken($token);
        if ($row === null || !$this->tenantGuard->allows($request, (int) $row['supplier_id'])) {
            return null;
        }
        return $row;
    }

    /**
     * Jen údaje potřebné k rozhodnutí — žádné interní poznámky, zaúčtování ani
     * údaje jiných schvalovatelů.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function payload(array $row, string $token): array
    {
        $supplierId = (int) $row['supplier_id'];
        $invoiceId = (int) $row['purchase_invoice_id'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.description, pii.quantity, pii.unit, pii.unit_price_without_vat, pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $items = array_map(static fn (array $i): array => [
            'description' => (string) $i['description'],
            'quantity' => (float) $i['quantity'],
            'unit' => $i['unit'] !== null ? (string) $i['unit'] : null,
            'unit_price' => (float) $i['unit_price_without_vat'],
            'total_without_vat' => (float) $i['total_without_vat'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $company = $this->db->pdo()->prepare(
            "SELECT COALESCE(NULLIF(display_name, ''), company_name) AS name, email_branding_enabled, logo_path
               FROM supplier WHERE id = ?"
        );
        $company->execute([$supplierId]);
        $c = $company->fetch(\PDO::FETCH_ASSOC) ?: [];

        $number = (string) (($row['invoice_vendor_number'] ?? '') ?: ($row['invoice_varsymbol'] ?? ''));
        $status = (string) $row['status'];

        return [
            'status' => $status,
            'round' => (int) $row['round'],
            'amount_czk' => (float) $row['amount_czk'],
            'dimension_value' => [
                'code' => (string) $row['dimension_value_code'],
                'name' => (string) $row['dimension_value_name'],
                'type_name' => (string) $row['dimension_type_name'],
            ],
            'approver_name' => (string) (($row['approver_name'] ?? '') ?: ($row['approver_email'] ?? '')),
            'company' => [
                'name' => (string) ($c['name'] ?? ''),
                'logo_url' => $this->logoPath($supplierId) !== null
                    ? '/api/public/purchase-approval/' . $token . '/logo'
                    : null,
            ],
            'invoice' => [
                'document_number' => $number !== '' ? $number : null,
                'supplier_name' => (string) ($row['invoice_vendor_name'] ?? ''),
                'supplier_ico' => $row['invoice_vendor_ic'] !== null ? (string) $row['invoice_vendor_ic'] : null,
                'issue_date' => $row['invoice_issue_date'] ?? null,
                'tax_date' => $row['invoice_tax_date'] ?? null,
                'due_date' => $row['invoice_due_date'] ?? null,
                'currency' => (string) ($row['invoice_currency'] ?? 'CZK'),
                'total_without_vat' => (float) $row['invoice_total_without_vat'],
                'total_vat' => (float) $row['invoice_total_vat'],
                'total_with_vat' => (float) $row['invoice_total_with_vat'],
                'items' => $items,
                'has_pdf' => (string) ($row['invoice_pdf_path'] ?? '') !== '',
            ],
            'decided_at' => $row['decided_at'] ?? null,
            'comment' => $status === 'approved' || $status === 'rejected' ? ($row['comment'] ?? null) : null,
            'expired' => $status === 'pending' && PurchaseInvoiceApprovalService::isExpired($row),
        ];
    }

    private function logoPath(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT logo_path, email_branding_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || empty($row['email_branding_enabled'])) {
            return null;
        }
        return SafeLogoPath::resolve($row['logo_path'] !== null ? (string) $row['logo_path'] : null, $supplierId);
    }

    private function notFound(Response $response): Response
    {
        return $this->noStore(Json::error($response, 'token_invalid_or_expired',
            'Tento odkaz není platný.', 404));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
