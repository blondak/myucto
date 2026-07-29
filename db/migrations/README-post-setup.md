# Migrace vs. setup wizard — kam patří seed dat

## Problém

Migrace běží **před** tím, než existuje firma. Nové nasazení vypadá takhle:

```
migrate.php   →   setup.php / setup wizard   →   uživatel zapne účetnictví
(žádný supplier)      (vzniká supplier)            (vzniká účtová osnova)
```

Migrace, která seeduje **per-tenant** data (`WHERE supplier_id = …`, `JOIN supplier`,
analytiky do `chart_of_accounts`, předkontace, bankovní pravidla), tedy na čerstvém
nasazení **nemá co potkat a proběhne jako no-op**. A protože se zapíše do tabulky
`migrations`, **už se nikdy nespustí**. Firma založená o minutu později data nedostane.

Stejně dopadne každé spuštění `api/bin/reset.php` — smaže per-tenant data a
`migrate.php` je neobnoví.

Tohle není hypotéza: přesně takhle přišla nová nasazení o analytiky z migrace
`1127_vehicle_expense_analytics.sql` (viz `1130_vehicle_analytics_reseed.sql`).

## Pravidlo

> **Kód je zdroj pravdy pro nové firmy. Migrace je jen doručení do těch stávajících.**

Cokoliv má dostat **každá** firma, musí být v kódu, který se pouští při provisioningu:

| Co | Kde je zdroj pravdy | Kdy se spustí |
|---|---|---|
| Účtová osnova vč. analytik | `ChartOfAccountsTemplate::ACCOUNTS` | `ChartOfAccountsSeeder::seedForSupplier()` — aktivace účetnictví, změna režimu, backfill |
| Override předkontace k analytikám | `ChartOfAccountsSeeder::seedAnalyticPostingRules()` | tamtéž |
| Globální předkontace, klasifikace DPH, bankovní šablony | seed v migraci s `supplier_id IS NULL` | jednou; `reset.php` je chrání přes `$partial` / `$keep` |

Nová migrace, která přidává per-tenant default, se proto píše **ve dvou krocích**:

1. Přidej to do šablony / seederu v kódu → dostanou to nové firmy.
2. Napiš migraci, která totéž doplní existujícím firmám → idempotentně, gate-ovaně
   přes `NOT EXISTS`, a s `JOIN` na `supplier` (ne literál `supplier_id = 1`, ten
   na čerstvém nasazení spadne na FK — viz `1091`, commit `c4fdc8b3`).

## Kdy je no-op migrace v pořádku

Když seed **není** produktový default, ale **jednorázová oprava dat konkrétní firmy**
(např. `1091` = pravidla pro termínovaný vklad Creditas firmy `supplier_id = 1`,
`1109` = analytiky konkrétních EUR účtů). Takové migrace do kódu nepatří — nová firma
je mít nemá. Ale je potřeba vědět, že **nejsou reprodukovatelné**: po `reset.php` jsou
pryč a musí se zadat ručně v UI.

Takovou migraci označ v hlavičce komentářem, ať je to zjevné:

```sql
-- TENANT-FIX: jednorázová oprava dat firmy supplier_id=1, ZÁMĚRNĚ není v kódu.
-- Po reset.php se neobnoví — v případě potřeby zadat ručně v UI.
```

## Proč ne „post-setup migrace" jako samostatná fáze

Nabízí se udělat druhý adresář (`db/post-setup/`), který by se pouštěl po setupu a pro
každou novou firmu. Zavrženo:

- Byla by to **druhá implementace provisioningu** vedle seederů, které už existují a
  jsou idempotentní a volané z aktivace účetnictví.
- SQL nemá přístup k doménové logice (dědění `tax_deductibility`, resolvery analytik),
  takže by se pravidla dublovala v SQL i v PHP a rozešla se.
- Per-tenant seed se musí umět spustit i **opakovaně a mimo setup** (aktivace účetnictví
  proběhne třeba rok po založení firmy, změna režimu, backfill). To je API seederu,
  ne migrační fáze.

Pokud provisioningu přibude kroků, patří to do jednoho seederu volaného z
`AccountingActivationAction` / `SettingsAction`, ne do nové fáze migrací.

## Kontrola

`api/bin/reset.php --dry-run` vypíše, co by se smazalo a co zůstane — po přidání nové
per-tenant tabulky si tím ověř, že režim `--keep-users-supplier` nechává konfiguraci žít.
