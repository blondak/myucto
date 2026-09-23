-- MyÚčto.cz — vyřazení majetku zaúčtované mimo modul majetku (převzatý deník, ruční zápis)
--
-- Vyřazení v modulu majetku zaúčtuje zůstatkovou cenu zápisem ('asset_disposal', id).
-- Majetek, jehož vyřazení už zaúčtoval deník (převod z jiného programu, ruční zápis
-- 54x / oprávky), se vyřadí bez zaúčtování a karta si na ten zápis drží odkaz: podle
-- něj se určí účetní zůstatková cena pro přiznání (můstek účetní a daňové ZC) a vazba
-- chrání před druhým zaúčtováním téže zůstatkové ceny.
--
-- Idempotence: ADD COLUMN / KEY / FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE assets
  ADD COLUMN IF NOT EXISTS disposal_entry_id BIGINT UNSIGNED NULL
      COMMENT 'zápis deníku, který zaúčtoval vyřazení mimo modul majetku (převzatý deník, ruční zápis); NULL = vyřazení modulem nebo zápis neurčen'
      AFTER sale_invoice_id,
  ADD KEY IF NOT EXISTS idx_assets_disposal_entry (disposal_entry_id);

ALTER TABLE assets
  ADD CONSTRAINT fk_assets_disposal_entry FOREIGN KEY IF NOT EXISTS (disposal_entry_id)
      REFERENCES journal_entries(id) ON DELETE SET NULL;
