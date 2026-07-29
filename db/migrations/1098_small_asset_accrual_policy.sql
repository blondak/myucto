-- MyÚčto.cz — účetní politika časového rozlišení drobného majetku (§DM / Task 11).
--
-- PROČ: drobný majetek se NEODPISUJE — jde celý do 501 v roce pořízení (§26/2 ZDP,
-- ČÚS 013). „50 %" u účetní ale NENÍ odpis, nýbrž ČASOVÉ ROZLIŠENÍ nákladu na 381
-- (náklady příštích období) kvůli věrnému a poctivému obrazu (§7 odst. 1 ZoÚ) —
-- účetní jednotka smí, ale nemusí. Je to tedy VOLITELNÁ účetní politika per firma,
-- ne zákonná povinnost, a proto nesmí být nikde natvrdo 50 %.
--
-- Režimy (small_asset_accrual_mode):
--   • none      = default — žádné rozlišení, celý náklad zůstává v roce pořízení;
--   • pro_rata  = poměrně dle data pořízení (dny od pořízení do konce období /
--                 počet dnů období) — rovnoměrné rozprostření k rozvahovému dni;
--   • flat_pct  = paušální procento (small_asset_accrual_pct) z pořizovací ceny.
--
-- Ukládá se per firma vedle ostatních uzávěrkových přepínačů (fx_rate_mode, R11).
-- Partial-upsert (setSmallAssetAccrual) mění jen tyto dva sloupce; default 'none'
-- drží beze změny chování firmy, které politiku nezvolily.

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS small_asset_accrual_mode ENUM('none','pro_rata','flat_pct')
    NOT NULL DEFAULT 'none'
    COMMENT '§7 ZoÚ: režim časového rozlišení drobného majetku na 381 (volitelná politika)',
  ADD COLUMN IF NOT EXISTS small_asset_accrual_pct DECIMAL(5,2) NULL
    COMMENT 'paušální % z ceny pro režim flat_pct (0–100); NULL u none/pro_rata';
