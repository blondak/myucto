<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portfolio;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Portfolio\GroupDashboardService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupDashboardAction
{
    public function __construct(private readonly GroupDashboardService $dashboard) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request) || !RequestAuthorization::allows($request, 'dashboard.portfolio')) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $q = $request->getQueryParams();
        $section = $q['section'] ?? 'overview';
        $months = filter_var($q['months'] ?? 12, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36]]);
        $weeks = filter_var($q['weeks'] ?? 8, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        if (!is_string($section) || !in_array($section, GroupDashboardService::SECTIONS, true) || $months === false || $weeks === false) {
            return Json::error($response, 'validation_failed', 'Neplatné parametry přehledu.', 422);
        }
        $from = $q['from'] ?? null;
        $to = $q['to'] ?? null;
        if (($from !== null && !is_string($from)) || ($to !== null && !is_string($to))) {
            return Json::error($response, 'validation_failed', 'Neplatné parametry přehledu.', 422);
        }
        try {
            GroupDashboardService::period($months, $from, $to);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'validation_failed', 'Neplatné parametry přehledu.', 422);
        }
        return Json::ok($response, $this->dashboard->dashboard($request, $section, $months, $weeks, $from, $to));
    }
}
