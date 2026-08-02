<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/approval/{token}
 *
 * Veřejný (bez auth) endpoint — vrací data potřebná pro schvalovací stránku.
 *
 * Token je v invoices.approval_token a po rozhodnutí se nuluje, aby odkaz nešel
 * použít podruhé. Zůstane po něm ale SHA-256 v `approval_receipt_hash` (migrace
 * 1185), takže endpoint vrátí 200 se `state` = approved|rejected a stránka může
 * poděkovat místo červeného „Odkaz není platný" — schvalovatel se do e-mailu
 * běžně vrátí zkontrolovat, co odklikl, a varování u úspěšného schválení vypadá
 * jako porucha. Rozhodnout se přes hash nedá; decide hledá podle `approval_token`.
 *
 * Returns: { state, invoice: {minimal}, work_report, supplier_name, captcha_site_key }
 */
final class PublicApprovalGetAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly WorkReportRepository $workReports,
        private readonly Config $config,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $invoice = $this->repo->findByApprovalToken($token);

        // Zkonzumovaný odkaz — token je pryč, ale jeho hash zůstal. Držitel
        // odkazu je ten, kdo rozhodl, takže vlastní rozhodnutí (i důvod, který
        // sám napsal) mu smíme ukázat zpátky.
        if ($invoice === null) {
            $decided = $this->repo->findByApprovalReceipt($token);
            $status = (string) ($decided['approval_status'] ?? '');
            if ($decided !== null && ($status === 'approved' || $status === 'rejected')) {
                return Json::ok($response, [
                    'state'            => $status,
                    'supplier_name'    => $this->resolveSupplierName($decided),
                    'invoice'          => [
                        'varsymbol' => $decided['varsymbol'],
                        'language'  => $decided['language'],
                    ],
                    'decided_at'       => $decided['approval_decided_at'] ?? null,
                    'rejection_reason' => $status === 'rejected'
                        ? ($decided['approval_rejection_reason'] ?? null)
                        : null,
                ]);
            }
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }

        if ((string) ($invoice['approval_status'] ?? '') !== 'requested') {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }

        $workReport = $this->workReports->findByInvoice((int) $invoice['id']);
        if ($workReport === null) {
            return Json::error($response, 'no_work_report', 'Faktura nemá výkaz práce.', 404);
        }

        $supplierName = $this->resolveSupplierName($invoice);

        $publicInvoice = [
            'id'                  => $invoice['id'],
            'varsymbol'           => $invoice['varsymbol'],
            'invoice_type'        => $invoice['invoice_type'],
            'currency'            => $invoice['currency'],
            'language'            => $invoice['language'],
            'client_company_name' => $invoice['client_company_name'] ?? null,
            'project_name'        => $invoice['project_name'] ?? null,
            'total_with_vat'      => $invoice['total_with_vat'] ?? null,
            'amount_to_pay'       => $invoice['amount_to_pay'] ?? null,
            'requested_at'        => $invoice['approval_requested_at'] ?? null,
        ];

        return Json::ok($response, [
            'state'            => 'requested',
            'invoice'          => $publicInvoice,
            'work_report'      => $workReport,
            'supplier_name'    => $supplierName,
            'captcha_site_key' => (string) $this->config->get('captcha.site_key', ''),
            'captcha_provider' => (string) $this->config->get('captcha.provider', 'none'),
        ]);
    }

    /** Jen omezený set polí — public endpoint, žádné citlivé údaje. */
    private function resolveSupplierName(array $invoice): string
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                $name = (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
                if ($name !== '') return $name;
            }
        }
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?'
        );
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
