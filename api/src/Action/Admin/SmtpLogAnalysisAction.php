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
 * GET /api/admin/smtp-log-analysis
 * Query: ?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&status=&kind=&search=&limit=200&offset=0
 *
 * Analýza logů poštovního serveru — kam co bylo doručeno a kde nastal problém.
 * Formátově nezávislé (viz {@see SmtpLogAnalyzer}); zatím konektor hMailServer.
 * Cesta k logům je v cfg `smtp_log.path`. Admin only.
 */
final class SmtpLogAnalysisAction
{
    public function __construct(private readonly SmtpLogAnalyzer $analyzer) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        if (!$this->analyzer->isEnabled()) {
            return Json::ok($response, [
                'enabled'    => false,
                'connectors' => $this->analyzer->availableConnectors(),
                'reason'     => 'disabled',
            ]);
        }

        $q = $request->getQueryParams();
        $result = $this->analyzer->analyze([
            'date_from' => isset($q['date_from']) ? (string) $q['date_from'] : null,
            'date_to'   => isset($q['date_to']) ? (string) $q['date_to'] : null,
            'status'    => isset($q['status']) ? (string) $q['status'] : null,
            'kind'      => isset($q['kind']) ? (string) $q['kind'] : null,
            'search'    => isset($q['search']) ? (string) $q['search'] : null,
            'limit'     => isset($q['limit']) ? (int) $q['limit'] : 200,
            'offset'    => isset($q['offset']) ? (int) $q['offset'] : 0,
        ]);

        $result['connectors'] = $this->analyzer->availableConnectors();

        return Json::ok($response, $result);
    }
}
