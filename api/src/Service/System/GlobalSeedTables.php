<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Jediné místo, které ví, které tabulky jsou GLOBÁLNÍ (legislativní číselníky,
 * katalogy, evidence schématu, provozní údaje instance) a které jsou uživatelská data.
 *
 * Proč to není jen seznam v `api/bin/reset.php`: reset maže VŠECHNY tabulky kromě
 * keep-listu a globální seedy z migrací po smazání nikdo nevrátí, protože migrace
 * jsou evidované jako proběhlé. Keep-list ve skriptu tiše zaostal za migracemi
 * (svátky 1781, katalog klíčových slov nákladů 1740) a lhůty mezd pak běžely
 * z pojistky v kódu. Konstanty tady čte reset, dotah seedů
 * ({@see GlobalSeedRestorer}), Diagnostika a guard `ResetKeepsGlobalCodebooksTest`,
 * který každou tabulku plněnou migrací nutí zařadit sem.
 */
final class GlobalSeedTables
{
    /**
     * Tabulky, které reset ponechá CELÉ. Hodnota = proč.
     *
     * @var array<string, string>
     */
    public const RESET_KEEP = [
        'countries'      => 'globální číselník zemí',
        'vat_rates'      => 'globální sazby DPH',
        'units'          => 'globální měrné jednotky',
        'tax_constants'  => 'globální daňové konstanty',
        'exchange_rates' => 'cache kurzů ČNB, drahé refetchnout',
        'migrations'     => 'evidence schématu',
        // Značky jednorázových datových převodů v migracích (1194/1204). Patří
        // k evidenci schématu: bez nich by opakované spuštění migrace převod
        // zopakovalo nad daty, která už mají jiný tvar.
        'payroll_data_migration_markers' => 'evidence jednorázových převodů v migracích',
        // Účetní globální seedy z migrací.
        'statement_versions'    => 'definice výkazů (rozvaha/VZZ, vyhl. 500/2002), seed 1012',
        'statement_rows'        => 'řádky výkazů, seed 1012',
        'statement_account_map' => 'mapování účtů na řádky výkazů, seed 1012',
        'cnb_repo_rates'        => 'repo sazby ČNB pro úrok z prodlení, seed 1048',
        'bank_rule_template_defaults' => 'výchozí katalog bankovních pravidel pro nové firmy, seed 1748',
        'remittance_map'        => 'globální mapa odvodů na účty ČNB, seed 1056',
        // Legislativní číselník sazeb členských států (seed 1152/1292/1294, dotah 1319).
        // Když zmizí, import i vystavení odmítne každý doklad se sazbou vyšší než 0 %.
        'oss_member_state_rates' => 'sazby DPH členských států EU',
        // Svátky podle z. 245/2000 Sb. posouvají přes § 33 odst. 4 daňového řádu
        // všechny lhůty podání i odvodů ze mzdy.
        'public_holidays' => 'státní a ostatní svátky, seed 1781',
        // Globální katalog klíčových slov pro průvodce nastavením účtování.
        'expense_keyword_catalog' => 'katalog klíčových slov nákladů, seed 1740/1742/1743',
        // Provozní údaje INSTANCE, ne uživatelská data. `license` by se po smazání
        // založila znovu jako TRIAL, kontrakt záloh by dostal výchozí hodnoty místo
        // sjednaných a bez `cron_settings` spadne režim plánovaných úloh na
        // `individual`, takže na instalaci s dispatcherem hostingu NEBĚŽÍ NIC.
        'license'                  =>'licence instance, znovuzaložení ji degraduje na trial',
        'backup_schedule_contract' => 'parametry sjednané s poskytovatelem hostingu',
        'instance_storage_usage'   => 'podklad pro měření úložiště u poskytovatele',
        'cron_settings'            => 'režim plánovaných úloh, migrace 1184/1320',
    ];

    /**
     * Cache, které reset ponechá jen s `--keep-cache`.
     *
     * @var list<string>
     */
    public const RESET_KEEP_CACHE = ['ares_cache', 'vies_cache', 'crpdph_cache'];

    /**
     * Tabulky se smíšeným obsahem: reset smaže jen řádky splňující podmínku,
     * globální seed zůstane.
     *
     * @var array<string, string>
     */
    public const RESET_PARTIAL = [
        'vat_classifications'         => 'supplier_id IS NOT NULL',
        'bank_email_notice_providers' => 'supplier_id IS NOT NULL',
        'posting_rules'               => 'supplier_id IS NOT NULL',
        // Příjemci podání (ČSSZ e-Podání, zdravotní pojišťovny vč. ID datových schránek),
        // seed 1381/1410/1535. Per-tenant override firmy se maže.
        'submission_recipients'       => 'supplier_id IS NOT NULL',
        'role_permissions'            => 'role_id NOT IN (SELECT id FROM roles WHERE system_key IS NOT NULL)',
        'roles'                       => 'system_key IS NULL',
    ];

    /**
     * Tabulky, které migrace plní a které nemají `supplier_id`, a přesto je reset
     * ZÁMĚRNĚ maže. Hodnota = proč. Guard test tu výjimku uděluje jen jmenovitě.
     *
     * @var array<string, string>
     */
    public const RESET_WIPES = [
        'activity_log_chain_head' => 'hlava hash řetězu musí padnout spolu s auditním logem, jinak by ukazovala na smazané záznamy',
        'cron_heartbeat'          => 'obnoví se sám při nejbližším běhu plánovaných úloh',
        'client_revenue_cache'    => 'přepočitatelná cache obratů klientů (per-tenant přes klienta)',
        'project_revenue_cache'   => 'přepočitatelná cache obratů projektů (per-tenant přes projekt)',
        'bank_counterparty_observations' => 'per-tenant data přes bank_counterparty_map',
        'signing_credentials'     => 'per-tenant klíče přes signing_profiles',
    ];

    /**
     * Globální číselníky, které nesmí být prázdné. Hodnota = podmínka globálních
     * řádků (null = celá tabulka). Čte je Diagnostika.
     *
     * @var array<string, string|null>
     */
    public const CODEBOOKS = [
        'countries'                   => null,
        'vat_rates'                   => null,
        'units'                       => null,
        'statement_versions'          => null,
        'statement_rows'              => null,
        'statement_account_map'       => null,
        'cnb_repo_rates'              => null,
        'bank_rule_template_defaults' => null,
        'remittance_map'              => null,
        'oss_member_state_rates'      => 'is_custom = 0',
        'public_holidays'             => null,
        'expense_keyword_catalog'     => null,
        'vat_classifications'         => 'supplier_id IS NULL',
        'posting_rules'               => 'supplier_id IS NULL',
        'bank_email_notice_providers' => 'supplier_id IS NULL',
        'submission_recipients'       => 'supplier_id IS NULL',
        'roles'                       => 'system_key IS NOT NULL',
    ];

    /**
     * Seedy, které umí {@see GlobalSeedRestorer} přehrát z migrací: tabulka =>
     * [podmínka globálních řádků nebo null, migrace v pořadí]. Přehrávají se jen
     * INSERT/UPDATE statementy mířící na tabulku; guard test hlídá, že seznam migrací
     * je úplný, INSERTy jsou idempotentní a žádná migrace z tabulky nemaže.
     *
     * Sazby členských států tu nejsou: mají vlastní sebeopravnou migraci 1319
     * a dotah `backfill-oss-rates.php`.
     *
     * @var array<string, array{global: string|null, migrations: list<string>}>
     */
    public const RESTORABLE = [
        'public_holidays' => [
            'global'     => null,
            'migrations' => ['1781_public_holidays.sql'],
        ],
        'expense_keyword_catalog' => [
            'global'     => null,
            'migrations' => [
                '1740_accounting_setup_assistant.sql',
                '1742_accounting_setup_catalog_languages.sql',
                '1743_accounting_setup_catalog_vetoes.sql',
            ],
        ],
        'submission_recipients' => [
            'global'     => 'supplier_id IS NULL',
            'migrations' => [
                '1381_submission_channels.sql',
                '1410_submission_recipients_cssz_epodani.sql',
                '1535_health_insurer_recipient_codebook.sql',
                '1536_vozp_payroll_submission_databox.sql',
            ],
        ],
        'payroll_data_migration_markers' => [
            'global'     => null,
            'migrations' => [
                '1194_payroll_employer_payment_identifiers.sql',
                '1204_payroll_employer_payment_identifiers_marker.sql',
            ],
        ],
    ];

    /** @return list<string> */
    public static function resetKeep(bool $keepCache): array
    {
        $keep = array_keys(self::RESET_KEEP);
        return $keepCache ? array_merge($keep, self::RESET_KEEP_CACHE) : $keep;
    }
}
