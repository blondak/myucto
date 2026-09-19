<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Report\EpoOkecCodebook;

/**
 * Podklad k výběru výnosů obchodního modelu v čistém obratu (§ 1a odst. 2 zákona
 * o účetnictví, § 35 vyhl. 500/2002 Sb.).
 *
 * Dvě pomůcky, obě NEZÁVAZNÉ:
 *
 *  1. **Návrh podle CZ-NACE.** Zapsaná činnost napoví, které výnosy k modelu
 *     firmy typicky patří — pronajímateli tržby z prodeje pronajímaného majetku,
 *     holdingu výnosy z podílů, nebankovnímu věřiteli úroky.
 *  2. **Obraty řádků.** Řádek, na kterém firma nemá ani korunu, nemá cenu
 *     nabízet k rozhodování — ze seznamu devatenácti zaškrtávátek zbydou jen ty,
 *     kde se opravdu rozhoduje.
 *
 * **Podle kategorie účetní jednotky (mikro/malá/střední/velká) to předvyplnit
 * NEJDE a nikdy nepůjde** — čistý obrat je jedním ze tří kritérií, ze kterých se
 * kategorie teprve určuje (§ 1b ZoÚ, {@see EntityCategoryService}). Odvozovat
 * výběr řádků od kategorie by znamenalo, že o obratu rozhoduje to, co z obratu
 * vychází.
 *
 * Rozhodnutí zůstává na účetní jednotce a podle § 1a odst. 2 ZoÚ se uvádí
 * v příloze v účetní závěrce. Proto se nic nezaškrtává samo a odpověď nese
 * `suggestions_binding: false`.
 */
final class NetTurnoverSuggestionService
{
    /**
     * Návrh podle CZ-NACE: první sedící pravidlo vyhrává, proto od nejužšího
     * kódu k nejširšímu. Prefix se porovnává proti kanonickému šestimístnému
     * tvaru (viz {@see EpoOkecCodebook::display()}), tedy „6420" = 64.20.
     *
     * Účelové členění (příloha č. 2 část II vyhlášky) nemá podřádky, takže
     * „tržby z prodaného dlouhodobého majetku" v něm samostatný řádek NEMAJÍ —
     * spadají do celého II. Ostatní provozní výnosy spolu s jinými provozními
     * výnosy (penále, náhrady škod), které k obchodnímu modelu nepatří.
     * Pronajímateli a leasingové firmě se proto v účelovém členění nenavrhuje
     * nic: nabídnout celé II. by obrat nadhodnotilo a je to rozhodnutí, které
     * musí udělat účetní.
     *
     * @var list<array{prefixes:list<string>,reason:string,rows:array<string,list<string>>}>
     */
    private const NACE_RULES = [
        [
            // 64.20 holdingy a účelové finanční společnosti, 64.30 svěřenské
            // a investiční fondy, 70.10 činnosti řízení podniků.
            'prefixes' => ['6420', '6430', '7010'],
            'reason' => 'holding',
            'rows' => [
                'income_statement' => ['IV.', 'V.'],
                FinancialStatementService::TYPE_PURPOSE => ['III.', 'IV.'],
            ],
        ],
        [
            // 64.91 finanční leasing, 64.92 ostatní poskytování úvěrů. Banky
            // (64.19) sem nepatří — účtují podle vyhlášky č. 501/2002 Sb.
            'prefixes' => ['6491', '6492'],
            'reason' => 'lending',
            'rows' => [
                'income_statement' => ['VI.'],
                FinancialStatementService::TYPE_PURPOSE => ['V.'],
            ],
        ],
        [
            // 64.99 jiné finanční činnosti, typicky obchodování s cennými
            // papíry na vlastní účet — výnos z něj je ostatní finanční výnos.
            'prefixes' => ['6499'],
            'reason' => 'securities_own_account',
            'rows' => [
                'income_statement' => ['VII.'],
                FinancialStatementService::TYPE_PURPOSE => ['VI.'],
            ],
        ],
        [
            // 64.90 obecná „ostatní finanční činnost" bez bližšího určení.
            'prefixes' => ['649'],
            'reason' => 'other_financial',
            'rows' => [
                'income_statement' => ['VI.', 'VII.'],
                FinancialStatementService::TYPE_PURPOSE => ['V.', 'VI.'],
            ],
        ],
        [
            // 68.2 pronájem a správa vlastních nebo pronajatých nemovitostí.
            // 68.1 (nákup a následný prodej nemovitostí) sem NEPATŘÍ: tomu je
            // nemovitost zbožím a tržba už je v I./II.
            'prefixes' => ['682'],
            'reason' => 'rental',
            'rows' => [
                'income_statement' => ['III.1.'],
                FinancialStatementService::TYPE_PURPOSE => [],
            ],
        ],
        [
            // 77 pronájem a leasing hmotných statků — prodej vyřazeného
            // předmětu nájmu je konec obvyklého cyklu, ne mimořádná událost.
            'prefixes' => ['77'],
            'reason' => 'operating_lease',
            'rows' => [
                'income_statement' => ['III.1.'],
                FinancialStatementService::TYPE_PURPOSE => [],
            ],
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly FinancialStatementService $statements,
    ) {}

    /**
     * @return array{
     *     nace:array{code:string,display:string,name:?string,status:string}|null,
     *     suggestion_reason:?string,
     *     suggested_rows:array<string,list<string>>,
     *     suggestions_binding:false,
     *     period:array{id:int,fiscal_year:int,starts_on:string,ends_on:string}|null,
     *     amounts:array<string,array<string,float>>,
     *     amounts_available:bool
     * }
     */
    public function hints(int $supplierId): array
    {
        $nace = EpoOkecCodebook::describe($this->supplierNaceCode($supplierId));
        $rule = $nace === null ? null : self::suggestFor($nace['code']);
        $period = $this->referencePeriod($supplierId);
        $amounts = $period === null ? [] : $this->amounts($supplierId, (int) $period['id']);

        return [
            'nace' => $nace === null ? null : [
                'code' => $nace['code'],
                'display' => $nace['display'],
                'name' => $nace['name'],
                'status' => $nace['status'],
            ],
            'suggestion_reason' => $rule['reason'] ?? null,
            'suggested_rows' => $rule['rows'] ?? self::allowedOnly([]),
            // Nikdy se nemění. Kdo bere návrh, vidí v téže odpovědi, že za něj
            // aplikace neručí — úsudek o obchodním modelu je na účetní jednotce.
            'suggestions_binding' => false,
            'period' => $period === null ? null : [
                'id' => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on' => (string) $period['starts_on'],
                'ends_on' => (string) $period['ends_on'],
            ],
            'amounts' => $amounts,
            // Prázdné obraty znamenají „nevíme", ne „všechno je nula": bez
            // období ani sestavitelného výkazu se nesmí žádný řádek schovat.
            'amounts_available' => $amounts !== [],
        ];
    }

    /**
     * Návrh omezený na volby, které vyhláška pro daný výkaz vůbec připouští.
     * Pojistka proti rozejití téhle tabulky se seznamem voleb.
     *
     * @param array<string,list<string>> $rows
     * @return array<string,list<string>>
     */
    private static function allowedOnly(array $rows): array
    {
        $out = [];
        foreach (FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS as $type => $allowed) {
            $out[$type] = array_values(array_intersect($rows[$type] ?? [], $allowed));
        }

        return $out;
    }

    /**
     * Návrh pro jeden kód CZ-NACE, nebo `null`, když žádné pravidlo nesedí.
     *
     * Veřejná a statická schválně: pravidlo schované jako privátní helper uvnitř
     * jedné služby se okopíruje dřív, než by kdo hledal, kde je vedené. Tohle je
     * jediné místo, kde se tabulka vyhodnocuje — a jde zavolat i z testu.
     *
     * @param string $code kanonický kód z {@see EpoOkecCodebook::normalize()}
     * @return array{reason:string,rows:array<string,list<string>>}|null
     */
    public static function suggestFor(string $code): ?array
    {
        $canonical = str_pad($code, 6, '0', STR_PAD_LEFT);
        foreach (self::NACE_RULES as $rule) {
            foreach ($rule['prefixes'] as $prefix) {
                if (str_starts_with($canonical, $prefix)) {
                    return ['reason' => $rule['reason'], 'rows' => self::allowedOnly($rule['rows'])];
                }
            }
        }

        return null;
    }

    /**
     * Období, ze kterého se berou obraty: to, ve kterém běží dnešek, jinak
     * nejnovější založené. Je to orientační podklad pro nastavení, ne sestava —
     * proto stačí jedno období a odpověď říká, které to bylo.
     *
     * @return array<string,mixed>|null
     */
    private function referencePeriod(int $supplierId): ?array
    {
        $current = $this->periods->findForDate($supplierId, date('Y-m-d'));
        if ($current !== null) {
            return $current;
        }
        $all = $this->periods->listForTenant($supplierId);

        return $all[0] ?? null;
    }

    /**
     * Obraty nabízených řádků obou variant VZZ.
     *
     * Čte se přes {@see FinancialStatementService}, ne vlastním SQL nad hlavní
     * knihou: mapa účtů na řádky výkazu má jediného vlastníka a druhá kopie
     * pravidel by se s ní rozešla. Cenou je, že se sestavuje celý výkaz —
     * u nastavení se to udělá jednou, ne při každém dotazu.
     *
     * Účelové členění spadne, chybí-li účtu přiřazení funkci; to je legitimní
     * stav firmy, která ho nepoužívá, a nesmí kvůli němu zmizet nápověda
     * u druhového členění. Proto se každá varianta zkouší zvlášť.
     *
     * @return array<string,array<string,float>>
     */
    private function amounts(int $supplierId, int $periodId): array
    {
        $out = [];
        foreach (FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS as $type => $codes) {
            try {
                $statement = $type === FinancialStatementService::TYPE_PURPOSE
                    ? $this->statements->incomeStatementByFunction($supplierId, $periodId, null, 'full')
                    : $this->statements->incomeStatement($supplierId, $periodId, null, 'full');
            } catch (\Throwable) {
                continue;
            }
            $wanted = array_flip($codes);
            $values = [];
            foreach ($statement['rows'] as $row) {
                $code = (string) $row['row_code'];
                if (array_key_exists($code, $wanted)) {
                    $values[$code] = (float) $row['amount'];
                }
            }
            if ($values !== []) {
                $out[$type] = $values;
            }
        }

        return $out;
    }

    private function supplierNaceCode(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare('SELECT cz_nace_code FROM supplier WHERE id = ? LIMIT 1');
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
