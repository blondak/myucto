<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portal;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Epic F6 — klientský portál: GET /api/portal/summary
 *
 * Agregovaný přehled hospodaření aktivní firmy (živě přes CrmAggregationService,
 * žádné nové tabulky/cache). Dostupné všem přihlášeným rolím — klient je primární
 * konzument, účetní má náhled; supplier scope řeší SupplierScopeMiddleware
 * (klient bez membershipu = fail-closed 403 v resolveru).
 *
 * ⚠️ BEZPEČNOSTNÍ INVARIANT (spec §5): response NESMÍ obsahovat žádná jména
 * klientů/odběratelů ani čísla dokladů — jen agregáty, počty a částky. Proto se
 * z CRM služby berou výhradně agregační metody (overview/monthlyHistory/aging/
 * forecast) a KPI řádky se mapují přes explicitní subset klíčů.
 */
final class PortalSummaryAction
{
    /** KPI období dle specu §5 (subset CrmAggregationService::overview). */
    private const KPI_PERIODS = ['current_month', 'last_month', 'ytd', 'prev_year_ytd', 'last_12m'];

    /** Okno pro daňové termíny na portálu (spec §5 — 35 dní dopředu). */
    private const DEADLINE_WINDOW_DAYS = 35;

    public function __construct(
        private readonly Connection $db,
        private readonly CrmAggregationService $crm,
        private readonly DphPriznaniBuilder $dph,
        private readonly \MyInvoice\Repository\DocumentRequestRepository $documentRequests,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, display_name, is_vat_payer FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if ($supplier === []) {
            return Json::error($response, 'not_found', 'Firma nenalezena.', 404);
        }

        $now = new \DateTimeImmutable('today');
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? false);

        $overview = $this->crm->overview($supplierId);
        $kpi = [];
        foreach (self::KPI_PERIODS as $period) {
            $kpi[$period] = array_map($this->kpiRow(...), (array) ($overview[$period] ?? []));
        }
        $kpi['currencies'] = $overview['currencies'] ?? [];

        $name = trim((string) ($supplier['display_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($supplier['company_name'] ?? '');
        }

        return Json::ok($response, [
            'company' => [
                'name'   => $name,
                'period' => [
                    'current_month' => $now->format('Y-m'),
                    'ytd_from'      => $now->format('Y-01-01'),
                    'today'         => $now->format('Y-m-d'),
                ],
            ],
            'kpi'      => $kpi,
            'monthly'  => array_map($this->kpiRow(...), $this->crm->monthlyHistory($supplierId, 12)),
            'cashflow' => [
                'receivables' => $this->crm->agingReceivables($supplierId),
                'payables'    => $this->crm->agingPayables($supplierId),
                'forecast'    => $this->crm->cashFlowForecast($supplierId, 4),
            ],
            'vat' => [
                'is_vat_payer' => $isVatPayer,
                'status'       => $this->vatStatus($supplierId, $isVatPayer, $now),
                'deadlines'    => $this->crm->taxDeadlineItems($supplierId, $now, self::DEADLINE_WINDOW_DAYS),
            ],
            // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — jen agregát (počty),
            // ne obsah požadavků; drží se stejný invariant §5 jako zbytek portálu.
            'document_requests_open' => $this->documentRequests->openCounts($supplierId),
        ]);
    }

    /**
     * Explicitní subset KPI řádku (fakturováno / náklady / rozdíl per měna) —
     * whitelist klíčů drží invariant „jen agregáty" i kdyby CRM služba přidala pole.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function kpiRow(array $row): array
    {
        return [
            'period'         => $row['period'] ?? null,
            'currency'       => (string) ($row['currency'] ?? 'CZK'),
            'invoiced'       => (float) ($row['revenue'] ?? 0),
            'costs'          => (float) ($row['costs'] ?? 0),
            'profit'         => (float) ($row['profit'] ?? 0),
            'invoiced_czk'   => (float) ($row['revenue_czk'] ?? 0),
            'costs_czk'      => (float) ($row['costs_czk'] ?? 0),
            'profit_czk'     => (float) ($row['profit_czk'] ?? 0),
            'invoice_count'  => (int) ($row['invoice_count'] ?? 0),
            'purchase_count' => (int) ($row['purchase_count'] ?? 0),
        ];
    }

    /**
     * DPH stav běžícího období přes DphPriznaniBuilder (subset summary bez řádků).
     * Neplátce → null; build selhání (nekompletní konfigurace firmy) → null,
     * portál kvůli DPH bloku nesmí spadnout.
     *
     * @return array<string,mixed>|null
     */
    private function vatStatus(int $supplierId, bool $isVatPayer, \DateTimeImmutable $now): ?array
    {
        if (!$isVatPayer) {
            return null;
        }
        try {
            $built = $this->dph->build($supplierId, (int) $now->format('Y'), (int) $now->format('n'));
        } catch (\Throwable) {
            return null;
        }
        $s = (array) ($built['summary'] ?? []);
        return [
            'period'              => (string) ($s['period'] ?? ''),
            'period_type'         => (string) ($s['period_type'] ?? 'monthly'),
            'quarter'             => $s['quarter'] ?? null,
            'vat_output'          => (float) ($s['total_vat_output'] ?? 0),
            'vat_input'           => (float) ($s['total_vat_input'] ?? 0),
            'tax_due'             => (float) ($s['tax_due'] ?? 0),
            'is_excess_deduction' => (bool) ($s['is_excess_deduction'] ?? false),
            'submission_deadline' => (string) ($s['submission_deadline'] ?? ''),
        ];
    }
}
