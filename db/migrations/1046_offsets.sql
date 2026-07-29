-- MyÚčto.cz — Fáze F: vzájemné zápočty pohledávek a závazků (dohoda o zápočtu)
--
-- offset_agreements = hlavička dohody o zápočtu mezi firmou a jedním partnerem
-- (§ 1982 obč. zák. — započtení vzájemných pohledávek). Vlastní číselná řada
-- 'offset' (prefix ZAP) přes DocumentSeriesService. Po potvrzení vznikne jeden
-- idempotentní účetní zápis 321 MD / 311 D (source_type='offset', source_id =
-- offset_agreements.id) a obě strany dokladů se (částečně/plně) vyrovnají.
--
-- offset_agreement_items = konkrétní započtené doklady obou stran s částkou
-- započtenou z každého dokladu (doc_type invoice = pohledávka na 311 → strana D,
-- purchase_invoice = závazek na 321 → strana MD). Σ invoice = Σ purchase = total.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + MODIFY (append-only ENUM).

SET NAMES utf8mb4;

-- journal_entries je system-versioned (1029) → MODIFY sloupce vyžaduje KEEP historie.
SET @@system_versioning_alter_history = 1;

-- Nový source_type pro zápočet (append na konec ENUM — fork hodnota, D6 vzor).
-- POZOR: MODIFY nesmí vynechat žádnou existující hodnotu (jinak by se DROPla) —
-- kompletní seznam z 1015 + 1022 (stock) + 1041 (provision/income_tax/profit_distribution).
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset') NOT NULL DEFAULT 'manual';

-- Číselná řada zápočtů (prefix ZAP) — append na konec ENUM řad.
ALTER TABLE accounting_document_series
  MODIFY COLUMN series_code
  ENUM('closing','opening','fx','transfer','manual','cash_in','cash_out',
       'stock_in','stock_out','stock_transfer','offset') NOT NULL;

-- Hlavička dohody o zápočtu.
CREATE TABLE IF NOT EXISTS offset_agreements (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  partner_id        BIGINT UNSIGNED NOT NULL COMMENT 'clients.id — protistrana zápočtu',
  agreement_date    DATE NOT NULL COMMENT 'datum účinnosti zápočtu (= datum účetního případu)',
  document_no       VARCHAR(50) NOT NULL COMMENT 'číslo z řady offset (ZAP-YYYY-NNNN)',
  total_amount      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'započtená částka (Σ MD = Σ D), CZK',
  status            ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  journal_entry_id  BIGINT UNSIGNED NULL COMMENT 'zaúčtování 321/311 (po potvrzení)',
  note              VARCHAR(500) NULL,
  created_by        INT NULL COMMENT 'user id (evidenční, bez FK — vzor assets.created_by)',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_oa_supplier_docno (supplier_id, document_no),
  KEY idx_oa_supplier (supplier_id, status, agreement_date),
  KEY idx_oa_partner (supplier_id, partner_id),
  CONSTRAINT fk_oa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_oa_partner  FOREIGN KEY (partner_id)  REFERENCES clients(id),
  CONSTRAINT fk_oa_entry    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Položky zápočtu — konkrétní doklad + započtená částka.
CREATE TABLE IF NOT EXISTS offset_agreement_items (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id        BIGINT UNSIGNED NOT NULL,
  supplier_id         INT UNSIGNED NOT NULL COMMENT 'denormalizováno pro tenant filtr',
  doc_type            ENUM('invoice','purchase_invoice') NOT NULL,
  doc_id              BIGINT UNSIGNED NOT NULL COMMENT 'invoices.id / purchase_invoices.id',
  amount              DECIMAL(15,2) NOT NULL COMMENT 'započteno z tohoto dokladu (CZK, kladná)',
  invoice_payment_id  BIGINT UNSIGNED NULL COMMENT 'u vydané faktury: vytvořená platba (pro storno)',
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_oai_agreement (agreement_id),
  KEY idx_oai_doc (supplier_id, doc_type, doc_id),
  CONSTRAINT fk_oai_agreement FOREIGN KEY (agreement_id) REFERENCES offset_agreements(id) ON DELETE CASCADE,
  CONSTRAINT fk_oai_supplier  FOREIGN KEY (supplier_id)  REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontace zápočtu (per-tenant override možný; default 321 MD / 311 D). Idempotentní vzor 1006_.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'offset.mutual', 'Vzájemný zápočet pohledávek a závazků (321/311)', '321', '311', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'offset.mutual');
