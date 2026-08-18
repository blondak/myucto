<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use PDO;

/**
 * Čerpání ročních košů osvobození za celou firmu.
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
 */
final class PayrollBenefitBasketOverviewRepository
{
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /** Znak, kterým se v hledání escapuje `%`, `_` a on sám. */
    private const LIKE_ESCAPE = '!';

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
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $search = mb_substr(trim($search), 0, self::SEARCH_MAX_LENGTH);

        $basketClause = $basket === null ? '' : ' AND component.exemption_basket = ?';
        $searchClause = $search === ''
            ? ''
            : ' AND ' . PayrollPeopleRepository::fullNameExpression()
                . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";

        $countStmt = $this->db->pdo()->prepare(
            $this->totalsCte($basketClause)
            . 'SELECT COUNT(*)
                 FROM basket_totals totals
                 JOIN payroll_employees employee
                   ON employee.supplier_id = ?
                  AND employee.id = totals.employee_id
                WHERE 1 = 1' . $searchClause
        );
        $position = 1;
        $countStmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        $countStmt->bindValue($position++, $taxYear, PDO::PARAM_INT);
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
            $this->totalsCte($basketClause)
            . 'SELECT totals.employee_id,
                      totals.basket,
                      totals.used_minor,
                      totals.exempt_minor,
                      totals.taxable_minor,
                      totals.input_count,
                      totals.unfrozen_count,
                      totals.negative_count,
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
        $stmt->bindValue($position++, $taxYear, PDO::PARAM_INT);
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
                AND accumulator.status = "active"
                AND component.exemption_basket IS NOT NULL
              ORDER BY accumulator.tax_year DESC'
        );
        $stmt->execute([$supplierId]);

        return array_map(
            static fn (mixed $year): int => (int) $year,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Agregace osoba × koš. Jméno se dojoinuje až v nadřazeném dotazu — jinak by
     * se korelovaný poddotaz účinného jména vyhodnocoval nad každým akumulátorem,
     * ne nad výslednou stránkou.
     */
    private function totalsCte(string $basketClause): string
    {
        return 'WITH basket_totals AS (
                    SELECT accumulator.employee_id AS employee_id,
                           component.exemption_basket AS basket,
                           SUM(accumulator.amount_minor) AS used_minor,
                           SUM(COALESCE(input.benefit_exempt_minor, 0)) AS exempt_minor,
                           SUM(COALESCE(input.benefit_taxable_minor, 0)) AS taxable_minor,
                           COUNT(*) AS input_count,
                           SUM(CASE WHEN input.benefit_basket IS NULL THEN 1 ELSE 0 END)
                               AS unfrozen_count,
                           SUM(CASE WHEN accumulator.amount_minor < 0 THEN 1 ELSE 0 END)
                               AS negative_count
                      FROM payroll_benefit_accumulators accumulator
                      JOIN payroll_component_definitions component
                        ON component.supplier_id = accumulator.supplier_id
                       AND component.id = accumulator.component_id
                      JOIN payroll_inputs input
                        ON input.supplier_id = accumulator.supplier_id
                       AND input.id = accumulator.input_id
                     WHERE accumulator.supplier_id = ?
                       AND accumulator.tax_year = ?
                       AND accumulator.status = "active"
                       AND component.exemption_basket IS NOT NULL' . $basketClause . '
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
