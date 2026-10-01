-- MyÚčto.cz — Produkt a kategorie jako zdroj účtu a dimenzí (Účtování podle dimenzí, F1)
--
-- Skladová karta (`stock_items`) a kategorie (`stock_categories`) nesou výchozí účet
-- výnosů a nákladů. Položka vydané faktury dostává vlastní výnosový účet
-- (`invoice_items.revenue_account_code`, protějšek `purchase_invoice_items.expense_account_code`),
-- šablona pravidelné fakturace ho přenáší na vygenerované faktury.
--
-- Pořadí účtu při zaúčtování (PostingService):
--   vydaná faktura:  majetek > účet položky > produkt > kategorie > předkontace dokladu
--   přijatá faktura: účet položky > druh výdaje > produkt > kategorie > předkontace dokladu
-- Všechny sloupce jsou NULL = beze změny chování (bajtově stejné zaúčtování).
--
-- `dimension_defaults` (1861) dostává dva další vlastníky: produkt a kategorii produktu.
-- Platí „právě jeden vlastník" — CHECK se proto zahodí a založí znovu se čtyřmi sloupci.
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, odtud DROP + ADD.
--
-- Idempotentní: ADD COLUMN / INDEX / FOREIGN KEY IF NOT EXISTS, DROP CONSTRAINT IF EXISTS.

SET NAMES utf8mb4;

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS revenue_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výchozí účet výnosů položky vydané faktury (6xx), NULL = podle kategorie / předkontace',
  ADD COLUMN IF NOT EXISTS expense_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výchozí účet nákladů položky přijaté faktury (5xx), NULL = podle kategorie / předkontace';

ALTER TABLE stock_categories
  ADD COLUMN IF NOT EXISTS revenue_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výchozí účet výnosů produktů kategorie (6xx), dědí se do podkategorií',
  ADD COLUMN IF NOT EXISTS expense_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výchozí účet nákladů produktů kategorie (5xx), dědí se do podkategorií';

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS revenue_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výnosový účet položky (6xx), NULL = produkt > kategorie > předkontace dokladu';

ALTER TABLE recurring_invoice_template_items
  ADD COLUMN IF NOT EXISTS revenue_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Výnosový účet položky vygenerované faktury (6xx)';

ALTER TABLE dimension_defaults
  ADD COLUMN IF NOT EXISTS product_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER project_id,
  ADD COLUMN IF NOT EXISTS product_category_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER product_id;

ALTER TABLE dimension_defaults
  ADD UNIQUE INDEX IF NOT EXISTS uq_dimdef_product_type (product_id, dimension_type_id),
  ADD UNIQUE INDEX IF NOT EXISTS uq_dimdef_category_type (product_category_id, dimension_type_id);

ALTER TABLE dimension_defaults
  ADD CONSTRAINT fk_dimdef_product FOREIGN KEY IF NOT EXISTS (product_id)
    REFERENCES stock_items(id) ON DELETE CASCADE;

ALTER TABLE dimension_defaults
  ADD CONSTRAINT fk_dimdef_category FOREIGN KEY IF NOT EXISTS (product_category_id)
    REFERENCES stock_categories(id) ON DELETE CASCADE;

ALTER TABLE dimension_defaults DROP CONSTRAINT IF EXISTS chk_dimdef_owner;

ALTER TABLE dimension_defaults
  ADD CONSTRAINT chk_dimdef_owner CHECK (
    (client_id IS NOT NULL) + (project_id IS NOT NULL)
      + (product_id IS NOT NULL) + (product_category_id IS NOT NULL) = 1
  );
