<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\LedgerReportRepository;

/**
 * Opis účtu (Epic F2): stránkovaný výpis pohybů účtu (vč. analytik pod
 * syntetikou) s běžícím zůstatkem. PS k `from` dle okna R6; balance = kladné = MD.
 * Řazení entry_date, entry_id, line_no (deterministické) — running_delta počítá
 * window funkce nad celým rozsahem, takže stránkování zůstatky nemění.
 */
final class AccountStatementService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array<string,mixed> struktura dle spec §2.6
     */
    public function build(int $supplierId, int $accountId, string $from, string $to, int $page, int $perPage, bool $afterClosing = false): array
    {
        $account = $this->accounts->findById($supplierId, $accountId);
        if ($account === null) {
            throw new ReportException('account_not_found', 'Účet #' . $accountId . ' neexistuje.', 404);
        }

        $period = $this->periods->findForDate($supplierId, $from);
        $periodStart = $period !== null ? (string) $period['starts_on'] : $from;

        $opening = $this->ledger->accountOpening($supplierId, $accountId, $from, $periodStart, !$afterClosing);
        $total   = $this->ledger->accountLinesTotal($supplierId, $accountId, $from, $to);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $lines = $this->ledger->accountLines($supplierId, $accountId, $from, $to, $perPage, ($page - 1) * $perPage);

        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'entry_id'    => (int) $l['entry_id'],
                'entry_date'  => (string) $l['entry_date'],
                'document_no' => $l['document_no'],
                'description' => $l['description'],
                'source_type' => (string) $l['source_type'],
                'source_id'   => $l['source_id'],
                'side'        => (string) $l['side'],
                'amount'      => (float) $l['amount'],
                'balance'     => round($opening + (float) $l['running_delta'], 2),
            ];
        }

        $turnovers = $this->ledger->accountTurnovers($supplierId, $accountId, $from, $to);

        return [
            'account' => [
                'id'          => (int) $account['id'],
                'code'        => (string) $account['account_code'],
                'name'        => (string) $account['name'],
                'type'        => (string) $account['account_type'],
                'normal_side' => $account['normal_side'],
            ],
            'opening_balance' => $opening,
            'items'           => $items,
            'total'           => $total,
            'page'            => $page,
            'per_page'        => $perPage,
            'closing_balance' => round($opening + $turnovers['md'] - $turnovers['d'], 2),
            'turnover_md'     => $turnovers['md'],
            'turnover_d'      => $turnovers['d'],
        ];
    }
}
