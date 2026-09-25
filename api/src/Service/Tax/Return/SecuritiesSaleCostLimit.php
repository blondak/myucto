<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Omezení daňové uznatelnosti nákladu prodaných podílů a cenných papírů (561 proti 661)
 * — jediné místo, kde se počítá. Volá ho přiznání DPPO (ř. 40 přes
 * {@see DppoReturnCalculator::accountingAdjustments()}), § 7 FO s podvojným účetnictvím
 * ({@see DpfoReturnDataProvider}) i evidenční podklad úprav základu.
 *
 * ZDP ve znění od 1. 8. 2026:
 *   - § 24 odst. 2 písm. w): nabývací cena akcie nebo kmenového listu neoceňovaných reálnou
 *     hodnotou a nabývací cena podílu na s.r.o., k.s. nebo družstvu je daňovým výdajem
 *     „jen do výše příjmů z prodeje této akcie, tohoto kmenového listu nebo tohoto podílu".
 *     Omezení se nepoužije u cenného papíru, který není oceňován reálnou hodnotou jen proto,
 *     že je poplatník mikro účetní jednotkou.
 *   - § 24 odst. 2 písm. r): ostatní cenné papíry (dluhopisy, cenné papíry oceňované reálnou
 *     hodnotou) jsou daňovým výdajem v účetní hodnotě bez omezení.
 *   - § 25 odst. 1 písm. c) ve spojení s písm. r): pořizovací cena cenného papíru, jehož
 *     převod je osvobozen (§ 19 odst. 1 písm. ze) bod 2, převod podílu mateřské společnosti
 *     v dceřiné), je nedaňová celá.
 *
 * Omezení tedy platí pro každý podíl zvlášť, ne pro úhrn portfolia, a výjimku pro obchodníka
 * s cennými papíry zákon v tomto znění nemá. Z deníku lze spolehlivě určit jen tohle:
 *   - 561P (prodané podíly) je vždy v režimu písm. w). Převis 561P nad CELÝM obratem 661
 *     je dolní mez součtu převisů po jednotlivých podílech (Σ max(0, cena − příjem) ≥
 *     max(0, Σ cena − Σ příjem), a 661 nese i tržby za ostatní papíry), takže základ
 *     nikdy nenadhodnotí. Připočte se automaticky na ř. 40.
 *   - Ostatní 561 (561C, syntetický 561 bez analytiky) mohou být dluhopisy nebo papíry
 *     oceňované reálnou hodnotou (písm. r, bez omezení) i akcie malé účetní jednotky
 *     (písm. w). Který případ nastal, deník neříká; převis se proto jen NABÍZÍ k posouzení.
 *
 * Mimo rozsah (účetní je řeší ruční položkou § 23): osvobozený převod podílu (celá nabývací
 * cena na ř. 40 a příjem na ř. 110), párování ceny a příjmu po jednotlivých podílech.
 *
 * Účty s tax_deductibility='non_deductible' (nebo řádky nedaňové přijaté faktury) už jsou
 * celé v {@see NonDeductibleCostsService}; z nákladu se proto vyřazují, jinak by se na
 * ř. 40 objevily dvakrát.
 */
final class SecuritiesSaleCostLimit
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   shares_cost: float, other_cost: float, income: float,
     *   addback: float, review_amount: float,
     *   accounts: list<array{account_code:string,name:string,kind:string,amount:float}>
     * }
     */
    public function forPeriod(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT a.account_code, a.name, a.account_type,
                    COALESCE(SUM(CASE WHEN a.account_type = 'expense' AND " . NonDeductibleCostsService::predicate() . " THEN 0
                                      WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0) AS turnover
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               JOIN chart_of_accounts a ON a.id = l.account_id
          LEFT JOIN purchase_invoices pi ON " . JournalTaxOrigin::sourceTypeSql() . " = 'purchase_invoice'
                                         AND pi.id = " . JournalTaxOrigin::sourceIdSql() . "
                                         AND pi.supplier_id = e.supplier_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND ((a.account_type = 'expense' AND a.account_code LIKE '561%')
                  OR (a.account_type = 'revenue' AND a.account_code LIKE '661%'))
              GROUP BY a.account_code, a.name, a.account_type
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);

        $shares = 0.0;
        $other = 0.0;
        $income = 0.0;
        $accounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = (string) $row['account_code'];
            $turnover = round((float) $row['turnover'], 2);
            if ((string) $row['account_type'] === 'revenue') {
                $amount = round(-$turnover, 2);
                $income += $amount;
                $kind = 'income';
            } else {
                $amount = $turnover;
                $kind = str_starts_with(strtoupper($code), '561P') ? 'shares' : 'other';
                if ($kind === 'shares') {
                    $shares += $amount;
                } else {
                    $other += $amount;
                }
            }
            if ((int) round($amount * 100) !== 0) {
                $accounts[] = ['account_code' => $code, 'name' => (string) $row['name'], 'kind' => $kind, 'amount' => $amount];
            }
        }

        return self::limit(round($shares, 2), round($other, 2), round($income, 2)) + ['accounts' => $accounts];
    }

    /**
     * Čisté pravidlo nad obraty: automatický připočet (jen podíly) a částka k posouzení
     * (převis všech 561 nad 661, který automatika nepřipočetla).
     *
     * @return array{shares_cost:float,other_cost:float,income:float,addback:float,review_amount:float}
     */
    public static function limit(float $sharesCost, float $otherCost, float $income): array
    {
        $shares = max(0.0, $sharesCost);
        $other = max(0.0, $otherCost);
        $income = max(0.0, $income);
        $addback = max(0.0, round($shares - $income, 2));
        $review = max(0.0, round(max(0.0, $shares + $other - $income) - $addback, 2));

        return [
            'shares_cost' => round($shares, 2),
            'other_cost' => round($other, 2),
            'income' => round($income, 2),
            'addback' => $addback,
            'review_amount' => $review,
        ];
    }

    /** @return array{shares_cost:float,other_cost:float,income:float,addback:float,review_amount:float,accounts:list<array<string,mixed>>} */
    public static function empty(): array
    {
        return self::limit(0.0, 0.0, 0.0) + ['accounts' => []];
    }
}
