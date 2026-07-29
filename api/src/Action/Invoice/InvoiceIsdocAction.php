<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/isdoc — ISDOC XML jedné vystavené faktury.
 *
 * Doplňuje symetrii s /api/purchase-invoices/{id}/isdoc (přijaté ho mají,
 * vystavené dosud jen hromadně přes admin export). Pro integrace: účetní
 * software zákazníka si stáhne strojově čitelnou fakturu bez ZIP obalu.
 *
 * Supplier scope: faktura musí patřit aktuálnímu dodavateli (X-Supplier-Id),
 * jinak 404 — stejně jako GetInvoiceAction.
 */
final class InvoiceIsdocAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly IsdocExporter $isdoc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExchangeRateApplier $rateApplier,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Draft nemá číslo (varsymbol) — ISDOC dává smysl až pro vystavený doklad.
        if (($invoice['status'] ?? '') === 'draft') {
            return Json::error($response, 'validation_failed', 'Koncept nelze exportovat do ISDOC — nejdřív fakturu vystavte.', 400);
        }
        // Stornovanou fakturu (status=cancelled, viz CancelInvoiceAction) hromadný
        // export za období (ExportAction::findInvoiceIds) záměrně vynechává —
        // stejné pravidlo platí i tady, ať účetní software nedostane tentýž doklad
        // jinak podle toho, kterým z obou endpointů ho stáhne.
        if (($invoice['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'invalid_state', 'Stornovanou fakturu nelze exportovat do ISDOC.', 409);
        }

        // Backfill kurzu (cache → ČNB → last known) pro cizí měnu bez zafixovaného
        // kurzu — stejně jako GetInvoiceAction / PdfAction; jinak IsdocExporter::buildXml
        // padá na kurz 1.0 u starších dokladů bez uloženého exchange_rate.
        if (
            (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
            && empty($invoice['exchange_rate'])
        ) {
            $demo = DemoReadOnlyMiddleware::enabled($request);
            $resolved = $this->rateApplier->ensureRate($id, persist: !$demo);
            $invoice = $demo
                ? $this->rateApplier->applyResolvedToInvoiceData($invoice, $resolved)
                : $this->repo->find($id);
        }

        try {
            $xml = $this->isdoc->buildXml($invoice);
        } catch (\Throwable $e) {
            return Json::error($response, 'export_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.isdoc_exported', isset($user['id']) ? (int) $user['id'] : null, 'invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'), $sid);

        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($invoice['varsymbol'] ?? $id));
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="Faktura-' . $vs . '.isdoc"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
