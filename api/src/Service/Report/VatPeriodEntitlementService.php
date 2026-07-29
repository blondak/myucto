<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Nárok na čtvrtletní zdaňovací období — § 99 a § 99a ZDPH.
 *
 * `supplier.vat_period` byl ruční přepínač BEZ jakékoli kontroly nároku. Nesprávné
 * nastavení přitom neznamená jednu chybu, ale celoročně pozdě podávaná přiznání a
 * pokuty — plátce, který na čtvrtletní období nárok nemá, musí podávat měsíčně.
 *
 * ── Co se ověřit DÁ ────────────────────────────────────────────────────────────────
 * Obrat za předcházející kalendářní rok proti limitu § 99a odst. 1. Počítá se stejnými
 * pravidly jako obrat pro registraci (§ 4a): vystavené faktury, daňové doklady k přijaté
 * platbě a dobropisy, přičemž dobropis obrat VŽDY snižuje. Koncepty, proformy a storna
 * se nezapočítávají.
 *
 * ── Co se ověřit NEDÁ, a proto se o tom mlčet nesmí ────────────────────────────────
 * Podle § 99a odst. 3 si plátce nemůže zvolit čtvrtletní období v roce registrace ani
 * v roce bezprostředně následujícím. Datum registrace k DPH ale model nezná (`supplier`
 * takový sloupec nemá), takže se na to jen UPOZORŇUJE. Totéž platí pro nespolehlivého
 * plátce a skupinovou registraci — obojí je stav v registru MFČR, ne v účetnictví.
 *
 * Tvrdit „nárok máte" na základě jediné ověřené podmínky by bylo horší než dnešní stav:
 * dnes uživatel ví, že si to musí ohlídat sám, kdežto falešně zelené hlášení by ho
 * uklidnilo.
 */
final class VatPeriodEntitlementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * Posouzení nároku pro dané zdaňovací období (rok).
     *
     * @return array{
     *   vat_period:?string, prior_year:int, prior_year_turnover:float, limit:float,
     *   over_limit:bool, ok:bool, warnings:list<string>
     * }
     */
    public function evaluate(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT vat_period, is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $period = $row['vat_period'] !== null ? (string) $row['vat_period'] : null;
        $isPayer = !empty($row['is_vat_payer']);
        $priorYear = $year - 1;
        $limit = (float) ($this->constants->forYear($year)['vat_quarterly_turnover_limit'] ?? 15000000);
        $turnover = $this->turnoverForYear($supplierId, $priorYear);
        $overLimit = $turnover > $limit;

        $warnings = [];
        if ($isPayer && $period === 'quarterly') {
            if ($overLimit) {
                $warnings[] = sprintf(
                    'Obrat za rok %d byl %s Kč, tedy nad limitem %s Kč — na čtvrtletní zdaňovací období '
                        . 'nárok podle § 99a odst. 1 není a přiznání se musí podávat MĚSÍČNĚ. '
                        . 'Nastavení firmy je přitom čtvrtletní.',
                    $priorYear,
                    number_format($turnover, 0, ',', ' '),
                    number_format($limit, 0, ',', ' '),
                );
            }
            // Hlásí se VŽDY, i když obrat sedí — jsou to podmínky, které systém ověřit
            // nemůže, a mlčení by vypadalo jako potvrzení nároku.
            $warnings[] = 'Systém neověřuje zbývající podmínky § 99a: rok registrace a rok bezprostředně '
                . 'následující (čtvrtletní období si v nich zvolit nelze), nespolehlivého plátce ani '
                . 'skupinovou registraci. Ověřte je ručně.';
        }

        return [
            'vat_period'          => $period,
            'prior_year'          => $priorYear,
            'prior_year_turnover' => round($turnover, 2),
            'limit'               => $limit,
            'over_limit'          => $overLimit,
            // `ok` je pouze o ověřitelné podmínce — viz docblock.
            'ok'                  => !($isPayer && $period === 'quarterly' && $overLimit),
            'warnings'            => $warnings,
        ];
    }

    /**
     * Obrat za kalendářní rok podle § 4a. Dobropis obrat VŽDY snižuje — u dobropisu
     * chybně zadaného s kladnou částkou by se jinak obrat navýšil, a právě obrat tady
     * rozhoduje o povinnosti podávat měsíčně.
     */
    private function turnoverForYear(int $supplierId, int $year): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(
                        CASE WHEN i.invoice_type = 'credit_note'
                             THEN -ABS(i.total_without_vat)
                             ELSE i.total_without_vat END
                        * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)
                    ), 0)
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND YEAR(COALESCE(i.effective_tax_date, i.tax_date, i.issue_date)) = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')"
        );
        $stmt->execute([$supplierId, $year]);

        return (float) $stmt->fetchColumn();
    }
}
