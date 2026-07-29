-- MyÚčto.cz — EP-11: tvrdá tenantová integrita deníku a období na úrovni DB
--
-- NÁLEZ (audit podvojné účetnictví): jednosloupcové FK nebrání tomu, aby
--   journal_entries.period_id      odkazoval na období JINÉHO dodavatele,
--   journal_entry_lines.entry_id   odkazoval na zápis JINÉHO dodavatele,
--   accounting_closing_steps.period_id odkazoval na období jiného dodavatele.
-- Tenantová izolace tak dnes visí jen na aplikačním filtru (PostingService /
-- repository WHERE supplier_id). Tuto invariantu má vynutit DB, ne aplikace.
--
-- ŘEŠENÍ (defense-in-depth za PostingService — vzor migrace 1029, část C):
--   1) UNIQUE(supplier_id, id) na rodičovských tabulkách (accounting_periods,
--      journal_entries) — parent klíč pro složené FK. id je PK (globálně unikátní),
--      takže (supplier_id, id) je triviálně UNIQUE → přidání je BEZPEČNÉ, nemůže
--      selhat na datech.
--   2) Složené FK, které svážou supplier_id child↔parent:
--        journal_entries(supplier_id, period_id)     → accounting_periods(supplier_id, id)
--        journal_entry_lines(supplier_id, entry_id)  → journal_entries(supplier_id, id)
--        accounting_closing_steps(supplier_id, period_id) → accounting_periods(supplier_id, id)
--      DB pak odmítne řádek ukazující na rodiče jiného tenanta (chyba 1452).
--   3) (VOLITELNĚ, viz níže) CHECK(amount > 0) a CHECK(starts_on < ends_on).
--
-- POŘADÍ JE ZÁMĚRNÉ (fail-safe, vzor 1029): nejdřív přidej NOVÝ složený FK a starý
-- jednosloupcový zahazuj až potom. ADD CONSTRAINT validuje existující řádky
-- (MATCH SIMPLE) — kdyby některý řádek odkazoval rodiče jiného tenanta (přesně to,
-- co starý FK dovolil), ADD selže 1452, migrace se NEZAZNAMENÁ a starý FK zůstane
-- zachovaný (tabulka není nikdy bez příslušného FK). Po vyčištění dat proběhne znovu.
-- DROP nového jména před ADD drží idempotenci (MariaDB nemá ADD CONSTRAINT IF NOT EXISTS).
--
-- ON DELETE zachovává stávající chování jednosloupcových FK, které nahrazuje:
--   fk_je_period  = RESTRICT (bez klauzule) → composite také RESTRICT (období se
--                   zápisy nelze smazat; §35 neměnnost)
--   fk_jel_entry  = CASCADE  → composite také CASCADE (smazání zápisu smaže řádky)
--   fk_acs_period = CASCADE  → composite také CASCADE
--
-- POZNÁMKA K SYSTEM VERSIONING (migrace 1029): journal_entries i
-- journal_entry_lines jsou system-versioned. FK + versioning koexistují (1029 to
-- ověřilo na MariaDB 11.8 pro řádky deníku). Zde je navíc RODIČ složeného FK
-- (journal_entries) system-versioned — kombinaci „složený FK cílící na UNIQUE klíč
-- versioned rodiče" DOPORUČUJI před nasazením na sdílenou test DB smoke-testnout na
-- scratch klonu (viz report). Preflighty proti versioned tabulkám používají
-- FOR SYSTEM_TIME ALL, protože ADD CONSTRAINT validuje i historické verze řádků.
--
-- Idempotence: ADD ... KEY IF NOT EXISTS + DROP FOREIGN KEY/INDEX/CONSTRAINT IF EXISTS.

SET NAMES utf8mb4;

-- journal_entries i journal_entry_lines jsou system-versioned (1029) → ALTER, který
-- se dotýká indexů/klíčů/CHECK, vyžaduje tento přepínač, jinak MariaDB odmítne
-- chybou 4119 „Not allowed for system-versioned … Change @@system_versioning_alter_history".
-- Vzor: 1041, 1046, 1099. Je to SESSION proměnná; migrace běží v jednom spojení.
SET @@system_versioning_alter_history = 1;

-- =====================================================================
-- 1) PARENT UNIQUE KEYS (supplier_id, id) — bezpečné, id je PK
--    Preflight: netřeba (id je globálně unikátní → (supplier_id, id) je UNIQUE vždy).
-- =====================================================================
ALTER TABLE accounting_periods
  ADD UNIQUE KEY IF NOT EXISTS uq_ap_supplier_id (supplier_id, id);

ALTER TABLE journal_entries
  ADD UNIQUE KEY IF NOT EXISTS uq_je_supplier_id (supplier_id, id);

-- =====================================================================
-- 2a) journal_entries(supplier_id, period_id) → accounting_periods(supplier_id, id)
--     Child-side index: idx_je_supplier_period (supplier_id, period_id) už existuje (1005).
--     PREFLIGHT (musí vrátit 0 — jinak ADD selže 1452):
--       SELECT COUNT(*) FROM journal_entries je
--         LEFT JOIN accounting_periods p
--                ON p.id = je.period_id AND p.supplier_id = je.supplier_id
--        WHERE p.id IS NULL;
--     Versioned rodič/dítě → autoritativní varianta přes historii:
--       SELECT COUNT(*) FROM journal_entries FOR SYSTEM_TIME ALL je
--         LEFT JOIN accounting_periods p
--                ON p.id = je.period_id AND p.supplier_id = je.supplier_id
--        WHERE p.id IS NULL;
-- =====================================================================
ALTER TABLE journal_entries DROP FOREIGN KEY IF EXISTS fk_je_period_supplier;
ALTER TABLE journal_entries
  ADD CONSTRAINT fk_je_period_supplier
  FOREIGN KEY (supplier_id, period_id)
  REFERENCES accounting_periods (supplier_id, id);
-- Nahrazený jednosloupcový FK + jeho osiřelý auto-index (period_id).
ALTER TABLE journal_entries DROP FOREIGN KEY IF EXISTS fk_je_period;
ALTER TABLE journal_entries DROP INDEX IF EXISTS fk_je_period;

-- =====================================================================
-- 2b) journal_entry_lines(supplier_id, entry_id) → journal_entries(supplier_id, id)
--     Child-side index (supplier_id, entry_id) NEEXISTUJE → přidej ho (idx_jel_entry
--     je jen (entry_id), idx_jel_supplier_account je (supplier_id, account_id)).
--     PREFLIGHT (musí vrátit 0):
--       SELECT COUNT(*) FROM journal_entry_lines l
--         LEFT JOIN journal_entries je
--                ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
--        WHERE je.id IS NULL;
--     Versioned varianta (obě tabulky versioned):
--       SELECT COUNT(*) FROM journal_entry_lines FOR SYSTEM_TIME ALL l
--         LEFT JOIN journal_entries FOR SYSTEM_TIME ALL je
--                ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
--        WHERE je.id IS NULL;
-- =====================================================================
ALTER TABLE journal_entry_lines
  ADD KEY IF NOT EXISTS idx_jel_supplier_entry (supplier_id, entry_id);
ALTER TABLE journal_entry_lines DROP FOREIGN KEY IF EXISTS fk_jel_entry_supplier;
ALTER TABLE journal_entry_lines
  ADD CONSTRAINT fk_jel_entry_supplier
  FOREIGN KEY (supplier_id, entry_id)
  REFERENCES journal_entries (supplier_id, id)
  ON DELETE CASCADE;
-- Nahrazený jednosloupcový FK. Explicitní index idx_jel_entry (entry_id) ZŮSTÁVÁ
-- (používají ho JOINy WHERE l.entry_id = je.id v JournalIntegrityService).
ALTER TABLE journal_entry_lines DROP FOREIGN KEY IF EXISTS fk_jel_entry;

-- =====================================================================
-- 2c) accounting_closing_steps(supplier_id, period_id) → accounting_periods(supplier_id, id)
--     Child-side index: idx_acs_supplier (supplier_id, period_id) už existuje (1015).
--     PREFLIGHT (musí vrátit 0):
--       SELECT COUNT(*) FROM accounting_closing_steps s
--         LEFT JOIN accounting_periods p
--                ON p.id = s.period_id AND p.supplier_id = s.supplier_id
--        WHERE p.id IS NULL;
-- =====================================================================
ALTER TABLE accounting_closing_steps DROP FOREIGN KEY IF EXISTS fk_acs_period_supplier;
ALTER TABLE accounting_closing_steps
  ADD CONSTRAINT fk_acs_period_supplier
  FOREIGN KEY (supplier_id, period_id)
  REFERENCES accounting_periods (supplier_id, id)
  ON DELETE CASCADE;
-- Nahrazený jednosloupcový FK + jeho osiřelý auto-index (period_id).
ALTER TABLE accounting_closing_steps DROP FOREIGN KEY IF EXISTS fk_acs_period;
ALTER TABLE accounting_closing_steps DROP INDEX IF EXISTS fk_acs_period;

-- =====================================================================
-- 3) CHECK CONSTRAINTY — VOLITELNÉ, ať ORCHESTRÁTOR ROZHODNE podle preflightu.
--    Pokud kterýkoli preflight vrátí > 0, PŘÍSLUŠNÝ blok zakomentuj (jinak ADD
--    selže na datech a shodí celou test suite přes __applyPendingTestMigrations).
--    DROP CONSTRAINT IF EXISTS drží idempotenci (MariaDB nemá ADD ... CHECK IF NOT EXISTS).
--
--    3a) CHECK(amount > 0) na journal_entry_lines
--        Schéma (1005) říká: amount je VŽDY kladná, MD/D dáno sloupcem `side`
--        (i storno = opačná strana, kladná částka). Očekává se, že platí.
--        RIZIKO: řádek s amount = 0 (memo/nulová položka). journal_entry_lines je
--        SYSTEM-VERSIONED → CHECK validuje i HISTORICKÉ verze řádků, proto je
--        autoritativní preflight FOR SYSTEM_TIME ALL.
--        PREFLIGHT (musí vrátit 0):
--          SELECT COUNT(*) FROM journal_entry_lines WHERE amount <= 0;
--        AUTORITATIVNÍ (včetně historie versioned tabulky):
--          SELECT COUNT(*) FROM journal_entry_lines FOR SYSTEM_TIME ALL WHERE amount <= 0;
ALTER TABLE journal_entry_lines DROP CONSTRAINT IF EXISTS chk_jel_amount_positive;
ALTER TABLE journal_entry_lines
  ADD CONSTRAINT chk_jel_amount_positive CHECK (amount > 0);

--    3b) CHECK(starts_on < ends_on) na accounting_periods (není versioned).
--        PREFLIGHT (musí vrátit 0):
--          SELECT COUNT(*) FROM accounting_periods WHERE starts_on >= ends_on;
ALTER TABLE accounting_periods DROP CONSTRAINT IF EXISTS chk_ap_dates_ordered;
ALTER TABLE accounting_periods
  ADD CONSTRAINT chk_ap_dates_ordered CHECK (starts_on < ends_on);
