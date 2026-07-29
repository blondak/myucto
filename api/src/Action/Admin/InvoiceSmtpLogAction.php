<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Mail\LogAnalysis\SmtpLogAnalyzer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SMTP log analýza vázaná na konkrétní fakturu (box v detailu faktury).
 *
 *  - GET /api/admin/smtp-log-analysis/status        → { enabled }  (levný probe pro UI)
 *  - GET /api/admin/invoices/{id}/smtp-log          → doručení dané faktury z logu MTA
 *
 * Admin only — analýza logu může odhalit i jinou poštu na serveru.
 */
final class InvoiceSmtpLogAction
{
    public function __construct(private readonly SmtpLogAnalyzer $analyzer) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        return Json::ok($response, ['enabled' => $this->analyzer->isEnabled()]);
    }

    public function forInvoice(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid', 'Neplatné ID faktury.', 400);
        }
        return Json::ok($response, $this->analyzer->analyzeForInvoice($id));
    }

    private function isAdmin(Request $request): bool
    {
        return RequestAuthorization::isSuperadmin($request);
    }
}
