<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\PurchaseInvoiceInboxScanner;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/scan-inbox
 *
 * Admin only — rekurzivně projde inbox_dir (z cfg.php), dedup přes SHA-256,
 * z PDF s embedded ISDOC vytvoří draft purchase_invoice (mapper bude v fázi 2c).
 *
 * Body (volitelně): { dry_run: true } → jen vrátí seznam, nezapisuje do DB ani filesystému.
 */
final class ScanInboxAction
{
    public function __construct(
        private readonly PurchaseInvoiceInboxScanner $scanner,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        // H-02: `purchase_invoice.inbox_dir` je cesta do souborového systému.
        // V multi-tenant provozu je skenování čtením mimo vlastní instanci.
        private readonly ManagedModeGuard $managed,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        if (($locked = $this->managed->deny($response, ManagedModeGuard::CAPABILITY_FILESYSTEM_SCAN)) !== null) {
            return $locked;
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = !empty($body['dry_run']);

        $userId = (int) ($user['id'] ?? 0);
        $result = $this->scanner->scan($supplierId, $userId, $dryRun);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.inbox_scanned', $userId, 'purchase_invoice', null, [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed'  => $result['failed'],
            'dry_run' => $dryRun,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $result);
    }
}
