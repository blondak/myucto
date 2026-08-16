-- MyÚčto.cz — výplatní pravidla dostávají zapisovací API a DB záruku jednoho zbytku.
--
-- PROČ: `payroll_payout_rules` vznikla v migraci 1250 a od té doby do ní neuměl nic
-- zapsat — jediná cesta ke čtení je PayrollRunSnapshotBatchLoader::payoutRules(),
-- který pravidla zmrazí do snapshotu běhu. Tabulka je proto prázdná a
-- PayoutAllocationService::allocate() na prázdné sadě vyhodí výjimku, takže
-- PayrollNetWageLiabilityMaterializer neumí vyrobit závazek čisté mzdy. Praktický
-- důsledek: plný mzdový modul neumí zaplatit NIKOHO. Tahle migrace je DB polovina
-- opravy — aplikační polovinou je PayrollPayoutRuleRepository + …DefaultsService +
-- PayrollPayoutRulesAction.
--
-- CO SE VYNUCUJE: PayoutAllocationService vyžaduje PRÁVĚ JEDNO aktivní pravidlo
-- druhu `remainder` na zaměstnance — bez něj neví, kam poslat zbytek po pevných a
-- procentních alokacích, a se dvěma by rozdělení nebylo jednoznačné. Dodnes to
-- nehlídalo nic: chyba se projevila až při materializaci závazků, tedy dávno po
-- zadání a nad zmrazenými daty, kde se s ní už nedalo nic dělat. Přesouváme
-- kontrolu do databáze, aby ji neobešel ani import, ani ruční SQL.
--
-- JAK: generovaný sloupec `remainder_guard` promítne employee_id JEN u aktivního
-- zbytkového pravidla, jinak je NULL. NULL hodnoty v UNIQUE indexu nekolidují,
-- takže neaktivní ani pevná/procentní pravidla omezení vůbec nevidí a zaměstnanec
-- jich může mít libovolně mnoho. Stejný vzor už tabulka `payroll_employments`
-- používá pro `primary_employee_key` a `legacy_projection_key`.
--
-- Sloupec je VIRTUAL, ne STORED: hodnota je čistá funkce dvou sloupců téhož řádku,
-- nikdy se nečte přímo (slouží jen jako klíč indexu) a MariaDB 11.8 umí nad
-- VIRTUAL sloupcem index bez problémů — ověřeno na tomto schématu. Ušetří to
-- 8 bajtů na řádek a přepočet při každém UPDATE.
--
-- PROČ INDEX MÍSTO CHECK: CHECK neumí sáhnout na jiné řádky tabulky, takže
-- „nejvýše jeden aktivní zbytek na zaměstnance" jím vyjádřit nelze. Aplikace
-- kontrolu duplikuje (PayrollPayoutRuleValidator) jen proto, aby uživatel dostal
-- srozumitelnou českou hlášku místo chyby 1062 — DB zůstává poslední instancí.
--
-- Idempotence: ADD COLUMN / ADD UNIQUE KEY s IF NOT EXISTS podle vzoru 1023, 1177,
-- 1178, 1191, 1374.

SET NAMES utf8mb4;

-- 1) Pojistka pro data, která by index shodila. Zapisovací cesta neexistovala,
--    takže duplicitní aktivní zbytek může pocházet jen z ručního SQL nebo importu.
--    Necháváme platit ten s nejnižším priority_no (při shodě nejnižší id) — to je
--    stejné pořadí, v jakém by pravidla vybral snapshot — a ostatní jen
--    DEAKTIVUJEME. Řádek se nemaže, protože na něj můžou odkazovat zmrazené
--    alokace v payroll_payout_allocations. V praxi je to no-op nad prázdnou
--    tabulkou; kdyby ne, je změna dohledatelná přes is_active = 0.
UPDATE payroll_payout_rules AS rule_row
  JOIN (
    SELECT winner.supplier_id,
           winner.employee_id,
           MIN(winner.priority_no * 100000000000 + winner.id) AS keep_rank
      FROM payroll_payout_rules AS winner
     WHERE winner.allocation_kind = 'remainder'
       AND winner.is_active = 1
     GROUP BY winner.supplier_id, winner.employee_id
  ) AS keeper
    ON keeper.supplier_id = rule_row.supplier_id
   AND keeper.employee_id = rule_row.employee_id
   SET rule_row.is_active = 0
 WHERE rule_row.allocation_kind = 'remainder'
   AND rule_row.is_active = 1
   AND rule_row.priority_no * 100000000000 + rule_row.id <> keeper.keep_rank;

-- 2) Klíč indexu: employee_id jen u aktivního zbytkového pravidla, jinak NULL.
ALTER TABLE payroll_payout_rules
  ADD COLUMN IF NOT EXISTS remainder_guard BIGINT UNSIGNED
      GENERATED ALWAYS AS (
        CASE
          WHEN allocation_kind = 'remainder' AND is_active = 1 THEN employee_id
          ELSE NULL
        END
      ) VIRTUAL
      COMMENT 'klíč pro uq_payroll_payout_rule_single_remainder; NULL = pravidlo omezení nepodléhá';

-- 3) Samotná záruka „právě jeden aktivní zbytek na zaměstnance a firmu".
ALTER TABLE payroll_payout_rules
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_payout_rule_single_remainder
    (supplier_id, remainder_guard);
