<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Časové rozlišení nákladů a výnosů příštích období a dohadných položek
 * (účty 381–385) k rozvahovému dni — uzávěrkový balíček #33.
 *
 * NEPOČÍTÁ NIC NOVÉHO — je to filtr výstupu {@see BalanceInventoryService::build()}
 * (soupis VŠECH rozvahových účtů tříd 0–4 s KZ MD/D) na účty s prefixem 381/382/
 * 383/384/385, včetně analytik (381.100 apod.). Díky tomu zůstává jediný zdroj
 * pravdy pro zůstatek účtu k rozvahovému dni (LedgerReportRepository::trialBalanceRows,
 * stejná definice jako rozvaha).
 */
final class AccrualsReportService
{
    /** Prefixy účtů časového rozlišení (§29 ZoÚ, ČÚS 017). */
    private const PREFIXES = ['381', '382', '383', '384', '385'];

    public function __construct(
        private readonly BalanceInventoryService $balanceInventory,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $periodId): array
    {
        $data = $this->balanceInventory->build($supplierId, $periodId);

        $rows = array_values(array_filter($data['rows'], static function (array $row): bool {
            $code = substr((string) $row['account_code'], 0, 3);
            return in_array($code, self::PREFIXES, true);
        }));

        $totals = ['ks_md' => 0.0, 'ks_d' => 0.0];
        foreach ($rows as $row) {
            $totals['ks_md'] = round($totals['ks_md'] + (float) $row['ks_md'], 2);
            $totals['ks_d'] = round($totals['ks_d'] + (float) $row['ks_d'], 2);
        }

        $data['rows'] = $rows;
        $data['count'] = count($rows);
        $data['totals'] = $totals;
        $data['report_title'] = 'Časové rozlišení';
        $data['legal_note'] = 'Soupis zůstatků účtů časového rozlišení nákladů a výnosů příštích'
            . ' období a dohadných položek (381–385) dle §29 zákona č. 563/1991 Sb., o účetnictví,'
            . ' a ČÚS 017 — k rozvahovému dni. Sloupce „Skutečný stav" a „Rozdíl" se doplňují ručně'
            . ' na základě přepočtu jednotlivých položek časového rozlišení.';

        return $data;
    }
}
