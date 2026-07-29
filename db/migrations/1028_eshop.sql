-- MyÚčto.cz — Epic ESHOP: karta Zboží (rozšíření modulu Sklad, issue #17).
--
-- Aditivně nad stock_items (item_type='goods'): satelitní tabulky pro i18n,
-- výrobce, strom kategorií (materialized path), tagy, typované atributy,
-- poplatky, cenotvorbu per měna, dodavatele M:N na clients a média. Skladový
-- stav / oceňování (1022) a vazby na fakturaci (1023) zůstávají netknuté.
-- Karta je plně použitelná i BEZ příjemky / bez stock_levels (is_stocked=0) —
-- dropshipping přes dodavatele (rozhodnutí E5).
--
-- Tenant izolace: každá tabulka má supplier_id INT UNSIGNED NOT NULL + FK
-- REFERENCES supplier(id) ON DELETE CASCADE (denormalizace i na M:N spojkách).
-- Money-safe: peněžní pole DECIMAL, v PHP string, aritmetika bcmath/string.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS,
-- CREATE INDEX IF NOT EXISTS, odložené FK přes DROP FK IF EXISTS + ADD
-- CONSTRAINT (MariaDB 11.8 — vzor 1023). Spouštět přes api/bin/migrate.php.

SET NAMES utf8mb4;

-- ── 1) Rozšíření stock_items ─────────────────────────────────────────────────
ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS manufacturer_id BIGINT UNSIGNED NULL COMMENT 'FK manufacturers (odložený)' AFTER item_type,
  ADD COLUMN IF NOT EXISTS warranty_months SMALLINT UNSIGNED NULL COMMENT 'záruka v měsících',
  ADD COLUMN IF NOT EXISTS delivery_days   SMALLINT UNSIGNED NULL COMMENT 'default dodací lhůta (dny)',
  ADD COLUMN IF NOT EXISTS export_eshop    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'export karty na eshop',
  ADD COLUMN IF NOT EXISTS is_stocked      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = neskladem, prodej přes dodavatele',
  ADD COLUMN IF NOT EXISTS weight_g        INT UNSIGNED NULL COMMENT 'hmotnost v gramech (doprava)',
  ADD COLUMN IF NOT EXISTS pricing_base    ENUM('weighted_avg','last_purchase','manual') NOT NULL DEFAULT 'weighted_avg' COMMENT 'zdroj nákupní ceny pro cenotvorbu';

CREATE INDEX IF NOT EXISTS idx_si_export       ON stock_items (supplier_id, export_eshop, item_type);
CREATE INDEX IF NOT EXISTS idx_si_manufacturer ON stock_items (manufacturer_id);

-- ── 2) i18n překlady karty (1:N) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_item_i18n (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(5) NOT NULL COMMENT 'cs, en, de, sk…',
  name VARCHAR(255) NOT NULL,
  short_desc VARCHAR(500) NULL,
  description MEDIUMTEXT NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(320) NULL,
  seo_slug VARCHAR(255) NULL COMMENT 'URL slug per jazyk',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sii_item_locale (stock_item_id, locale),
  UNIQUE KEY uq_sii_slug (supplier_id, locale, seo_slug),
  KEY idx_sii_supplier (supplier_id, locale),
  CONSTRAINT fk_sii_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sii_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) Výrobci ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS manufacturers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  website VARCHAR(255) NULL,
  logo_media_id BIGINT UNSIGNED NULL COMMENT 'FK stock_media (odložený)',
  display_order INT NOT NULL DEFAULT 0,
  export_eshop TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mf_supplier_code (supplier_id, code),
  KEY idx_mf_supplier (supplier_id, archived, display_order),
  CONSTRAINT fk_mf_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) Kategorie — strom (materialized path) + i18n ──────────────────────────
CREATE TABLE IF NOT EXISTS stock_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL COMMENT 'fallback (default locale)',
  path VARCHAR(255) NOT NULL DEFAULT '/' COMMENT 'materialized path /12/45/98/ — breadcrumbs, subtree',
  depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 0,
  export_eshop TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sc_supplier_code (supplier_id, code),
  KEY idx_sc_parent (parent_id),
  KEY idx_sc_supplier_path (supplier_id, path),
  KEY idx_sc_supplier_tree (supplier_id, parent_id, display_order),
  CONSTRAINT fk_sc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_parent FOREIGN KEY (parent_id) REFERENCES stock_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_category_i18n (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(5) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  seo_slug VARCHAR(255) NULL,
  UNIQUE KEY uq_sci_cat_locale (category_id, locale),
  KEY idx_sci_supplier (supplier_id, locale),
  CONSTRAINT fk_sci_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sci_cat FOREIGN KEY (category_id) REFERENCES stock_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5) Zboží ↔ kategorie M:N ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_item_categories (
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'hlavní kat. — breadcrumbs, kanonická URL',
  display_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (stock_item_id, category_id),
  KEY idx_sic_category (category_id),
  KEY idx_sic_supplier (supplier_id),
  CONSTRAINT fk_sic_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sic_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sic_category FOREIGN KEY (category_id) REFERENCES stock_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6) Tagy ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  color CHAR(7) NULL COMMENT '#RRGGBB',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stg_supplier_code (supplier_id, code),
  KEY idx_stg_supplier (supplier_id, archived),
  CONSTRAINT fk_stg_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_item_tags (
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (stock_item_id, tag_id),
  KEY idx_sit_tag (tag_id),
  KEY idx_sit_supplier (supplier_id),
  CONSTRAINT fk_sit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sit_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sit_tag FOREIGN KEY (tag_id) REFERENCES stock_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7) Atributy / parametry (definice + typované hodnoty) ────────────────────
CREATE TABLE IF NOT EXISTS stock_attributes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(120) NOT NULL COMMENT 'fallback, např. "Barva"',
  data_type ENUM('text','number','bool','enum') NOT NULL DEFAULT 'text',
  unit VARCHAR(20) NULL COMMENT 'jednotka pro number (cm, GB)',
  is_filterable TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'facetový filtr',
  is_multivalue TINYINT(1) NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 0,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sa_supplier_code (supplier_id, code),
  KEY idx_sa_supplier (supplier_id, archived, display_order),
  CONSTRAINT fk_sa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_attribute_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  label VARCHAR(120) NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sao (attribute_id, code),
  KEY idx_sao_supplier (supplier_id),
  CONSTRAINT fk_sao_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sao_attr FOREIGN KEY (attribute_id) REFERENCES stock_attributes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_attribute_i18n (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NULL,
  option_id BIGINT UNSIGNED NULL COMMENT 'právě jeden z attribute_id/option_id NOT NULL',
  locale VARCHAR(5) NOT NULL,
  label VARCHAR(120) NOT NULL,
  KEY idx_sai_supplier (supplier_id, locale),
  KEY idx_sai_attr (attribute_id, locale),
  KEY idx_sai_option (option_id, locale),
  CONSTRAINT fk_sai_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sai_attr FOREIGN KEY (attribute_id) REFERENCES stock_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_sai_option FOREIGN KEY (option_id) REFERENCES stock_attribute_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_item_attribute_values (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NULL COMMENT 'pro data_type=enum',
  value_text VARCHAR(500) NULL,
  value_num DECIMAL(20,6) NULL COMMENT 'number — indexovatelné rozsahy',
  value_bool TINYINT(1) NULL,
  display_order INT NOT NULL DEFAULT 0,
  KEY idx_siav_item (stock_item_id),
  KEY idx_siav_attr_num (supplier_id, attribute_id, value_num),
  KEY idx_siav_attr_opt (supplier_id, attribute_id, option_id),
  CONSTRAINT fk_siav_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_siav_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_siav_attr FOREIGN KEY (attribute_id) REFERENCES stock_attributes(id) ON DELETE CASCADE,
  CONSTRAINT fk_siav_option FOREIGN KEY (option_id) REFERENCES stock_attribute_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8) Poplatky (autorský / recyklační) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_fee_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL COMMENT 'copyright, recycling, weee…',
  name VARCHAR(120) NOT NULL,
  vat_rate_id INT UNSIGNED NULL COMMENT 'DPH režim poplatku',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sft_supplier_code (supplier_id, code),
  KEY idx_sft_supplier (supplier_id, archived),
  CONSTRAINT fk_sft_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sft_vat FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_item_fees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  fee_type_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'CZK',
  vat_included TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_sif_item (stock_item_id),
  KEY idx_sif_supplier (supplier_id),
  CONSTRAINT fk_sif_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sif_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sif_type FOREIGN KEY (fee_type_id) REFERENCES stock_fee_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9) Cenotvorba — ceny per měna ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_item_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) NOT NULL COMMENT 'ISO 4217; nezávislé na bankovních účtech',
  price_mode ENUM('markup','fixed') NOT NULL DEFAULT 'markup',
  markup_pct DECIMAL(7,3) NULL COMMENT 'přirážka % (markup)',
  fixed_price DECIMAL(12,2) NULL COMMENT 'pevná cena bez DPH (fixed)',
  rounding ENUM('none','0.01','0.10','0.50','1','9_ending') NOT NULL DEFAULT 'none',
  computed_price DECIMAL(12,2) NULL COMMENT 'materializovaná prodejní cena bez DPH',
  computed_base DECIMAL(15,6) NULL COMMENT 'nákupní cena použitá (audit)',
  computed_rate DECIMAL(14,6) NULL COMMENT 'FX kurz (audit), NULL pro CZK',
  computed_at TIMESTAMP NULL,
  is_manual_override TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sip_item_currency (stock_item_id, currency_code),
  KEY idx_sip_supplier (supplier_id),
  CONSTRAINT fk_sip_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sip_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10) Dodavatelé zboží M:N na clients (is_vendor) ──────────────────────────
CREATE TABLE IF NOT EXISTS stock_item_vendors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL COMMENT 'clients.id s is_vendor=1',
  vendor_sku VARCHAR(80) NULL COMMENT 'kód u dodavatele (budoucí feedy)',
  purchase_price DECIMAL(12,2) NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'CZK',
  delivery_days SMALLINT UNSIGNED NULL COMMENT 'dodací lhůta dodavatele',
  stock_qty DECIMAL(14,3) NULL COMMENT 'skladovost u dodavatele (feed/ručně)',
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_siv_item_client (stock_item_id, client_id),
  KEY idx_siv_client (client_id),
  KEY idx_siv_supplier (supplier_id),
  CONSTRAINT fk_siv_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_siv_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_siv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11) Média / přílohy ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('image','document') NOT NULL DEFAULT 'image',
  storage_key VARCHAR(255) NOT NULL COMMENT 'sha256 obsahu (content-addressed, vzor DocumentStorage)',
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  title VARCHAR(255) NULL,
  alt_text VARCHAR(255) NULL COMMENT 'alt (SEO/přístupnost)',
  display_order INT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  export_eshop TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'export přílohy (per příloha)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sm_item (stock_item_id, display_order),
  KEY idx_sm_supplier (supplier_id),
  CONSTRAINT fk_sm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sm_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 12) Odložené FK (cyklické závislosti) — drop-then-add (vzor 1023) ─────────
ALTER TABLE stock_items   DROP FOREIGN KEY IF EXISTS fk_si_manufacturer;
ALTER TABLE stock_items   ADD CONSTRAINT fk_si_manufacturer FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id) ON DELETE SET NULL;

ALTER TABLE manufacturers DROP FOREIGN KEY IF EXISTS fk_mf_logo;
ALTER TABLE manufacturers ADD CONSTRAINT fk_mf_logo FOREIGN KEY (logo_media_id) REFERENCES stock_media(id) ON DELETE SET NULL;

-- ── 13) Backfill — cs i18n řádek z stock_items.name pro existující zboží ──────
INSERT IGNORE INTO stock_item_i18n (supplier_id, stock_item_id, locale, name)
SELECT si.supplier_id, si.id, 'cs', si.name
  FROM stock_items si
 WHERE si.item_type = 'goods'
   AND NOT EXISTS (
     SELECT 1 FROM stock_item_i18n t
      WHERE t.stock_item_id = si.id AND t.locale = 'cs'
   );
