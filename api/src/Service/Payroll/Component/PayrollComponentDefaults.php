<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Výchozí číselník mzdových složek — VERZOVANĚ.
 *
 * Tabulka dřív bydlela jako `PayrollComponentRepository::DEFAULTS` a zakládala se
 * s natvrdo napsaným `valid_from = '2026-01-01'`. To mělo dvě následky:
 *
 *  1. Legislativní změnu klasifikace nešlo do existujících firem rozvést vůbec —
 *     `INSERT IGNORE` narazil na unikátní klíč `(supplier_id, code, valid_from)`
 *     a tiše neudělal nic. Firma založená loni zůstala navždy na staré klasifikaci.
 *  2. Roční limit osvobození benefitů (`annual_limit_minor`) ve VÝČTU VKLÁDANÝCH
 *     SLOUPCŮ vůbec nebyl, takže zůstal NULL. `PayrollInputRepository::approve()`
 *     přitom limit hlídá jen tehdy, když NENÍ NULL — u výchozích složek se tedy
 *     roční strop nehlídal vůbec a benefit prošel v jakékoli výši.
 *
 * Verzuje se stejně jako podmínky pracovního vztahu a ruleset: nová verze VZNIKNE
 * VEDLE té staré a předchozí otevřené verzi se dopočítá `valid_to` na den před
 * účinností nové. Historie se nepřepisuje — mzdový vstup schválený loni si drží
 * `component_snapshot_json` a nadále ukazuje na verzi, která tehdy platila.
 *
 * Zákonné částky se sem NEPÍŠOU. Roční limit se u složky uvádí kanonickým klíčem
 * parametru rulesetu a hodnotu k němu vytáhne {@see limitMinor()} z rulesetu
 * účinného k `valid_from` dané verze klasifikace. Nová průměrná mzda tedy znamená
 * nový ruleset a novou verzi klasifikace, ne editaci čísla v kódu.
 */
final class PayrollComponentDefaults
{
    /**
     * Verze výchozí klasifikace, chronologicky. Sloupce řádku:
     *
     *  0 kód, 1 název, 2 druh složky, 3 peněžní/nepeněžní, 4 četnost,
     *  5 daň, 6 sociální (účast i vyměřovací základ), 7 zdravotní (účast
     *  i vyměřovací základ), 8 průměrný výdělek, 9 exekuční srážky, 10 JMHZ,
     *  11 statistika, 12 kanonický klíč ročního limitu v rulesetu daně z příjmů
     *     (NULL = složka roční limit nemá; důvod je u konkrétního řádku).
     *
     * @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string}>}>
     */
    private const VERSIONS = [
        [
            'valid_from' => '2026-01-01',
            'rows' => [
                ['MZDA_MESICNI', 'Základní měsíční mzda', 'base_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['MZDA_HODINOVA', 'Základní hodinová mzda', 'hourly_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['MZDA_UKOLOVA', 'Úkolová mzda', 'task_wage', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['ODMENA', 'Odměna', 'bonus', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['PREMIE_PRIPLATKY', 'Prémie a příplatky', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['PROVIZE', 'Provize', 'commission', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['NAHRADA_MZDY', 'Náhrada mzdy', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null],
                ['ODSTUPNE', 'Odstupné', 'severance', 'monetary', 'one_off', 'included', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included', null],
                ['NAHRADA_KONKURENCNI_DOLOZKA', 'Náhrada za konkurenční doložku', 'competitive_clause', 'monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null],
                ['DOPLATEK_MZDY', 'Doplatek mzdy za minulé období', 'backpay', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null],
                ['NEPENEZNI_PRIJEM', 'Nepeněžní příjem', 'non_cash', 'non_monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null],
                // Limit § 6 odst. 9 písm. b) ZDP je ZA SMĚNU (70 % horní hranice
                // stravného 5–12 h), ne za rok — roční strop složky ho nevyjádří.
                // Ruleset to nese jako vědomé ruční posouzení
                // `benefit_exemption.meal.per_shift`.
                ['PRISPEVEK_STRAVOVANI', 'Příspěvek na stravování', 'benefit_meal', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null],
                // Soukromé užití vozidla je podle § 6 odst. 6 ZDP OCENĚNÍ příjmu
                // (1 % / 0,5 % / 0,25 % vstupní ceny měsíčně), ne osvobozený
                // benefit — žádný roční strop osvobození neexistuje.
                ['SOUKROME_VOZIDLO', 'Soukromé užití vozidla', 'benefit_vehicle', 'non_monetary', 'regular', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null],
                ['PRISPEVEK_PENZE_ZIVOTNI', 'Příspěvek na penzijní a životní produkty', 'benefit_pension', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'benefit_exemption.old_age_savings.yearly'],
                // § 6 odst. 9 písm. p) ZDP sdílí 50 000 Kč s příspěvkem na produkty
                // spoření na stáří, ale jde-li o jinou formu podpory dlouhodobé péče
                // než pojištění, spadá jinam. Zařazení tenhle číselník neurčí, takže
                // limit zůstává prázdný a vyplní ho účetní.
                ['PRISPEVEK_DLOUHODOBA_PECE', 'Příspěvek na dlouhodobou péči', 'benefit_care', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null],
                // Vzdělávání má DVA různé režimy: odborný rozvoj související
                // s předmětem činnosti zaměstnavatele je podle § 6 odst. 9 písm. a)
                // ZDP osvobozený BEZ limitu, ostatní vzdělávání spadá pod strop
                // § 6 odst. 9 písm. d) bodu 2. Který z nich platí, plyne z náplně
                // kurzu — proto se tu limit netvrdí; naslepo nasazený strop by
                // blokoval schválení legitimního školení.
                ['VZDELAVANI', 'Vzdělávání zaměstnance', 'benefit_education', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null],
                ['REKREACE_VOLNY_CAS', 'Rekreace a volnočasový benefit', 'benefit_recreation', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'benefit_exemption.non_cash_leisure.yearly'],
                ['ZDRAVOTNI_BENEFIT', 'Zdravotní benefit', 'benefit_health', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'benefit_exemption.non_cash_health.yearly'],
                ['PRISPEVEK_RIZIKOVE_SPORENI', 'Povinný příspěvek na spoření u rizikové práce', 'risky_savings', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null],
                ['CESTOVNI_NAHRADA', 'Cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'manual_review', 'excluded', 'excluded', 'excluded', 'excluded', 'manual_review', 'included', null],
                // MZ-08-W07 — klasifikovaný rozpad vyúčtování pracovní cesty. Do zákonného
                // limitu (§ 6 odst. 7 písm. a) ZDP) není náhrada předmětem daně, pojistného,
                // průměrného výdělku ani exekučních srážek; nadlimitní část je běžný
                // zdanitelný příjem ze závislé činnosti a vstupuje do vyměřovacích základů.
                ['CESTOVNI_NAHRADA_LIMIT', 'Cestovní náhrada do zákonného limitu', 'travel_reimbursement', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', null],
                ['CESTOVNI_NAHRADA_NADLIMIT', 'Nadlimitní cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null],
            ],
        ],
    ];

    /** @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string}>}> */
    private array $catalog;

    /**
     * @param list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string}>}>|null $catalog
     *        Jen pro testy verzování; runtime bere vestavěnou sadu.
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?array $catalog = null,
    ) {
        $this->catalog = $catalog ?? self::VERSIONS;
    }

    /**
     * Kódy složek, které si aplikace zakládá sama, napříč všemi verzemi.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::VERSIONS as $version) {
            foreach ($version['rows'] as $row) {
                $codes[$row[0]] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Verze klasifikace se zákonnými limity už dosazenými z rulesetu.
     *
     * Verze, ke které není účinný ruleset daně z příjmů, se PŘESKOČÍ celá. Založit
     * ji bez limitu by znamenalo tiše vypnout hlídání ročního stropu — přesně vada,
     * kvůli které tahle třída vznikla. Ostatní verze se založí normálně.
     *
     * @return list<array{valid_from:string, rows:list<array{
     *   code:string, name:string, component_kind:string, value_kind:string,
     *   frequency_kind:string, tax_treatment:string,
     *   social_treatment:string, health_treatment:string,
     *   average_earning_treatment:string, enforcement_treatment:string,
     *   jmhz_treatment:string, statistics_treatment:string,
     *   annual_limit_minor:?int
     * }>}>
     */
    public function versions(): array
    {
        $versions = [];
        foreach ($this->catalog as $version) {
            $rows = [];
            foreach ($version['rows'] as $row) {
                try {
                    $limit = $this->limitMinor($row[12], $version['valid_from']);
                } catch (PayrollRulesetException) {
                    continue 2;
                }
                $rows[] = [
                    'code' => $row[0],
                    'name' => $row[1],
                    'component_kind' => $row[2],
                    'value_kind' => $row[3],
                    'frequency_kind' => $row[4],
                    'tax_treatment' => $row[5],
                    'social_treatment' => $row[6],
                    'health_treatment' => $row[7],
                    'average_earning_treatment' => $row[8],
                    'enforcement_treatment' => $row[9],
                    'jmhz_treatment' => $row[10],
                    'statistics_treatment' => $row[11],
                    'annual_limit_minor' => $limit,
                ];
            }
            $versions[] = ['valid_from' => $version['valid_from'], 'rows' => $rows];
        }
        usort(
            $versions,
            static fn (array $left, array $right): int => $left['valid_from'] <=> $right['valid_from'],
        );

        return $versions;
    }

    /**
     * Ruleset se bere `forDate()`, ne `forCalculation()`: výchozí číselník je
     * jen SEED, který si firma smí přepsat, a nesmí spadnout jen proto, že
     * sada ještě není odborně schválená.
     */
    private function limitMinor(?string $key, string $validFrom): ?int
    {
        if ($key === null) {
            return null;
        }
        $value = $this->rulesets
            ->forDate(PayrollRulesetDomain::IncomeTax, $validFrom)
            ->parameter($key)
            ->value;
        if (!is_int($value)) {
            throw new PayrollRulesetException(
                "Roční limit {$key} není částka v haléřích.",
            );
        }

        return $value;
    }
}
