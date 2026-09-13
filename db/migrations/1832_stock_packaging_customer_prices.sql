-- MyÚčto.cz - balení (nadřazené jednotky skladové karty) a individuální ceny zákazníků (issue #17).
--
-- Balení: číselník `stock_packaging_units` drží kódy balení firmy (KT = karton, PAL = paleta),
-- samotný převod na základní jednotku karty zůstává v `stock_item_units` (1793), který drží
-- přesný zlomek numerator/denominator. Karta dostane EAN balení a výchozí prodejní jednotku.
--
-- `is_sales_unit` odděluje balení (1, zapisuje jen editor balení) od převodních jednotek
-- šarží z 1793 (0). Množství řádků faktur se přepočítává JEN přes balení; převodní
-- jednotky šarží zůstávají pro doklady 1:1, přesně jako dosud.
--
-- Individuální ceny: pevná cena nebo sleva v % pro konkrétního odběratele, vždy za ZÁKLADNÍ
-- jednotku a bez DPH. Rozhoduje o nich jen EffectivePriceResolver. Starší
-- `price_list_customer_overrides` (1121) je samostatný koncept pro firmy BEZ skladu.
--
-- PDF faktury: `supplier.invoice_pdf_show_base_qty` rozepíše balení na základní jednotky.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_packaging_units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock_packaging_unit_code (supplier_id, code),
  CONSTRAINT fk_spu_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_item_units
  ADD COLUMN IF NOT EXISTS ean VARCHAR(20) NULL AFTER denominator;
ALTER TABLE stock_item_units
  ADD COLUMN IF NOT EXISTS is_sales_unit TINYINT(1) NOT NULL DEFAULT 0 AFTER ean;
ALTER TABLE stock_item_units
  ADD KEY IF NOT EXISTS ix_siu_ean (supplier_id, ean);

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS default_sale_unit VARCHAR(20) NULL AFTER unit;

CREATE TABLE IF NOT EXISTS stock_item_customer_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) NOT NULL,
  price_type ENUM('fixed','discount_pct') NOT NULL,
  fixed_price DECIMAL(12,2) NULL,
  discount_pct DECIMAL(6,3) NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sicp_item_client_currency (supplier_id, stock_item_id, client_id, currency_code),
  KEY ix_sicp_client_currency (supplier_id, client_id, currency_code),
  CONSTRAINT fk_sicp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sicp_item FOREIGN KEY (supplier_id, stock_item_id) REFERENCES stock_items(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_sicp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT chk_sicp_value CHECK (
    (price_type = 'fixed' AND fixed_price IS NOT NULL AND fixed_price >= 0)
    OR (price_type = 'discount_pct' AND discount_pct IS NOT NULL AND discount_pct >= 0 AND discount_pct <= 100)
  ),
  CONSTRAINT chk_sicp_window CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_pdf_show_base_qty TINYINT(1) NOT NULL DEFAULT 1;
