<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Mail\SafeLogoPath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/public/approval/{token}/logo — logo dodavatele pro schvalovací stránku.
 *
 * Klíčem je schvalovací TOKEN, ne ID dodavatele: kdo odkaz nemá, logo nestáhne
 * a nejde přes něj procházet firmy na instalaci. Cesta k souboru projde
 * SafeLogoPath (allowlist adresáře, přípony i tvaru názvu), takže ani zmanipulovaný
 * `logo_path` v DB nevytáhne cizí soubor.
 *
 * SVG se servíruje se `sandbox` CSP a `nosniff`: logo se vykresluje v <img>, kde
 * skripty neběží, ale přímé otevření URL by u nahraného SVG jinak byl XSS na naší
 * doméně.
 */
final class PublicApprovalLogoAction
{
    private const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        // Stvrzenka (už rozhodnutý výkaz) má logo ukázat taky — stránka vypadá stejně.
        $invoice = $this->repo->findByApprovalToken($token) ?? $this->repo->findByApprovalReceipt($token);
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        if ($invoice === null || $supplierId <= 0) {
            return Json::error($response, 'not_found', 'Logo není k dispozici.', 404);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT logo_path, email_branding_enabled FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || empty($row['email_branding_enabled'])) {
            return Json::error($response, 'not_found', 'Logo není k dispozici.', 404);
        }

        $path = SafeLogoPath::resolve($row['logo_path'] !== null ? (string) $row['logo_path'] : null, $supplierId);
        if ($path === null || !is_file($path)) {
            return Json::error($response, 'not_found', 'Logo není k dispozici.', 404);
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? null;
        if ($mime === null) {
            return Json::error($response, 'not_found', 'Logo není k dispozici.', 404);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return Json::error($response, 'not_found', 'Logo není k dispozici.', 404);
        }

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody(new Stream($handle));
    }
}
