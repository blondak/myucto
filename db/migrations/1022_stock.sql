-- MyÚčto.cz — Epic SKLAD: skladová evidence (sklady, karty, doklady PRI/VYD/PRE,
-- inventury, vedlejší pořizovací náklady, uzávěrkové kontace způsobu B).
--
-- v1 = způsob B only (ČÚS 015 bod 4.3): pohyby se NEÚČTUJÍ průběžně, jen evidence;
-- konečný/počáteční stav účtuje uzávěrkový krok ClosingService. Sloupce
-- journal_entry_id a source_type='stock' jsou schema-ready pro způsob A (v2).
-- Oceňování: vážený aritmetický klouzavý průměr (§49/3 vyhl. 500/2002);
-- value_total v haléřích je zdroj pravdy, avg_unit_cost odvozený.
--
-- Tento soubor = VÝHRADNĚ fork-owned objekty (nové tabulky + ENUM appendy fork
-- tabulek journal_entries/accounting_document_series + seedy kontací). Vazby na
-- upstream tabulky (invoice_items, purchase_invoice_items, supplier, …) jsou
-- izolovány v 1023_stock_invoice_links.sql (nález B12).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, MODIFY ENUM append-only,
-- seedy kontací s NOT EXISTS guardem (vzor 1019).

SET NAMES utf8mb4;

-- ── Sklady ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS warehouses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  code          VARCHAR(20) NOT NULL COMMENT 'HLAVNI, PRODEJNA…',
  name          VARCHAR(100) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  note          TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wh_supplier_code (supplier_id, code),
  CONSTRAINT fk_wh_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Pozn.: žádné account_* sloupce (rozhodnutí A11) — přidá migrace způsobu A (v2).

-- ── Skladové karty (první produktový číselník v systému) ─────────────────────
CREATE TABLE IF NOT EXISTS stock_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  sku            VARCHAR(50) NOT NULL,
  name           VARCHAR(255) NOT NULL,
  item_type      ENUM('material','goods','product') NOT NULL DEFAULT 'goods'
                 COMMENT 'určuje uzávěrkové kontace B (112/501 vs 132/504); product v1 jen evidence (A14)',
  unit           VARCHAR(20) NOT NULL DEFAULT 'ks',
  ean            VARCHAR(20) NULL,
  vat_rate_id    INT UNSIGNED NULL COMMENT 'default sazba do řádku FV',
  sale_price_without_vat DECIMAL(12,2) NULL COMMENT 'default prodejní cena do řádku FV',
  min_qty        DECIMAL(14,3) NULL COMMENT 'hlídání minima (badge v listu)',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  note           TEXT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_si_supplier_sku (supplier_id, sku),
  KEY idx_si_supplier_active (supplier_id, is_active, item_type),
  KEY idx_si_supplier_name (supplier_id, name),
  CONSTRAINT fk_si_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_vat FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Aktuální stav — transakčně udržovaná materializace (zámkovatelný agregát) ─
CREATE TABLE IF NOT EXISTS stock_levels (
  supplier_id   INT UNSIGNED NOT NULL,
  warehouse_id  BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
  value_total   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'ocenění stavu v CZK — ZDROJ PRAVDY (haléřová aritmetika)',
  avg_unit_cost DECIMAL(15,6) NOT NULL DEFAULT 0 COMMENT 'odvozené = value_total/qty, jen pro čtení',
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, warehouse_id, stock_item_id),
  KEY idx_sl_item (stock_item_id),
  CONSTRAINT fk_sl_supplier  FOREIGN KEY (supplier_id)   REFERENCES supplier(id)    ON DELETE CASCADE,
  CONSTRAINT fk_sl_warehouse FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sl_item      FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Skladové doklady (lifecycle draft → posted → reversed, vzor cash_documents) ─
CREATE TABLE IF NOT EXISTS stock_documents (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  doc_type             ENUM('receipt','issue','transfer') NOT NULL,
  origin               ENUM('manual','invoice','credit_note','purchase_invoice','inventory')
                       NOT NULL DEFAULT 'manual' COMMENT 'původ — ortogonální k doc_type (A4)',
  warehouse_id         BIGINT UNSIGNED NOT NULL COMMENT 'u transfer = zdrojový sklad',
  warehouse_to_id      BIGINT UNSIGNED NULL COMMENT 'jen transfer — cílový sklad',
  doc_number           VARCHAR(30) NULL COMMENT 'PRI-2026-0001 — přiděleno při post(), až PO kontrole zásob (B3)',
  doc_date             DATE NOT NULL,
  description          VARCHAR(255) NOT NULL COMMENT '§11/1/c ZoÚ — obsah případu',
  partner_name         VARCHAR(255) NULL COMMENT '§11/1/b — dodavatel/odběratel (volný text)',
  invoice_id           BIGINT UNSIGNED NULL COMMENT 'výdejka k FV / vratka k dobropisu',
  purchase_invoice_id  BIGINT UNSIGNED NULL COMMENT 'příjemka z PF',
  stock_take_id        BIGINT UNSIGNED NULL COMMENT 'rozdílový doklad inventury',
  journal_entry_id     BIGINT UNSIGNED NULL COMMENT 'v1 (způsob B) vždy NULL — schema-ready pro A',
  reversal_document_id BIGINT UNSIGNED NULL COMMENT 'protidoklad při stornu (§4.4)',
  status               ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  booked_at            TIMESTAMP NULL COMMENT 'doc-lock vzor F6 (tax_evidence) — nastaví post()',
  booked_by            BIGINT UNSIGNED NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sd_supplier_number (supplier_id, doc_number),
  KEY idx_sd_supplier_status (supplier_id, status, doc_date),
  KEY idx_sd_warehouse_date (warehouse_id, doc_date),
  KEY idx_sd_invoice (invoice_id),
  KEY idx_sd_pinvoice (purchase_invoice_id),
  KEY idx_sd_reversal (reversal_document_id),
  CONSTRAINT fk_sd_supplier  FOREIGN KEY (supplier_id)         REFERENCES supplier(id)          ON DELETE CASCADE,
  CONSTRAINT fk_sd_wh        FOREIGN KEY (warehouse_id)        REFERENCES warehouses(id),
  CONSTRAINT fk_sd_wh_to     FOREIGN KEY (warehouse_to_id)     REFERENCES warehouses(id),
  CONSTRAINT fk_sd_invoice   FOREIGN KEY (invoice_id)          REFERENCES invoices(id)          ON DELETE SET NULL,
  CONSTRAINT fk_sd_pinvoice  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_sd_entry     FOREIGN KEY (journal_entry_id)    REFERENCES journal_entries(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Pozn. B4: pro idempotenci auto-výdeje ŽÁDNÝ UNIQUE s invoice_id (reversed doklady
-- by kolidovaly) — jen idx_sd_invoice + aplikační guard v transakci issue.

-- ── Řádky dokladů = skladová kniha (jediný zdroj pravdy o pohybech, A6) ──────
CREATE TABLE IF NOT EXISTS stock_document_lines (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id              BIGINT UNSIGNED NOT NULL,
  supplier_id              INT UNSIGNED NOT NULL COMMENT 'denormalizace pro tenant filtr (vzor journal_entry_lines)',
  stock_item_id            BIGINT UNSIGNED NOT NULL,
  doc_date                 DATE NULL COMMENT 'kopie z hlavičky při post() — index pro replay/valuation (B8)',
  qty                      DECIMAL(14,3) NOT NULL COMMENT 'vždy kladné; směr dán doc_type (A6)',
  unit_cost                DECIMAL(15,6) NOT NULL DEFAULT 0 COMMENT 'příjem: zadaná PC; výdej: klouzavý průměr v okamžiku post()',
  value_total              DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'haléřově; výdej celého zůstatku = přesně stock_levels.value_total',
  extra_cost               DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'rozpuštěné vedlejší náklady (informativní, už obsaženy ve value_total)',
  invoice_item_id          BIGINT UNSIGNED NULL,
  purchase_invoice_item_id BIGINT UNSIGNED NULL,
  source_description       VARCHAR(500) NULL COMMENT 'snapshot textu řádku FV/PF — řádky se mažou přes replaceItems (B6)',
  source_qty               DECIMAL(14,3) NULL COMMENT 'snapshot qty řádku PF při příjmu (B6)',
  line_no                  INT UNSIGNED NOT NULL DEFAULT 0,
  note                     VARCHAR(255) NULL,
  KEY idx_sdl_document (document_id, line_no),
  KEY idx_sdl_item_ledger (supplier_id, stock_item_id, doc_date, id),
  KEY idx_sdl_pii (purchase_invoice_item_id),
  CONSTRAINT fk_sdl_document FOREIGN KEY (document_id)              REFERENCES stock_documents(id)        ON DELETE CASCADE,
  CONSTRAINT fk_sdl_item     FOREIGN KEY (stock_item_id)            REFERENCES stock_items(id),
  CONSTRAINT fk_sdl_ii       FOREIGN KEY (invoice_item_id)          REFERENCES invoice_items(id)          ON DELETE SET NULL,
  CONSTRAINT fk_sdl_pii      FOREIGN KEY (purchase_invoice_item_id) REFERENCES purchase_invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Vedlejší pořizovací náklady (§49/1 vyhl. 500/2002 — doprava, clo, provize) ─
CREATE TABLE IF NOT EXISTS stock_landed_costs (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  document_id              BIGINT UNSIGNED NOT NULL COMMENT 'příjemka (doc_type=receipt) — v1 jen ve stavu draft (A8)',
  purchase_invoice_id      BIGINT UNSIGNED NULL COMMENT 'PF za dopravu/clo — smí být JINÁ než PF zboží',
  purchase_invoice_item_id BIGINT UNSIGNED NULL,
  description              VARCHAR(255) NOT NULL,
  amount                   DECIMAL(15,2) NOT NULL COMMENT 'CZK; plátce bez DPH, neplátce vč. DPH (B7)',
  allocation               ENUM('by_value','by_qty') NOT NULL DEFAULT 'by_value',
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_slc_document (document_id),
  CONSTRAINT fk_slc_supplier FOREIGN KEY (supplier_id)              REFERENCES supplier(id)               ON DELETE CASCADE,
  CONSTRAINT fk_slc_document FOREIGN KEY (document_id)              REFERENCES stock_documents(id)        ON DELETE CASCADE,
  CONSTRAINT fk_slc_pinvoice FOREIGN KEY (purchase_invoice_id)      REFERENCES purchase_invoices(id)      ON DELETE SET NULL,
  CONSTRAINT fk_slc_pii      FOREIGN KEY (purchase_invoice_item_id) REFERENCES purchase_invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Inventury (§29–30 ZoÚ) ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_takes (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  warehouse_id         BIGINT UNSIGNED NOT NULL,
  take_date            DATE NOT NULL,
  status               ENUM('draft','counting','closed') NOT NULL DEFAULT 'draft'
                       COMMENT 'counting blokuje post pohybů na skladu (A13)',
  note                 TEXT NULL,
  receipt_document_id  BIGINT UNSIGNED NULL COMMENT 'rozdílová příjemka (přebytky), origin=inventory',
  issue_document_id    BIGINT UNSIGNED NULL COMMENT 'rozdílová výdejka (manka), origin=inventory',
  created_by           INT UNSIGNED NULL,
  closed_by            BIGINT UNSIGNED NULL,
  closed_at            TIMESTAMP NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_st_supplier_wh_date (supplier_id, warehouse_id, take_date),
  KEY idx_st_supplier_status (supplier_id, status),
  CONSTRAINT fk_st_supplier FOREIGN KEY (supplier_id)  REFERENCES supplier(id)   ON DELETE CASCADE,
  CONSTRAINT fk_st_wh       FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_take_lines (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_take_id  BIGINT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL,
  stock_item_id  BIGINT UNSIGNED NOT NULL,
  expected_qty   DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'snapshot stavu při přechodu do counting',
  expected_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_qty    DECIMAL(14,3) NULL,
  UNIQUE KEY uq_stl (stock_take_id, stock_item_id),
  CONSTRAINT fk_stl_take FOREIGN KEY (stock_take_id) REFERENCES stock_takes(id) ON DELETE CASCADE,
  CONSTRAINT fk_stl_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Pozn.: norm_qty (normy ztratného) vědomě chybí — v2 (rozhodnutí A4).

-- ── ENUM appendy fork-owned tabulek (append-only, idempotentní) ──────────────
-- Řady PRI/VYD/PRE (fork tabulka 1016, vzor 1019). Aktuální stav DB ověřen:
-- enum('closing','opening','fx','transfer','manual','cash_in','cash_out').
ALTER TABLE accounting_document_series
  MODIFY COLUMN series_code
  ENUM('closing','opening','fx','transfer','manual','cash_in','cash_out',
       'stock_in','stock_out','stock_transfer') NOT NULL;

-- source_type='stock' — schema-ready pro způsob A (v2, A1). v1 uzávěrka účtuje
-- zásoby přes source_type='closing' (ClosingService). Aktuální DB stav ověřen:
-- končí na 'fx_revaluation'.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock') NOT NULL DEFAULT 'manual';

-- ── Seedy kontací způsobu B (globální šablona supplier_id NULL, NOT EXISTS guard) ─
-- B-korektní algoritmus inventurních rozdílů a uzávěrky viz spec §3.4 (nález B1).
-- Kontace inventory.shortage.stock (549/112) a inventory.surplus.stock (112/648)
-- z 1006 zůstávají — jsou pro způsob A (v2), v1 je nepoužívá.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
FROM (
            SELECT 'stock.closing.material'          AS rule_key, 'Uzávěrka zásob (způsob B) — konečný stav materiálu'      AS description, '112' AS debit_account_code, '501' AS credit_account_code
  UNION ALL SELECT 'stock.closing.goods',              'Uzávěrka zásob (způsob B) — konečný stav zboží',                     '132', '504'
  UNION ALL SELECT 'stock.opening.material',           'Otevření roku (způsob B) — počáteční stav materiálu do spotřeby',    '501', '112'
  UNION ALL SELECT 'stock.opening.goods',              'Otevření roku (způsob B) — počáteční stav zboží do nákladů',         '504', '132'
  UNION ALL SELECT 'stock.shortage.reclass.material',  'Manko materiálu — reklasifikace do mank (způsob B)',                 '549', '501'
  UNION ALL SELECT 'stock.shortage.reclass.goods',     'Manko zboží — reklasifikace do mank (způsob B)',                     '549', '504'
  UNION ALL SELECT 'stock.surplus.material',           'Inventurní přebytek materiálu (způsob B)',                           '501', '648'
  UNION ALL SELECT 'stock.surplus.goods',              'Inventurní přebytek zboží (způsob B)',                               '504', '648'
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
);
