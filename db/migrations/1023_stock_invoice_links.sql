-- MyÚčto.cz — Epic SKLAD: vazby na upstream tabulky (JEDINÉ zásahy do upstream
-- schématu v celém epicu — izolováno pro merge z upstreamu, nález B12).
--
-- Nullable sloupce nic nerozbíjejí: upstream kód je ignoruje, řádek FV/PF zůstává
-- volný text jako první občan (stock_item_id NULL = služba/volný text).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + DROP/ADD FK (vzor 0021). MariaDB 11.8
-- podporuje ADD COLUMN IF NOT EXISTS i DROP FOREIGN KEY IF EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL AFTER linked_work_report_id,
  ADD COLUMN IF NOT EXISTS warehouse_id  BIGINT UNSIGNED NULL AFTER stock_item_id;

ALTER TABLE invoice_items
  DROP FOREIGN KEY IF EXISTS fk_ii_stock_item,
  DROP FOREIGN KEY IF EXISTS fk_ii_warehouse;

ALTER TABLE invoice_items
  ADD CONSTRAINT fk_ii_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ii_warehouse  FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id)  ON DELETE SET NULL;

-- PF řádek: jen vazba na kartu; sklad se volí až na příjemce (wizard).
ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL AFTER vat_classification_code;

ALTER TABLE purchase_invoice_items
  DROP FOREIGN KEY IF EXISTS fk_pii_stock_item;

ALTER TABLE purchase_invoice_items
  ADD CONSTRAINT fk_pii_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL;

-- Recurring šablony (A15) — generátor kopíruje vazbu do draftu FV.
ALTER TABLE recurring_invoice_template_items
  ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL AFTER order_index,
  ADD COLUMN IF NOT EXISTS warehouse_id  BIGINT UNSIGNED NULL AFTER stock_item_id;

ALTER TABLE recurring_invoice_template_items
  DROP FOREIGN KEY IF EXISTS fk_ritm_stock_item,
  DROP FOREIGN KEY IF EXISTS fk_ritm_warehouse;

ALTER TABLE recurring_invoice_template_items
  ADD CONSTRAINT fk_ritm_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ritm_warehouse  FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id)  ON DELETE SET NULL;

-- Nastavení firmy. stock_method v1 vynuceně 'B' (service); přepnutí na 'A' povolí
-- až epic způsobu A (v2), a jen k hranici účetního období (A1).
-- Žádný stock_negative_policy — tvrdý zákaz minusu bez alternativy (A3).
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS stock_enabled    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS stock_method     ENUM('B','A') NOT NULL DEFAULT 'B'
    COMMENT 'ČÚS 015 způsob účtování zásob — v1 vynuceně B (service), A schema-ready',
  ADD COLUMN IF NOT EXISTS stock_auto_issue TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'automatická výdejka při vystavení FV; 0 = výdejky ručně (úniková cesta A3)';
