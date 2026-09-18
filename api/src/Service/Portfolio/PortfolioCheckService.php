<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PDO;

/**
 * Souhrn měsíční kontroly pro JEDNU firmu do přehledu firem.
 *
 * Účetní kancelář chce z rozcestníku vidět, KDE něco nesedí, ne otevírat měsíční
 * kontrolu firmu po firmě. Plná sada (přes 40 kontrol) se na to ale pustit nedá —
 * nad velkou firmou trvá jednotky sekund a přehled firem je jedna stránka pro
 * všechny firmy naráz. Bere se proto jen {@see ACUTE_KEYS}: kontroly, které ukazují
 * na rozbité účetnictví (nesedící deník, chybějící zápis), ne na stav práce.
 *
 * Vrací jen POČTY a klíče — nálezy samotné si vyžádá až detail měsíční kontroly
 * té firmy. Přehled tak zůstane malý i u kanceláře s padesáti firmami.
 */
final class PortfolioCheckService
{
    /**
     * Kontroly, které jdou do přehledu firem — jen to, co je AKUTNÍ k dnešku.
     *
     * Výběr je záměrně užší než měsíční kontrola, a to dvakrát. Informativní
     * kontroly (zůstatky dohadných položek, daňový odhad) sem nepatří vůbec —
     * nejsou to nálezy, jen čísla. A z nálezů sem patří jen ty, se kterými účetní
     * může hnout DNES: chybějící zápis, saldo, které nesedí na zaplacený doklad,
     * nevyrovnaný deník. Sezónní práce vázaná na rozvahový den je v
     * {@see SEASONAL_KEYS} a na rozcestník nechodí.
     */
    public const ACUTE_KEYS = [
        // Rozbité účetnictví — severity error.
        'pl_balance_before_period',
        'drafts_in_period',
        'journal_unbalanced',
        // Nezaúčtováno / nesedí saldo — nejčastější reálné nálezy.
        'unposted_invoices',
        'unposted_purchases',
        'paid_invoices_open_saldo',
        'paid_purchases_open_saldo',
        'settled_but_unpaid',
        'cancelled_with_entry',
        // Daně a měna.
        'vat_343_vs_return',
        'realized_fx_unbooked',
    ];

    /**
     * Kontroly, které přehled firem SCHVÁLNĚ nepočítá — patří do měsíční kontroly
     * a do uzávěrky, ne na denní rozcestník.
     *
     * Není to rozdíl v závažnosti, ale v tom, KDY se to dělá. Všechny tři se
     * rozsvítí jako běžný stav rozdělané práce a svítí měsíce, takže by z pruhu
     * udělaly stálou barvu, kterou účetní přestane číst — a s ní přehlédne i nález,
     * který akutní je:
     *   - `depreciation_missing` — účetní odpisy roku se účtují v uzávěrce, takže
     *     u otevřeného roku chybí naprosto legitimně až do jejího spuštění,
     *   - `inventory_unresolved` — inventarizace se dělá k rozvahovému dni (§29–30 ZoÚ),
     *   - `vh_431_undistributed` — VH na 431 leží nerozdělený legitimně až do
     *     rozhodnutí valné hromady, typicky do půlky následujícího roku,
     *   - `prior_period_open` — minulý rok je otevřený běžně až do podání přiznání.
     *
     * Blokující bránou uzávěrky zůstávají dál ({@see ClosingService::failingErrorChecks}),
     * jen se na ně neupozorňuje odsud.
     */
    public const SEASONAL_KEYS = [
        'prior_period_open',
        'vh_431_undistributed',
        'inventory_unresolved',
        'depreciation_missing',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly ClosingService $closing,
    ) {}

    /**
     * @return array{supplier_id:int, period:array{id:int, fiscal_year:int}, range_from:string,
     *               range_to:string, errors:int, warnings:int,
     *               findings:list<array{key:string, severity:string, count:int}>}|null
     *         null = firma nevede podvojné účetnictví nebo nemá založené období
     */
    public function summary(int $supplierId, \DateTimeImmutable $now): ?array
    {
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) ($mode->fetchColumn() ?: '') !== 'double_entry') {
            return null;
        }

        $period = $this->currentPeriod($supplierId, $now);
        if ($period === null) {
            return null;
        }

        // Do dneška, ne do konce roku: kontroly „k datu" by jinak u otevřeného roku
        // hlásily stav k 31. 12., který ještě nenastal.
        $rangeFrom = (string) $period['starts_on'];
        $rangeTo = min((string) $period['ends_on'], $now->format('Y-m-d'));
        if ($rangeTo < $rangeFrom) {
            $rangeTo = $rangeFrom;
        }

        // cap = 0: nálezy se stejně zahazují, posílají se jen počty.
        $checks = $this->closing->buildChecks($supplierId, $period, $rangeFrom, $rangeTo, 0, self::ACUTE_KEYS);

        $findings = [];
        $errors = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            if (($check['ok'] ?? true) === true) {
                continue;
            }
            $severity = (string) ($check['severity'] ?? 'warning');
            if ($severity === 'error') {
                $errors++;
            } elseif ($severity === 'warning') {
                $warnings++;
            } else {
                continue; // info nálezy do přehledu nepatří
            }
            $findings[] = [
                'key'      => (string) $check['key'],
                'severity' => $severity,
                'count'    => self::findingCount($check['value'] ?? null),
            ];
        }

        return [
            'supplier_id' => $supplierId,
            'period'      => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year']],
            'range_from'  => $rangeFrom,
            'range_to'    => $rangeTo,
            'errors'      => $errors,
            'warnings'    => $warnings,
            'findings'    => $findings,
        ];
    }

    /** Období pokrývající dnešek; jinak poslední založené (loňský rok u nezaložené řady). */
    private function currentPeriod(int $supplierId, \DateTimeImmutable $now): ?array
    {
        $today = $now->format('Y-m-d');
        $period = $this->periods->findForDate($supplierId, $today);
        if ($period !== null) {
            return $period;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year DESC LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Počet nálezů kontroly. Kontroly se skalární hodnotou (zůstatek, rozdíl) počet
     * nemají — hlásí se jako jeden nález, ať nezmizí z přehledu.
     */
    private static function findingCount(mixed $value): int
    {
        if (is_array($value)) {
            if (isset($value['count']) && is_numeric($value['count'])) {
                return (int) $value['count'];
            }
            if (isset($value['findings']) && is_array($value['findings'])) {
                return count($value['findings']);
            }
            if (isset($value['items']) && is_array($value['items'])) {
                return count($value['items']);
            }
        }
        return 1;
    }
}
