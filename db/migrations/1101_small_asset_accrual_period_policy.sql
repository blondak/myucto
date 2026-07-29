-- MyÚčto.cz — §DM účetní politika časového rozlišení drobného majetku PER OBDOBÍ.
--
-- PROČ per období (a ne per firma): „50 %" u drobného majetku NENÍ odpis, nýbrž
-- VOLITELNÉ časové rozlišení nákladu na 381 (§7 odst. 1 ZoÚ). Politika se ale
-- LEGITIMNĚ liší rok od roku — např. flat_pct 50 % pro zkrácené první období
-- (FY2024) a NONE pro následující (FY2025, kde už se nic neodkládá). Původní
-- uložení do accounting_supplier_settings (firma) způsobilo, že uzávěrka jednoho
-- období přepsala default a příští období se předvyplnilo špatnou politikou
-- (flat_pct 50 → chybný odklad). Politika proto patří na OBDOBÍ.
--
-- Firemní sloupce (accounting_supplier_settings.small_asset_accrual_mode/pct)
-- ZŮSTÁVAJÍ jako DEFAULT/seed pro nově zakládané období (AccountingPeriodRepository::create
-- dědí firemní default). Backward compatible — default 'none' drží beze změny chování.

SET NAMES utf8mb4;

ALTER TABLE accounting_periods
  ADD COLUMN IF NOT EXISTS small_asset_accrual_mode ENUM('none','pro_rata','flat_pct')
    NOT NULL DEFAULT 'none'
    COMMENT '§7 ZoÚ: režim časového rozlišení drobného majetku (381) PER OBDOBÍ (volitelná politika)',
  ADD COLUMN IF NOT EXISTS small_asset_accrual_pct DECIMAL(5,2) NULL
    COMMENT 'paušální % z ceny pro flat_pct (0–100); NULL u none/pro_rata';

-- Backfill z HISTORIE (per období, ne z firmy): období, které už §DM rozlišení
-- zaúčtovalo, dostane svůj režim/pct z payloadu kroku 'deferrals' (zdroj pravdy pro
-- to konkrétní období). Firemní default se ZÁMĚRNĚ nepoužívá — mohl být přepsán chybně
-- (flat_pct 50) minulou uzávěrkou; období bez §DM zápisu tak správně zůstává 'none'.
-- Idempotentní (opakovaný běh spočítá tytéž hodnoty).
UPDATE accounting_periods p
  JOIN accounting_closing_steps s
    ON s.period_id = p.id AND s.supplier_id = p.supplier_id AND s.step_key = 'deferrals'
  SET p.small_asset_accrual_mode =
        JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.small_asset_accrual.mode')),
      p.small_asset_accrual_pct =
        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.small_asset_accrual.mode')) = 'flat_pct'
             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.small_asset_accrual.pct')) AS DECIMAL(5,2))
             ELSE NULL END
  WHERE JSON_EXTRACT(s.payload, '$.small_asset_accrual.mode') IS NOT NULL
    AND JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.small_asset_accrual.mode')) IN ('none','pro_rata','flat_pct');
