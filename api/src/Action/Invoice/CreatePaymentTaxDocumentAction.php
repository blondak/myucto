<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/payments/{paymentId}/tax-document
 *
 * Vystaví DRAFT daňového dokladu k přijaté platbě zálohové faktury (DUZP = datum
 * platby). Idempotentní — existující nestornovaný doklad k platbě vrátí beze změny.
 */
final class CreatePaymentTaxDocumentAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
        private readonly PaymentTaxDocumentCreator $creator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $payment = $this->payments->findPayment($paymentId);
        if ($payment === null || (int) $payment['invoice_id'] !== $id) {
            return Json::error($response, 'not_found', 'Platba nenalezena.', 404);
        }

        // H1 (Epic F6): DUZP nového daňového dokladu = paid_on platby — datum v uzavřeném
        // období nesmí klient použít k backdatovanému dokladu (účetní 409, admin ?force=1).
        $lock = $this->locks->forDate(SupplierGuard::currentId($request), substr((string) $payment['paid_on'], 0, 10));
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', $id)) {
            return $deny;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $taxDocId = $this->creator->createForPayment($paymentId, (int) ($user['id'] ?? 0));
        } catch (\RuntimeException $e) {
            return Json::error($response, 'tax_document_failed', $e->getMessage(), 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.payment_tax_document_created', $user['id'] ?? null, 'invoice', $taxDocId, [
            'proforma_id' => $id,
            'payment_id'  => $paymentId,
            'amount'      => $payment['amount'],
            'paid_on'     => $payment['paid_on'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'tax_document_id' => $taxDocId,
            'payments'        => $this->payments->listFor($id),
        ]);
    }
}
