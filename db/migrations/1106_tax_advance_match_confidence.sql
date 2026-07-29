-- MyÚčto.cz — Spolehlivost párování záloh §38a: rozlišení JISTÝCH a NEJISTÝCH shod
--
-- Audit 2026-07: „Spárovat platby" u DPPO zapisovalo do přiznání nesprávné zaplacené
-- zálohy (supplier 1 / 2025: 985 549 místo 730 800). U daně §38a je VS = kmenová část
-- DIČ, kterou nese KAŽDÁ platba na finanční úřad (zálohy, doplatky daně, DPH). Původní
-- matcher spároval podle VS + blízkosti data i platbu s NESEDÍCÍ částkou (amount-blind
-- fallback) a rovnou ji započítal do zaplacených záloh → nadhodnocený/chybný předpis.
--
-- Nově se každá spárovaná úhrada klasifikuje:
--   * 'exact'     = částka sedí na předpis v toleranci → auto-započítat do přiznání,
--   * 'uncertain' = částka nesedí (JEN u pojistného, kde je VS unikátní) → do
--                   automaticky předvyplněného součtu NEVSTUPUJE, účetní potvrdí ručně.
-- U daně §38a se nesedící částka NEspáruje vůbec (předpis zůstává 'planned', vrací se
-- jen jako návrh k ručnímu potvrzení) — proto řádek s advance_kind='tax' a
-- match_confidence='uncertain' u nových párování nevzniká.
--
-- Default 'exact' drží zpětnou kompatibilitu (existující spárované řádky = beze změny
-- chování součtů; konkrétní chybná historická párování řeší samostatný cleanup skript,
-- ne tato migrace). Idempotentní: ADD COLUMN IF NOT EXISTS. Tenant izolace beze změny.

SET NAMES utf8mb4;

ALTER TABLE tax_advance_schedules
  ADD COLUMN IF NOT EXISTS match_confidence ENUM('exact','uncertain') NOT NULL DEFAULT 'exact'
    COMMENT 'spolehlivost shody částky spárované úhrady: exact = sedí na předpis (auto-započítat), uncertain = nesedí (mimo automatický součet, jen pojistné)'
    AFTER matched_transaction_id;
