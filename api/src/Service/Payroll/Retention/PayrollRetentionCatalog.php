<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

use MyInvoice\Service\Accounting\RetentionPolicy;

/**
 * Zákonné retenční lhůty mzdové agendy jako DATA, ne jako konstanta zapadlá
 * v mazací rutině. Přímý protějšek {@see \MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy}:
 * stejný tvar (hodnota + citace + stav doloženosti), stejný důvod.
 *
 * ── Proč katalog v kódu a ne v tabulce ────────────────────────────────────────
 * Zákonná lhůta je tvrzení o právu, ne nastavení zákazníka. Musí projít revizí
 * v diffu — lhůta, kterou jde tiše přepsat UPDATEem, je horší než ta, co
 * vyžaduje commit, protože podle ní se NEVRATNĚ maže. Tenantní odchylky (delší
 * lhůta ze smlouvy, dodaná lhůta tam, kde zákon mlčí) proto žijí v tabulce
 * `payroll_retention_policies` a katalog jen PŘEBÍJEJÍ SMĚREM NAHORU — viz
 * {@see PayrollRetentionPolicyRepository}.
 *
 * ── Stav doloženosti ──────────────────────────────────────────────────────────
 * `REPO_VERIFIED`        citace ověřená proti zdroji v repozitáři.
 * `EXTERNAL_UNVERIFIED`  lhůta i zákon známé, doklad v repozitáři není.
 * `UNDETERMINED`         zákonná lhůta se nenašla. `retention_years` je `null`
 *                        a kategorie se NIKDY nenavrhne k výmazu. Není to
 *                        „zapomněli jsme doplnit" — je to výslovné odmítnutí
 *                        odhadu, protože odhadnutá lhůta maže cizí data.
 *
 * K DNEŠNÍMU DNI NENÍ ANI JEDNA KATEGORIE `REPO_VERIFIED`. Citace pocházejí
 * z odborné praxe, ne z ověřeného znění sbírky — při psaní katalogu nebyl
 * primární zdroj dostupný. Konstanta `REPO_VERIFIED` tu je proto, aby bylo kam
 * postoupit, až se citace doloží; do té doby je stav vidět v API i v položce
 * návrhu výmazu, takže schvalující ví, jak pevná ta lhůta je. Zejména čísla
 * ustanovení u kategorií, kde je `section` vyplněná, si zaslouží kontrolu
 * proti platnému znění dřív, než se podle nich poprvé smaže.
 *
 * ── Co katalog VĚDOMĚ nemodeluje ──────────────────────────────────────────────
 * 1. Zkrácenou lhůtu mzdových listů pro poživatele starobního důchodu. Existuje,
 *    ale její uplatnění stojí na údaji o pobírání důchodu, který modul nedrží
 *    spolehlivě. Kratší lhůta uplatněná na základě nejistého údaje maže dřív, než
 *    smí — proto se modeluje jen delší, bezpečná varianta.
 * 2. Lhůtu pro stanovení daně (§ 148 daňového řádu). Je to lhůta pro správce daně,
 *    ne uchovávací povinnost zaměstnavatele; míchat je dohromady by vyrobilo
 *    pravidlo, které v zákoně není.
 *
 * ── Účetní lhůtu si katalog NEDRŽÍ ────────────────────────────────────────────
 * Kategorie `accounting_records` nemá vlastní číslo. Lhůtu si bere z
 * {@see RetentionPolicy}, která je v aplikaci zdrojem pravdy pro § 31 ZoÚ už od
 * účetní strany. Dvě čísla pro tutéž lhůtu jsou přesně ta třída chyby, před
 * kterou varuje AGENTS.md: novela by opravila jedno a druhé by tiše mazalo dál.
 * Shodu hlídá test {@see \MyInvoice\Tests\Unit\Payroll\PayrollRetentionCatalogTest}.
 *
 * ── Otevřené riziko ───────────────────────────────────────────────────────────
 * U účetních záznamů katalog cituje zákon č. 563/1991 Sb. Rekodifikace účetnictví
 * byla v legislativním procesu a účinnost nového zákona se posouvala; NEOVĚŘENO,
 * které znění je k dnešnímu dni účinné. Lhůty 5 a 10 let jsou v obou zněních
 * shodné, takže výpočet tím netrpí, ale citace může být zastaralá.
 */
final class PayrollRetentionCatalog
{
    public const PAYROLL_SHEET = 'payroll_sheet';
    public const PENSION_EVIDENCE = 'pension_evidence';
    public const PENSION_EVIDENCE_SHEETS = 'pension_evidence_sheets';
    public const SOCIAL_CONTRIBUTIONS = 'social_contributions';
    public const SICKNESS_INSURANCE = 'sickness_insurance';
    public const HEALTH_INSURANCE = 'health_insurance';
    public const ACCOUNTING_RECORDS = 'accounting_records';
    public const WORKING_TIME = 'working_time';
    public const GARNISHMENT = 'garnishment';

    /** Citace ověřená proti zdroji v repozitáři. */
    public const REPO_VERIFIED = 'repo_verified';
    /** Lhůta i zákon známé, doklad v repozitáři není. */
    public const EXTERNAL_UNVERIFIED = 'external_unverified';
    /** Zákonná lhůta se nenašla — kategorie se k výmazu nenavrhne. */
    public const UNDETERMINED = 'undetermined';

    /** Kalendářní roky následující po roce, kterého se záznam týká. */
    public const BASIS_CALENDAR_YEARS = 'calendar_years_after_record_year';
    /** Roky počínající koncem účetního období, kterého se záznam týká. */
    public const BASIS_ACCOUNTING_PERIOD = 'years_after_accounting_period_end';

    private const ACT_SOCIAL_ORGANISATION = 'zákon č. 582/1991 Sb.';
    private const ACT_SICKNESS = 'zákon č. 187/2006 Sb.';
    private const ACT_HEALTH_PREMIUMS = 'zákon č. 592/1992 Sb.';
    private const ACT_ACCOUNTING = 'zákon č. 563/1991 Sb., o účetnictví';
    private const ACT_LABOUR_CODE = 'zákon č. 262/2006 Sb., zákoník práce';
    private const ACT_CIVIL_PROCEDURE = 'zákon č. 99/1963 Sb., občanský soudní řád';

    /**
     * @var array<string,array{
     *   label:string,retention_years:?int,basis:string,act:string,section:?string,
     *   source_status:string,accounting_relevant:bool,
     *   employee_tables:list<string>,employment_tables:list<string>,note:string
     * }>
     */
    private const RULES = [
        self::PAYROLL_SHEET => [
            'label' => 'Mzdové listy',
            'retention_years' => 30,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 zákona č. 582/1991 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => true,
            'employee_tables' => [
                'payroll_monthly_records',
                'payroll_generated_documents',
                'payroll_annual_document_revisions',
            ],
            'employment_tables' => [],
            'note' => 'Povinnost VÉST mzdový list plyne z § 38j zákona č. 586/1992 Sb.; '
                . 'lhůtu pro jeho uschování stanoví až předpis o sociálním zabezpečení, '
                . 'a to pro účely důchodového pojištění. Je to nejdelší lhůta v celé '
                . 'agendě a v praxi určuje, kdy vůbec smí osoba zmizet.',
        ],
        self::PENSION_EVIDENCE_SHEETS => [
            'label' => 'Stejnopisy evidenčních listů důchodového pojištění',
            'retention_years' => 3,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => null,
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => false,
            'employee_tables' => ['payroll_jmhz_eldp_evidence_snapshots'],
            'employment_tables' => [],
            'note' => 'Lhůta 3 kalendářní roky po roce, kterého se ELDP týká, je běžně '
                . 'uváděná; PŘESNÉ USTANOVENÍ NEOVĚŘENO, proto je uvedený jen zákon. '
                . 'Krátká lhůta sama o sobě nic neuvolní — osobu drží mzdový list.',
        ],
        self::PENSION_EVIDENCE => [
            'label' => 'Záznamy pro účely důchodového pojištění',
            'retention_years' => 30,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 zákona č. 582/1991 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => false,
            'employee_tables' => [
                'payroll_jmhz_ordinary_evidence_snapshots',
                'payroll_person_social_jurisdictions',
                'payroll_person_social_discount_claims',
                'payroll_person_external_ids',
            ],
            'employment_tables' => [],
            'note' => 'Účetní záznamy o údajích potřebných pro účely důchodového '
                . 'pojištění sdílejí lhůtu s mzdovými listy.',
        ],
        self::SOCIAL_CONTRIBUTIONS => [
            'label' => 'Záznamy pro stanovení a odvod pojistného na sociální zabezpečení',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 zákona č. 582/1991 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => true,
            'employee_tables' => [
                'payroll_statutory_person_results',
                'payroll_statutory_accumulator_entries',
                'payroll_statutory_accumulator_openings',
            ],
            'employment_tables' => [],
            'note' => 'Kratší lhůta než u důchodových údajů — týká se odvodu pojistného, '
                . 'ne nároku na důchod. Sama nic neuvolní, viz maximum přes kategorie.',
        ],
        self::SICKNESS_INSURANCE => [
            'label' => 'Záznamy pro nemocenské pojištění',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_SICKNESS,
            'section' => null,
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => false,
            'employee_tables' => [],
            'employment_tables' => ['payroll_absences'],
            'note' => 'Lhůta 10 kalendářních roků je běžně uváděná; PŘESNÉ USTANOVENÍ '
                . 'NEOVĚŘENO, proto je uvedený jen zákon. Nositelem evidence je záznam '
                . 'o nepřítomnosti — `payroll_sickness_events` je jen dopočet nad ním '
                . 'a vlastní vazbu na osobu nemá, takže by se v sondě choval jako '
                . 'tabulka bez vlastníka.',
        ],
        self::HEALTH_INSURANCE => [
            'label' => 'Záznamy pro zdravotní pojištění',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_HEALTH_PREMIUMS,
            'section' => null,
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => true,
            'employee_tables' => [
                'payroll_person_health_coverage_history',
                'payroll_person_health_month_evidence',
                'payroll_person_health_minimum_reductions',
                'payroll_person_health_other_employer_bases',
            ],
            'employment_tables' => [],
            'note' => 'Povinnost vést průkaznou evidenci o platbách pojistného a uschovat '
                . 'ji 10 kalendářních roků je běžně uváděná; PŘESNÉ USTANOVENÍ NEOVĚŘENO.',
        ],
        self::ACCOUNTING_RECORDS => [
            // `null` tady NEZNAMENÁ neurčenou lhůtu — accounting_records si ji bere
            // z RetentionPolicy (viz rule()). Stav doloženosti proto není UNDETERMINED.
            'label' => 'Účetní doklady a účetní záznamy',
            'retention_years' => null,
            'basis' => self::BASIS_ACCOUNTING_PERIOD,
            'act' => self::ACT_ACCOUNTING,
            'section' => '§ 31 odst. 2 zákona č. 563/1991 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'accounting_relevant' => true,
            'employee_tables' => [
                'payroll_payment_liabilities',
                'payroll_payout_allocations',
                'payroll_deduction_ledger',
                'payroll_benefit_accumulators',
            ],
            'employment_tables' => [],
            'note' => 'Účetní doklady a knihy 5 let od konce účetního období; účetní '
                . 'závěrka a výroční zpráva 10 let — ty ale nejsou vázané na osobu, '
                . 'takže je tenhle katalog neřeší. Účetní záznam se NIKDY neruší '
                . 'řádkově: osobní údaj z něj zmizí anonymizací, částka zůstane.',
        ],
        self::WORKING_TIME => [
            'label' => 'Evidence pracovní doby',
            'retention_years' => null,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_LABOUR_CODE,
            'section' => '§ 96 zákona č. 262/2006 Sb.',
            'source_status' => self::UNDETERMINED,
            'accounting_relevant' => false,
            'employee_tables' => [],
            'employment_tables' => [
                'payroll_time_entries',
                'payroll_absences',
                'payroll_leave_ledger',
                'payroll_overtime_consents',
            ],
            'note' => 'Zákoník práce evidenci pracovní doby PŘIKAZUJE VÉST, ale lhůtu '
                . 'pro její uchování NESTANOVÍ. Odvozovat ji z promlčecí doby je úvaha, '
                . 'ne zákon — proto zůstává neurčená a osobu s evidencí pracovní doby '
                . 'modul k výmazu nenavrhne, dokud lhůtu nedodá tenant vlastní politikou.',
        ],
        self::GARNISHMENT => [
            'label' => 'Doklady k exekučním srážkám',
            'retention_years' => null,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'act' => self::ACT_CIVIL_PROCEDURE,
            'section' => null,
            'source_status' => self::UNDETERMINED,
            'accounting_relevant' => true,
            'employee_tables' => [
                'payroll_enforcement_cases',
                'payroll_enforcement_dependants',
                'payroll_enforcement_month_results',
                'payroll_enforcement_person_month_evidence',
                'payroll_deduction_agreements',
            ],
            'employment_tables' => [],
            'note' => 'Předpisy o srážkách ze mzdy ukládají plátci mzdy povinnosti '
                . 'a odpovědnost vůči oprávněnému, ale VLASTNÍ UCHOVÁVACÍ LHŮTU pro '
                . 'jeho spis NESTANOVÍ. Sražené částky samotné jsou součástí mzdového '
                . 'listu, takže je kryje jeho třicetiletá lhůta; spis k exekuci ale '
                . 'oporu nemá a zůstává neurčený.',
        ],
    ];

    /**
     * Katalog musí jít ZAVOLAT (AGENTS.md) — schématový i architektonický test
     * proti němu ověřují, že každá uvedená tabulka existuje a že žádná kategorie
     * nezůstala bez citace.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::RULES);
    }

    public static function has(string $category): bool
    {
        return isset(self::RULES[$category]);
    }

    public static function rule(string $category): PayrollRetentionRule
    {
        $rule = self::RULES[$category] ?? null;
        if ($rule === null) {
            throw new \InvalidArgumentException(
                'Neznámá retenční kategorie mzdové agendy.',
            );
        }

        return new PayrollRetentionRule(
            $category,
            $rule['label'],
            $category === self::ACCOUNTING_RECORDS
                ? RetentionPolicy::retentionYears(RetentionPolicy::ACCOUNTING_RECORDS)
                : $rule['retention_years'],
            $rule['basis'],
            $rule['act'],
            $rule['section'],
            $rule['source_status'],
            $rule['accounting_relevant'],
            $rule['employee_tables'],
            $rule['employment_tables'],
            $rule['note'],
        );
    }

    /** @return list<PayrollRetentionRule> */
    public static function rules(): array
    {
        return array_map(
            static fn (string $category): PayrollRetentionRule => self::rule($category),
            self::categories(),
        );
    }

    /**
     * Všechny tabulky, které katalog sleduje — sonda pro schématový test, aby
     * nová tabulka s osobními údaji nezůstala mimo retenci.
     *
     * @return list<string>
     */
    public static function trackedTables(): array
    {
        $tables = [];
        foreach (self::RULES as $rule) {
            foreach ($rule['employee_tables'] as $table) {
                $tables[] = $table;
            }
            foreach ($rule['employment_tables'] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }
}
