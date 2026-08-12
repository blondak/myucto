-- MyÚčto.cz — Vlastní číselná řada na kategorii tržeb
--
-- Doteď šla číselná řada nastavit jen na dodavateli (migrace 0014) a na klientovi
-- (migrace 0061). Firmy, které oddělují druhy tržeb (např. „hosting" vs „konzultace")
-- a chtějí pro ně samostatné řady, na to neměly nástroj — klient je špatná osa,
-- protože tentýž odběratel odebírá obojí.
--
-- Přidáváme:
--
--   * revenue_categories.{invoice|proforma|credit_note}_number_format — per-kategorii
--     template override. NULL = fallback na supplier-level template (a dál na cfg).
--   * revenue_categories.invoice_number_period — per-kategorii období counteru
--     ('year'|'month'|'none'). NULL = dědí supplier.invoice_number_period.
--   * invoice_counters.revenue_category_id — další osa scope counteru. `0` = řada
--     kategorií se neřídí (supplier-wide nebo per-client counter).
--
-- Výsledná priorita šablony (VarsymbolGenerator::resolveTemplateAndPeriod):
--   klient → kategorie tržby → dodavatel → cfg.varsymbol.templates.{type}
-- Klient je specifičtější než kategorie záměrně: per-client řada se sjednávala
-- s konkrétním odběratelem (typicky převod z jiného systému) a nesmí ji přebít
-- plošné nastavení kategorie.
--
-- Counter scope drží právě jednu vyhrávající osu:
--   vyhraje klient    → (supplier_id, client_id, 0,           type, period)
--   vyhraje kategorie → (supplier_id, 0,         category_id, type, period)
--   jinak             → (supplier_id, 0,         0,           type, period)
--
-- Idempotence: MariaDB-native `IF NOT EXISTS`; PK se přeskládá kombinovaným
-- DROP+ADD v jednom ALTER (stejný postup jako 0061). Existující řádky dostanou
-- revenue_category_id = 0 z DEFAULT, takže žádný counter se neztratí ani
-- nezduplikuje. Re-run safe.

SET NAMES utf8mb4;

-- ── revenue_categories: per-kategorii číselný formát ──────────────────────
ALTER TABLE revenue_categories
  ADD COLUMN IF NOT EXISTS invoice_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Template pro vydanou fakturu v této kategorii. NULL = dědit ze supplieru.'
    AFTER display_order;

ALTER TABLE revenue_categories
  ADD COLUMN IF NOT EXISTS proforma_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Template pro proformu v této kategorii. NULL = dědit ze supplieru.'
    AFTER invoice_number_format;

ALTER TABLE revenue_categories
  ADD COLUMN IF NOT EXISTS credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Template pro dobropis v této kategorii. NULL = dědit ze supplieru.'
    AFTER proforma_number_format;

ALTER TABLE revenue_categories
  ADD COLUMN IF NOT EXISTS invoice_number_period ENUM('year','month','none') NULL DEFAULT NULL
    COMMENT 'Období counteru kategorie. NULL = dědit ze supplieru.'
    AFTER credit_note_number_format;

-- ── invoice_counters: rozšířit scope o revenue_category_id ────────────────
ALTER TABLE invoice_counters
  ADD COLUMN IF NOT EXISTS revenue_category_id INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = řada se neřídí kategorií, jinak revenue_categories.id.'
    AFTER client_id;

-- Rozšíření PK o revenue_category_id. Kombinovaný DROP+ADD v jednom ALTER je
-- re-runnable: tabulka má vždy primární klíč, takže DROP PRIMARY KEY uspěje a ADD
-- ho poskládá do cílové podoby (při opakovaném běhu drop+add téhož 5-sloup. PK).
-- Bez PREPARE/EXECUTE — viz konvence idempotentních migrací (MariaDB native).
ALTER TABLE invoice_counters
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (supplier_id, client_id, revenue_category_id, invoice_type, period);
