<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupDashboardForecast
{
    public function __construct(
        private readonly SummaryAction $revenue,
        private readonly PurchaseSummaryAction $costs,
        private readonly OtherItemForecastService $otherItems,
    ) {}

    public function read(Request $request, array $supplier): ?array
    {
        foreach (['invoices', 'purchase_invoices', 'other_items'] as $permission) {
            if (!RequestAuthorization::allows($request, $permission, AccessLevel::READ)) return null;
        }
        $id = (int) $supplier['id'];
        if ($id <= 0 || (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0) !== $id) {
            throw new \InvalidArgumentException('Forecast supplier differs from the resolved request scope.');
        }
        $year = (int) date('Y');
        $revenues = array_column($this->revenue->annualRevenueForecast($id), null, 'currency');
        $costs = array_column($this->costs->annualCostsForecast($id), null, 'currency');
        $otherItems = array_column($this->otherItems->resultImpact($id,
            sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)), null, 'currency');
        $currencies = array_values(array_unique([...array_keys($revenues), ...array_keys($costs), ...array_keys($otherItems)]));
        sort($currencies);
        $rows = [];
        foreach ($currencies as $currency) {
            $revenue = $revenues[$currency] ?? [];
            $cost = $costs[$currency] ?? [];
            $other = $otherItems[$currency] ?? [];
            $modelRevenue = (float) ($revenue['forecast'] ?? 0.0);
            $modelCosts = (float) ($cost['forecast'] ?? 0.0);
            $otherRevenue = (float) ($other['revenue'] ?? 0.0);
            $otherCosts = (float) ($other['costs'] ?? 0.0);
            $annualRevenue = $modelRevenue + $otherRevenue;
            $annualCosts = $modelCosts + $otherCosts;
            $low = (float) ($revenue['forecast_low'] ?? 0.0) + $otherRevenue;
            $high = (float) ($revenue['forecast_high'] ?? 0.0) + $otherRevenue;
            $rows[] = [
                'year' => $year, 'currency' => $currency,
                'revenue_model' => round($modelRevenue, 2), 'costs_model' => round($modelCosts, 2),
                'revenue_current_year' => (float) ($revenue['ytd'] ?? 0.0),
                'costs_current_year' => (float) ($cost['ytd'] ?? 0.0),
                'other_revenue' => round($otherRevenue, 2), 'other_costs' => round($otherCosts, 2),
                'other_posted' => (float) ($other['posted'] ?? 0.0), 'other_draft' => (float) ($other['draft'] ?? 0.0),
                'revenue' => round($annualRevenue, 2), 'costs' => round($annualCosts, 2),
                'profit' => round($annualRevenue - $annualCosts, 2),
                'revenue_low' => round($low, 2), 'revenue_high' => round($high, 2),
                'profit_low' => round($low - $annualCosts, 2), 'profit_high' => round($high - $annualCosts, 2),
            ];
        }
        return $rows;
    }
}
