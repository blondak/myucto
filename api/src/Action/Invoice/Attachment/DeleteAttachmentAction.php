<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice\Attachment;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteAttachmentAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $attId = (int) ($args['attId'] ?? 0);

        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $att = $this->attachments->find($attId, $id);
        if ($att === null) {
            return Json::error($response, 'not_found', 'Příloha nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): auditní stopa — mazání příloh na zamčeném dokladu
        // blokuje jen klienta; přílohy nejsou účetní data, staff projde i v zavřeném období.
        $lock = $this->locks->forInvoice($invoice);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', $id, clientOnly: true)) {
            return $deny;
        }

        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $path = $this->attachments->pathFor($supplierId, $id, (string) $att['filename']);
        if (is_file($path)) {
            @unlink($path);
        }

        $this->attachments->delete($attId, $id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.attachment_deleted', $user['id'] ?? null, 'invoice', $id, [
            'attachment_id' => $attId,
            'original_name' => $att['original_name'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => $attId]);
    }
}
