-- MyÚčto.cz — Fáze E (audit 2026-07), E8: vícenásobná dodatečná přiznání (§141 DŘ).
--
-- Migrace 1031 zavedla UNIQUE (supplier_id, year, taxpayer_type, variant) — jedno
-- řádné + jedno opravné + JEDNO dodatečné za období. V praxi se ale za jedno období
-- podává i několik dodatečných přiznání (§141 DŘ, každé rozdílově proti POSLEDNÍ
-- pravomocně stanovené dani). Přidáváme pořadové číslo `variant_seq`:
--   * radne / opravne  → vždy variant_seq = 1 (jen jedno smí existovat),
--   * dodatecne         → inkrementující se pořadí 1, 2, 3, …
-- a rozšiřujeme UNIQUE o variant_seq.
--
-- Aditivní + idempotentní (ADD COLUMN IF NOT EXISTS / DROP INDEX IF EXISTS /
-- CREATE UNIQUE INDEX IF NOT EXISTS). Bezpečné pro existující data: default 1 → všechny
-- dosavadní řádky (max. jedno dodatečné díky starému UNIQUE) dostanou variant_seq = 1,
-- takže nový širší UNIQUE nemůže kolidovat. MyÚčto migrace od 1000_.

SET NAMES utf8mb4;

-- 1. Pořadové číslo v rámci varianty. Default 1 → BC pro existující řádky.
ALTER TABLE income_tax_returns
  ADD COLUMN IF NOT EXISTS variant_seq SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'pořadí v rámci varianty: radne/opravne vždy 1, dodatecne 1..N (§141 DŘ)'
    AFTER variant;

-- 2. Rozšíření unikátního klíče o pořadí (nahrazuje uq_itr_variant z 1031).
ALTER TABLE income_tax_returns DROP INDEX IF EXISTS uq_itr_variant;
CREATE UNIQUE INDEX IF NOT EXISTS uq_itr_variant_seq
  ON income_tax_returns (supplier_id, year, taxpayer_type, variant, variant_seq);
