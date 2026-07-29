-- MyÚčto.cz — Fáze F: penalizace (úrok z prodlení) + penalizační faktura
--
-- Zákonný úrok z prodlení dle nařízení vlády č. 351/2013 Sb. § 2:
--   roční úrok = 2týdenní repo sazba ČNB platná k PRVNÍMU DNI kalendářního
--   pololetí, ve kterém prodlení trvá, + 8 procentních bodů.
-- Denní úrok = jistina × (repo + 8) / 100 × dny / (365|366).
--
-- 1) cnb_repo_rates — historie repo sazby ČNB (globální číselník, admin editovatelný).
--    Ukládáme řádky k 1. dni každého pololetí (rozhodný okamžik dle NV 351/2013);
--    lookup = poslední valid_from <= dotazované datum. Sazba se navíc FIXUJE
--    k pololetí VZNIKU prodlení (viz PenaltyInterestCalculator) — nemění se
--    v jeho průběhu, i když prodlení trvá přes další pololetí.
--
-- 2) invoice_type += 'penalty' — penalizační faktura je běžná pohledávka (311),
--    výnos na 644 (smluvní pokuty a úroky z prodlení). Úrok z prodlení je MIMO
--    předmět DPH (§ 2 ZDPH — není plnění) → penalty se NEzahrnuje do DPH evidence
--    (VatLedgerService::fetchSales ho vylučuje) a NEmá DPH nohu (343).
--
-- 3) posting rule 'invoice.penalty.issued' (311 / 644) — globální seed šablona.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, INSERT IGNORE (PK), MODIFY ENUM
-- (idempotentní), INSERT ... WHERE NOT EXISTS pro posting rule.

SET NAMES utf8mb4;

-- 1) Repo sazba ČNB (globální číselník) --------------------------------------

CREATE TABLE IF NOT EXISTS cnb_repo_rates (
  valid_from  DATE NOT NULL PRIMARY KEY COMMENT 'Datum, od kterého sazba platí (typicky 1. den pololetí)',
  rate        DECIMAL(6,3) NOT NULL COMMENT '2T repo sazba ČNB v % p.a.',
  note        VARCHAR(255) NULL COMMENT 'Poznámka / zdroj',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repo sazba ČNB k 1. dni pololetí (rozhodná pro úrok z prodlení). Hodnoty
-- odpovídají 2T repo sazbě ČNB platné k danému dni. Admin může řádky
-- upravit/doplnit v UI (Účetnictví → Repo sazba ČNB).
INSERT IGNORE INTO cnb_repo_rates (valid_from, rate, note) VALUES
  ('2020-01-01', 2.000, 'zdroj: ČNB, seed 2026-07'),
  ('2020-07-01', 0.250, 'zdroj: ČNB, seed 2026-07'),
  ('2021-01-01', 0.250, 'zdroj: ČNB, seed 2026-07'),
  ('2021-07-01', 0.500, 'zdroj: ČNB, seed 2026-07'),
  ('2022-01-01', 3.750, 'zdroj: ČNB, seed 2026-07'),
  ('2022-07-01', 7.000, 'zdroj: ČNB, seed 2026-07'),
  ('2023-01-01', 7.000, 'zdroj: ČNB, seed 2026-07'),
  ('2023-07-01', 7.000, 'zdroj: ČNB, seed 2026-07'),
  ('2024-01-01', 6.750, 'zdroj: ČNB, seed 2026-07'),
  ('2024-07-01', 4.750, 'zdroj: ČNB, seed 2026-07'),
  ('2025-01-01', 4.000, 'zdroj: ČNB, seed 2026-07'),
  ('2025-07-01', 3.500, 'zdroj: ČNB, seed 2026-07');

-- 2) Nový typ dokladu: penalizační faktura (úrok z prodlení) ------------------
ALTER TABLE invoices
    MODIFY invoice_type ENUM('invoice','proforma','credit_note','cancellation','tax_document','penalty')
        NOT NULL DEFAULT 'invoice';

-- 3) Kontační pravidlo penalizační faktury (globální seed) --------------------
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'invoice.penalty.issued', 'Penalizační faktura — úrok z prodlení (mimo DPH; výnos 644)', '311', '644', 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = 'invoice.penalty.issued' AND pr.priority = 0
);
