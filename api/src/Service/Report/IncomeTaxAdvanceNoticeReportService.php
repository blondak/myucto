<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Podklady pro PDF „Placení záloh dle §38a zákona č. 586/1992 Sb." (uzávěrkový balíček,
 * část `income_tax_advances`). Jen pro poplatníky PO — DPFO §38a se v systému negeneruje
 * (viz {@see \MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService}, docblok třídy).
 *
 * Daň za rok a doplatek/přeplatek čerpá ze STEJNÉHO výpočtu jako DPPO přiznání
 * ({@see TaxReturnService::getReturn()}, computed.summary — total_tax/balance_due).
 *
 * Termíny a výše záloh na PŘÍŠTÍ zdaňovací období NEbere ze syrové predikce kalkulátoru
 * (next_advances), ale z persistovaného předpisu ({@see TaxReturnService::advanceScheduleList()}) —
 * ten jako jediný zohledňuje případný override rozhodnutím finančního úřadu (§174 daňového
 * řádu, #43): predikce je jen odhad z právě počítaného přiznání, kdežto schválený override je
 * skutečná stanovená výše. Před čtením se předpis best-effort přegeneruje
 * ({@see TaxReturnService::generateAdvanceSchedulesForPeriod()}), aby odrážel aktuální stav
 * přiznání/override — regenerace je bezpečná i pro už spárované/potvrzené zálohy (přepisuje
 * jen 'planned' řádky, {@see \MyInvoice\Repository\TaxAdvanceScheduleRepository::replacePlanned()}).
 */
final class IncomeTaxAdvanceNoticeReportService
{
    public function __construct(
        private readonly TaxReturnService $taxReturns,
        private readonly Connection $db,
    ) {}

    /**
     * @return array{
     *   supplier: array{company_name:string,dic:string,ic:string},
     *   year: int, period_year: int, total_tax: float, balance_due: float,
     *   regime: string, advances: list<array{due_date:string,amount:float,status:string}>,
     * }
     */
    public function build(int $supplierId, int $year): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $periodYear = $year + 1;

        $returnData = $this->taxReturns->getReturn($supplierId, $year, 'po', null);
        $summary = (array) ($returnData['computed']['summary'] ?? []);
        $totalTax = round((float) ($summary['total_tax'] ?? 0), 2);
        $balanceDue = round((float) ($summary['balance_due'] ?? 0), 2);

        try {
            $this->taxReturns->generateAdvanceSchedulesForPeriod($supplierId, $periodYear, 'po');
        } catch (\Throwable) {
            // Předpis se nepodařilo (znovu) vygenerovat (chybí přiznání i override) —
            // použije se, co je v DB (může být prázdné, pak sestava jen ukáže daň bez záloh).
        }
        $schedules = (array) ($this->taxReturns->advanceScheduleList($supplierId, $periodYear, 'po')['schedules'] ?? []);
        $advances = [];
        foreach ($schedules as $s) {
            if ((string) ($s['advance_kind'] ?? '') !== 'tax') {
                continue;
            }
            $advances[] = [
                'due_date' => (string) $s['due_date'],
                'amount' => round((float) $s['amount'], 2),
                'status' => (string) $s['status'],
            ];
        }

        $regime = match (count($advances)) {
            4 => 'quarterly',
            2 => 'semiannual',
            1 => 'annual',
            0 => 'none',
            default => 'other',
        };

        return [
            'supplier' => [
                'company_name' => (string) ($supplier['company_name'] ?? ''),
                'dic' => (string) ($supplier['dic'] ?? ''),
                'ic' => (string) ($supplier['ic'] ?? ''),
            ],
            'year' => $year,
            'period_year' => $periodYear,
            'total_tax' => $totalTax,
            'balance_due' => $balanceDue,
            'regime' => $regime,
            'advances' => $advances,
        ];
    }

    /** @return array<string,mixed> */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, ic, dic FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }
}
