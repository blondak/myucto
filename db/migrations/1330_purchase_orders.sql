-- MyÚčto.cz — Epic SKLAD „na cestě", fáze 1: objednávky vydané dodavateli.
--
-- Objednávka NENÍ účetní případ (§ 11 ZoÚ) — nepřešlo vlastnictví, nevznikl
-- závazek —, takže tahle migrace nezakládá žádnou kontaci ani deníkový zápis.
-- Drží jen dokladovou evidenci a stavový automat; množství „na cestě" se z ní
-- ODVOZUJE dotazem (viz 1331 + InTransitRepository), nematerializuje se do
-- stock_levels (ta tabulka existuje kvůli oceňování, objednané zboží cenu nemá).
--
-- Číslo objednávky přiděluje až send() z řady OBJ (DocumentSeriesService), proto
-- je order_number NULL-able a unikátní jen v rámci firmy.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, MODIFY ENUM append-only (vzor 1022).

SET NAMES utf8mb4;

-- ── Hlavička objednávky ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_orders (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL COMMENT 'tenant; INT UNSIGNED je závazná šířka (SupplierIdColumnWidthTest)',
  vendor_id          BIGINT UNSIGNED NOT NULL COMMENT 'clients.id, typicky is_vendor=1',
  order_number       VARCHAR(30) NULL COMMENT 'OBJ-RRRR-NNNN; přiděluje až send()',
  vendor_reference   VARCHAR(50) NULL COMMENT 'číslo objednávky u dodavatele',
  order_date         DATE NOT NULL,
  expected_date      DATE NULL,
  warehouse_id       BIGINT UNSIGNED NOT NULL COMMENT 'výchozí sklad; řádek smí přebít',
  currency_id        INT UNSIGNED NOT NULL,
  exchange_rate      DECIMAL(12,6) NULL COMMENT 'INDIKATIVNÍ — ocenění určí až příjemka/faktura',
  state              ENUM('draft','sent','confirmed','partially_received',
                          'received','closed','cancelled') NOT NULL DEFAULT 'draft',
  total_without_vat  DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_with_vat     DECIMAL(15,2) NOT NULL DEFAULT 0,
  note               TEXT NULL,
  internal_note      TEXT NULL,
  sent_at            TIMESTAMP NULL DEFAULT NULL,
  confirmed_at       TIMESTAMP NULL DEFAULT NULL,
  confirmed_by       BIGINT UNSIGNED NULL,
  closed_at          TIMESTAMP NULL DEFAULT NULL,
  closed_by          BIGINT UNSIGNED NULL,
  close_reason       VARCHAR(255) NULL,
  cancelled_at       TIMESTAMP NULL DEFAULT NULL,
  cancelled_by       BIGINT UNSIGNED NULL,
  cancel_reason      VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_supplier_number (supplier_id, order_number),
  KEY idx_po_supplier_state (supplier_id, state, order_date),
  KEY idx_po_vendor (supplier_id, vendor_id, state),
  KEY idx_po_expected (supplier_id, state, expected_date),
  CONSTRAINT fk_po_supplier  FOREIGN KEY (supplier_id)  REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_vendor    FOREIGN KEY (vendor_id)    REFERENCES clients(id),
  CONSTRAINT fk_po_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_po_currency  FOREIGN KEY (currency_id)  REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Řádky objednávky ─────────────────────────────────────────────────────────
-- qty_confirmed NULL = dodavatel nepotvrdil odchylku, platí qty_ordered.
-- qty_cancelled = ROZHODNUTÍ uživatele (close „zbytek nedodán"), proto se ukládá;
-- „přijato" se naopak NEUKLÁDÁ, odvozuje se ze stock_document_lines (1331).
CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL COMMENT 'denormalizace pro tenant filtr (vzor stock_document_lines)',
  line_no        INT UNSIGNED NOT NULL DEFAULT 0,
  stock_item_id  BIGINT UNSIGNED NULL COMMENT 'NULL = doprava/služba — do „na cestě" nevstupuje',
  warehouse_id   BIGINT UNSIGNED NULL COMMENT 'přebíjí sklad z hlavičky',
  vendor_sku     VARCHAR(80) NULL,
  description    VARCHAR(500) NOT NULL,
  unit           VARCHAR(20) NOT NULL DEFAULT 'ks',
  qty_ordered    DECIMAL(14,3) NOT NULL,
  qty_confirmed  DECIMAL(14,3) NULL COMMENT 'NULL = platí qty_ordered',
  qty_cancelled  DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'uzavřený (nedodaný) zbytek',
  unit_price     DECIMAL(15,6) NOT NULL DEFAULT 0,
  vat_rate_id    INT UNSIGNED NULL,
  expected_date  DATE NULL,
  has_over_delivery TINYINT(1) NOT NULL DEFAULT 0,
  note           VARCHAR(255) NULL,
  UNIQUE KEY uq_pol_order_line (order_id, line_no),
  KEY idx_pol_item (supplier_id, stock_item_id),
  KEY idx_pol_order (order_id),
  CONSTRAINT fk_pol_order    FOREIGN KEY (order_id)      REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pol_supplier FOREIGN KEY (supplier_id)   REFERENCES supplier(id)        ON DELETE CASCADE,
  CONSTRAINT fk_pol_item     FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
  CONSTRAINT fk_pol_wh       FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id),
  CONSTRAINT fk_pol_vat      FOREIGN KEY (vat_rate_id)   REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── M:N objednávka ↔ přijatá faktura ─────────────────────────────────────────
-- Jedna objednávka může přijít na víc fakturách a jedna faktura krýt víc
-- objednávek, takže vazba nemůže být sloupec na hlavičce.
CREATE TABLE IF NOT EXISTS purchase_order_invoice_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  purchase_invoice_id BIGINT UNSIGNED NOT NULL,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  linked_by BIGINT UNSIGNED NULL,
  match_source ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  UNIQUE KEY uq_poil (order_id, purchase_invoice_id),
  KEY idx_poil_pi (supplier_id, purchase_invoice_id),
  CONSTRAINT fk_poil_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_poil_order FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poil_pi FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Číselná řada OBJ + původ skladového dokladu ──────────────────────────────
-- MODIFY je append-only: pořadí i názvy stávajících hodnot zůstávají, jen
-- přibývá 'purchase_order' na konci (vzor 1022).
ALTER TABLE accounting_document_series MODIFY COLUMN series_code
  ENUM('closing','opening','fx','transfer','manual','cash_in','cash_out',
       'stock_in','stock_out','stock_transfer','offset','purchase_order') NOT NULL;

ALTER TABLE stock_documents MODIFY COLUMN origin
  ENUM('manual','invoice','credit_note','purchase_invoice','inventory','purchase_order')
  NOT NULL DEFAULT 'manual';
