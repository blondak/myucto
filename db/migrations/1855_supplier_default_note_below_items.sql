-- MyÚčto.cz — výchozí text poznámky pod položkami na nově vystavených dokladech (#79).
--
-- Zákazník vkládal na každou fakturu tentýž text ručně (výhrada vlastnictví, sazba
-- úroku z prodlení). Nově si ho firma uloží jednou do Nastavení → Firma → Fakturace
-- a nová faktura ho dostane předvyplněný do `invoices.note_below_items`.
--
-- Text se drží podle JAZYKA dokladu (`invoices.language`), ne podle měny: doklad
-- v cizí měně může být česky a naopak. Podporované jazyky dokladu jsou `cs` a `en`
-- (ENUM v 0001_init.sql), proto přesně dva sloupce; další jazyk by znamenal další
-- sloupec i rozšíření ENUMu.
--
-- DEFAULT = DNEŠNÍ CHOVÁNÍ: přepínač je vypnutý a oba texty prázdné, takže bez
-- zásahu uživatele se nikde nic nepředvyplňuje.
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS default_note_below_items_enabled
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Předvyplňovat poznámku pod položkami na nových vystavených dokladech (0 = chování do migrace 1855)',
  ADD COLUMN IF NOT EXISTS default_note_below_items_cs
    TEXT NULL
    COMMENT 'Výchozí poznámka pod položkami pro doklady v češtině',
  ADD COLUMN IF NOT EXISTS default_note_below_items_en
    TEXT NULL
    COMMENT 'Výchozí poznámka pod položkami pro doklady v angličtině';
