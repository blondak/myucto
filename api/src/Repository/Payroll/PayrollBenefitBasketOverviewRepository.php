<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use PDO;

/**
 * Čerpání košů osvobození za celou firmu — ročních i měsíčních.
 *
 * Rozhodné období si koš nevybírá přehled, ale samo ustanovení
 * ({@see PayrollBenefitExemptionBasket::accumulatesPerMonth()}), takže se obě
 * množiny košů nepřekrývají: {@see page()} sčítá zdaňovací období,
 * {@see monthlyPage()} kalendářní měsíc a každý koš patří právě do jedné z nich.
 *
 * Repozitář výhradně ČTE, a to ze dvou zdrojů, které se nesmí zaměnit:
 *
 *  - `payroll_benefit_accumulators.amount_minor` = HRUBÉ plnění, kterým se koš
 *    čerpá. To je totéž číslo, proti kterému se poměřuje další vstup
 *    ({@see PayrollInputRepository::annualBasketTotal()}), takže „vyčerpáno" tady
 *    znamená přesně to co tam.
 *  - `payroll_inputs.benefit_exempt_minor` / `benefit_taxable_minor` = ZMRAZENÝ
 *    rozpad z okamžiku schválení. Nepřepočítává se: pořadí čerpání koše je dané
 *    pořadím schválení a zpětný přepočet by u dřívějšího vstupu změnil daňový
 *    dopad, který už je v uzavřené revizi mzdového běhu.
 *
 * Agreguje se na `employee_id`, ne na `employment_id` — koš je podle § 6 odst. 9
 * ZDP za osobu u zaměstnavatele, takže souběžné vztahy sdílí jeden koš — a přes
 * `component.exemption_basket`, takže se sečtou i různé mzdové složky téhož koše.
 *
 * Vstupy schválené dřív, než koše existovaly, mají rozpad NULL. Nedopočítávají
 * se: `unfrozen_count` je vypraví ven jako chybějící podklad a přehled to řekne
 * větou.
 *
 * Stornované akumulátory (`status = "reversed"`,
 * {@see PayrollInputRepository::reverseBenefit()}) do čerpání NEVSTUPUJÍ — koš se
 * jimi právě uvolnil. Ze součtů jsou proto vyloučené podmíněnou agregací, ale
 * z přehledu vypadnout nesmějí: jinak by se koš, který se uvolnil, tvářil jako
 * koš, který se nikdy nečerpal, a účetní by neměla kde ověřit, že storno
 * proběhlo. Jdou ven jako `reversed_count` a `reversed_minor` a řádek se drží
 * i tehdy, když po stornu nezůstal jediný aktivní akumulátor.
 */
final class PayrollBenefitBasketOverviewRepository
{
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /** Znak, kterým se v hledání escapuje `%`, `_` a on sám. */
    private const LIKE_ESCAPE = '!';

    /**
     * Do ROČNÍHO přehledu patří jen koše, jejichž rozhodným obdobím je zdaňovací
     * období. Měsíční koše (§ 6 odst. 9 písm. b) a i)) by tu ukázaly roční součet
     * proti limitu, který za rok neexistuje — a „zbývá" by lhalo.
     */
    private const ANNUAL_BASKETS = '"non_cash_health", "non_cash_leisure", "old_age_savings"';

    /**
     * Do MĚSÍČNÍHO přehledu patří naopak jen koše, jejichž rozhodným obdobím je
     * kalendářní měsíc. Roční koš by v něm ukázal jednu dvanáctinu čerpání proti
     * ročnímu limitu, tedy číslo, které nic neznamená.
     */
    private const MONTHLY_BASKETS = '"meal_per_shift", "temporary_accommodation"';

    /** Kolik nejvýš měsíců nabídne rozbalovátko období. */
    private const PERIOD_OPTIONS_LIMIT = 120;

    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(private readonly Connection $db) {}

    /**
     * Stránka přehledu; jeden řádek = jedna osoba a jeden koš.
     *
     * Stránkuje se po řádcích osoba × koš, ne po osobách: filtr na koš je pak
     * zúžení téže množiny a `total` sedí s tím, co jde odklikat.
     *
     * @return array{items: list<array<string,int|string>>, total: int}
     */
    public function page(
        int $supplierId,
        int $taxYear,
        ?PayrollBenefitExemptionBasket $basket = null,
        string $search = '',
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        return $this->scan(
            $supplierId,
            self::ANNUAL_BASKETS,
            'accumulator.tax_year = ?',
            $taxYear,
            $basket,
            $search,
            $limit,
            $offset,
        );
    }

    /**
     * Táž stránka za JEDEN KALENDÁŘNÍ MĚSÍC, pro koše podle § 6 odst. 9 písm. b)
     * a i) ZDP.
     *
     * Období se bere z mzdového VSTUPU (`payroll_inputs.period_start`), ne
     * z akumulátoru — ten nese jen zdaňovací rok. Zpětný vstup do minulého měsíce
     * se tak započte tomu měsíci, kterého se týká, ne tomu, ve kterém se zadával.
     * Je to přesně týž klíč, jaký používá
     * {@see PayrollInputRepository::monthlyBasketTotal()}, takže „vyčerpáno" tady
     * znamená totéž, proti čemu se poměřuje další vstup.
     *
     * Nový index není potřeba: dotaz se dá vést existujícím
     * `idx_payroll_input_period_status (supplier_id, period_start, status)` a na
     * akumulátor se z něj sahá přes `uq_payroll_benefit_input (supplier_id, input_id)`.
     *
     * @param string $periodStart První den měsíce ve tvaru `YYYY-MM-01`.
     * @return array{items: list<array<string,int|string>>, total: int}
     */
    public function monthlyPage(
        int $supplierId,
        string $periodStart,
        ?PayrollBenefitExemptionBasket $basket = null,
        string $search = '',
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        return $this->scan(
            $supplierId,
            self::MONTHLY_BASKETS,
            'input.period_start = ?',
            $periodStart,
            $basket,
            $search,
            $limit,
            $offset,
        );
    }

    /**
     * @param string $basketList Seznam košů pro `IN (…)`, viz konstanty výše.
     * @param string $windowClause Podmínka rozhodného období s jedním `?`.
     * @return array{items: list<array<string,int|string>>, total: int}
     */
    private function scan(
        int $supplierId,
        string $basketList,
        string $windowClause,
        int|string $windowValue,
        ?PayrollBenefitExemptionBasket $basket,
        string $search,
        int $limit,
        int $offset,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $search = mb_substr(trim($search), 0, self::SEARCH_MAX_LENGTH);

        $basketClause = $basket === null ? '' : ' AND component.exemption_basket = ?';
        $searchClause = $search === ''
            ? ''
            : ' AND ' . PayrollPeopleRepository::fullNameExpression()
                . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";

        $countStmt = $this->db->pdo()->prepare(
            $this->totalsCte($basketList, $windowClause, $basketClause)
            . 'SELECT COUNT(*)
                 FROM basket_totals totals
                 JOIN payroll_employees employee
                   ON employee.supplier_id = ?
                  AND employee.id = totals.employee_id
                WHERE 1 = 1' . $searchClause
        );
        $position = 1;
        $countStmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        $countStmt->bindValue(
            $position++,
            $windowValue,
            is_int($windowValue) ? PDO::PARAM_INT : PDO::PARAM_STR,
        );
        if ($basket !== null) {
            $countStmt->bindValue($position++, $basket->value);
        }
        $countStmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        if ($search !== '') {
            $countStmt->bindValue($position, '%' . self::escapeLike($search) . '%');
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            $this->totalsCte($basketList, $windowClause, $basketClause)
            . 'SELECT totals.employee_id,
                      totals.basket,
                      totals.used_minor,
                      totals.exempt_minor,
                      totals.taxable_minor,
                      totals.input_count,
                      totals.unfrozen_count,
                      totals.negative_count,
                      totals.reversed_count,
                      totals.reversed_minor,
                      ' . PayrollPeopleRepository::fullNameExpression() . ' AS employee_name
                 FROM basket_totals totals
                 JOIN payroll_employees employee
                   ON employee.supplier_id = ?
                  AND employee.id = totals.employee_id
                WHERE 1 = 1' . $searchClause . '
                ORDER BY employee_name ASC, totals.employee_id ASC, totals.basket ASC
                LIMIT ? OFFSET ?'
        );
        $position = 1;
        $stmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(
            $position++,
            $windowValue,
            is_int($windowValue) ? PDO::PARAM_INT : PDO::PARAM_STR,
        );
        if ($basket !== null) {
            $stmt->bindValue($position++, $basket->value);
        }
        $stmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        if ($search !== '') {
            $stmt->bindValue($position++, '%' . self::escapeLike($search) . '%');
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'employee_id' => PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                'employee_name' => PayrollTimeValue::string(
                    $row['employee_name'] ?? null,
                    'employee_name',
                ),
                'basket' => PayrollTimeValue::string($row['basket'] ?? null, 'basket'),
                'used_minor' => PayrollTimeValue::int($row['used_minor'] ?? null, 'used_minor'),
                'exempt_minor' => PayrollTimeValue::int(
                    $row['exempt_minor'] ?? null,
                    'exempt_minor',
                ),
                'taxable_minor' => PayrollTimeValue::int(
                    $row['taxable_minor'] ?? null,
                    'taxable_minor',
                ),
                'input_count' => PayrollTimeValue::int($row['input_count'] ?? null, 'input_count'),
                'unfrozen_count' => PayrollTimeValue::int(
                    $row['unfrozen_count'] ?? null,
                    'unfrozen_count',
                ),
                'negative_count' => PayrollTimeValue::int(
                    $row['negative_count'] ?? null,
                    'negative_count',
                ),
                'reversed_count' => PayrollTimeValue::int(
                    $row['reversed_count'] ?? null,
                    'reversed_count',
                ),
                'reversed_minor' => PayrollTimeValue::int(
                    $row['reversed_minor'] ?? null,
                    'reversed_minor',
                ),
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Roky, ve kterých firma vůbec něco do koše načerpala.
     *
     * Slouží rozbalovátku filtru: nabízet roky, ve kterých nic není, znamená
     * nabízet prázdné obrazovky.
     *
     * @return list<int>
     */
    public function years(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT accumulator.tax_year
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.status IN ("active", "reversed")
                AND component.exemption_basket IN (' . self::ANNUAL_BASKETS . ')
              ORDER BY accumulator.tax_year DESC'
        );
        $stmt->execute([$supplierId]);

        return array_map(
            static fn (mixed $year): int => (int) $year,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Měsíce, ve kterých firma vůbec něco do měsíčního koše načerpala.
     *
     * Protějšek {@see years()}. Období se čte ze mzdového VSTUPU, ne z roku
     * akumulátoru — jinak by rozbalovátko nabízelo dvanáct měsíců každého roku,
     * ve kterém padl jediný vstup.
     *
     * @return list<string> Měsíce ve tvaru `YYYY-MM`, od nejnovějšího.
     */
    public function periods(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT DATE_FORMAT(input.period_start, "%Y-%m") AS period
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_inputs input
                 ON input.supplier_id = accumulator.supplier_id
                AND input.id = accumulator.input_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.status IN ("active", "reversed")
                AND component.exemption_basket IN (' . self::MONTHLY_BASKETS . ')
              ORDER BY period DESC
              LIMIT ' . self::PERIOD_OPTIONS_LIMIT
        );
        $stmt->execute([$supplierId]);

        return array_map(
            static fn (mixed $period): string => (string) $period,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Agregace osoba × koš. Jméno se dojoinuje až v nadřazeném dotazu — jinak by
     * se korelovaný poddotaz účinného jména vyhodnocoval nad každým akumulátorem,
     * ne nad výslednou stránkou.
     */
    private function totalsCte(
        string $basketList,
        string $windowClause,
        string $basketClause,
    ): string {
        return 'WITH basket_totals AS (
                    SELECT accumulator.employee_id AS employee_id,
                           component.exemption_basket AS basket,
                           SUM(CASE WHEN accumulator.status = "active"
                                    THEN accumulator.amount_minor ELSE 0 END) AS used_minor,
                           SUM(CASE WHEN accumulator.status = "active"
                                    THEN COALESCE(input.benefit_exempt_minor, 0)
                                    ELSE 0 END) AS exempt_minor,
                           SUM(CASE WHEN accumulator.status = "active"
                                    THEN COALESCE(input.benefit_taxable_minor, 0)
                                    ELSE 0 END) AS taxable_minor,
                           SUM(CASE WHEN accumulator.status = "active" THEN 1 ELSE 0 END)
                               AS input_count,
                           SUM(CASE WHEN accumulator.status = "active"
                                     AND input.benefit_basket IS NULL
                                    THEN 1 ELSE 0 END) AS unfrozen_count,
                           SUM(CASE WHEN accumulator.status = "active"
                                     AND accumulator.amount_minor < 0
                                    THEN 1 ELSE 0 END) AS negative_count,
                           SUM(CASE WHEN accumulator.status = "reversed" THEN 1 ELSE 0 END)
                               AS reversed_count,
                           SUM(CASE WHEN accumulator.status = "reversed"
                                    THEN accumulator.amount_minor ELSE 0 END) AS reversed_minor
                      FROM payroll_benefit_accumulators accumulator
                      JOIN payroll_component_definitions component
                        ON component.supplier_id = accumulator.supplier_id
                       AND component.id = accumulator.component_id
                      JOIN payroll_inputs input
                        ON input.supplier_id = accumulator.supplier_id
                       AND input.id = accumulator.input_id
                     WHERE accumulator.supplier_id = ?
                       AND ' . $windowClause . '
                       AND accumulator.status IN ("active", "reversed")
                       AND component.exemption_basket IN (' . $basketList . ')'
                       . $basketClause . '
                     GROUP BY accumulator.employee_id, component.exemption_basket
                ) ';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [
                self::LIKE_ESCAPE . self::LIKE_ESCAPE,
                self::LIKE_ESCAPE . '%',
                self::LIKE_ESCAPE . '_',
            ],
            $value,
        );
    }
}
