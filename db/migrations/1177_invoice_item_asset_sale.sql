-- MyÚčto.cz — prodej majetku z VYDANÉ faktury: vazba POLOŽKY na kartu majetku.
--
-- PROČ POLOŽKA, A NE HLAVIČKA: migrace 1097 přidala `invoices.revenue_rule_key`, kterým jde
-- přepnout výnosový účet celého dokladu (602 → 641). To ale znamená, že faktura „notebook +
-- konzultace" pošle na 641 i tu konzultaci. Přijatá strana tenhle problém vyřešila už v §DM
-- (1092): `purchase_invoice_items.expense_kind` klasifikuje KAŽDÝ řádek a PostingService
-- rozpadne nákladovou nohu podle vah. Tahle migrace dělá totéž na výnosové straně, jen
-- klasifikace není enum druhu, ale přímo VAZBA NA KARTU — z ní plyne účet i to, co se má
-- s kartou po vystavení stát:
--
--   • small_asset_id → karta drobného majetku; výnos `small_asset.sale.revenue` (311/642),
--     po vystavení SmallAssetService::sell() (karta → 'sold', nic se neúčtuje, ZC je 0).
--   • asset_id       → karta dlouhodobého majetku; výnos `asset.sale.revenue` (311/641),
--     po vystavení AssetService::dispose(type='sold') (doúčtuje ZC 541/08x + vyřazení 08x/02x).
--   • obojí NULL     → dosavadní chování, tedy `invoices.revenue_rule_key` (default 602).
--
-- 642, NE 641, U DROBNÉHO MAJETKU: drobný majetek se pořízením zaúčtoval do spotřeby (501),
-- nikdy nebyl na 02x a nemá oprávky — jeho prodej proto není „prodej dlouhodobého majetku"
-- (641), ale prodej materiálu (642). Účet je v osnově (ChartOfAccountsTemplate) a jako každé
-- kontační pravidlo si ho tenant může přesměrovat per-tenant overridem (třeba na 604 nebo 648).
--
-- ŽÁDNÝ CHECK „nejvýš jeden zdroj": oba sloupce mají FK ON DELETE SET NULL a MariaDB nepustí
-- CHECK nad sloupcem s takovým FK (chyba 1901 — narazilo se na to už v 1094). Invariant proto
-- hlídá aplikace (InvoiceRepository::assertItemAssetLinks), stejně jako u karet majetku.
--
-- ON DELETE SET NULL: smazání karty nesmí shodit fakturu, jen ji odpojí. Opačný směr (smazání
-- faktury) drží vazbu na kartě z 1097, taky se SET NULL.
--
-- Vazby se ZÁMĚRNĚ nekopírují při stornu, hromadném přefakturování ani při vzniku vyúčtovací
-- faktury z proformy (CancelInvoiceAction / BulkReissueAction / FinalFromProformaCreator
-- vyjmenovávají sloupce, nové tedy propadnou jako NULL): jedna karta se prodává jednou a kopie
-- vazby do storna by automat pustila podruhé na už prodanou kartu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + DROP/ADD FOREIGN KEY IF EXISTS (vzor 1023).

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS small_asset_id BIGINT UNSIGNED NULL
      COMMENT 'prodávaná karta drobného majetku; výnos 642 a po vystavení karta → sold'
      AFTER warehouse_id,
  ADD COLUMN IF NOT EXISTS asset_id BIGINT UNSIGNED NULL
      COMMENT 'prodávaná karta dlouhodobého majetku; výnos 641 a po vystavení vyřazení type=sold'
      AFTER small_asset_id;

ALTER TABLE invoice_items
  DROP FOREIGN KEY IF EXISTS fk_ii_small_asset,
  DROP FOREIGN KEY IF EXISTS fk_ii_asset;

ALTER TABLE invoice_items
  ADD KEY IF NOT EXISTS idx_ii_small_asset (small_asset_id),
  ADD KEY IF NOT EXISTS idx_ii_asset (asset_id);

ALTER TABLE invoice_items
  ADD CONSTRAINT fk_ii_small_asset FOREIGN KEY (small_asset_id) REFERENCES small_assets(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ii_asset       FOREIGN KEY (asset_id)       REFERENCES assets(id)       ON DELETE SET NULL;

-- Výnos z prodeje drobného majetku — globální šablona (supplier_id NULL), stejný guard jako 1006.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'small_asset.sale.revenue', 'Tržba z prodeje drobného majetku (DPH split na 343)', '311', '642', 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = 'small_asset.sale.revenue' AND pr.priority = 0
);
