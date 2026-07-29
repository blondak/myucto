-- MyÚčto.cz — Mini-epic POKLADNA (#14): hotovostní pokladna (PPD/VPD)
--
-- Aditivně vůči upstream MyInvoice (fork). Pokladny (analytiky 211), pokladní
-- doklady příjmové/výdajové se 6 účely, DPH rozpad per sazba. Zaúčtování přes
-- PostingService (source_type='cash'), číselné řady PPD/VPD přes
-- accounting_document_series. Legislativa: §11 ZoÚ (náležitosti dokladu),
-- ČÚS 016 (krátkodobý finanční majetek), §29/§30/§37 ZDPH, §101c+ (KH).
--
-- CZK-only v1 (rozhodnutí O4) — sloupce currency_code/fx_rate zůstávají
-- schema-ready pro budoucí valutovou pokladnu (R10, vědomě odloženo).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, MODIFY ENUM append-only (2× bez chyby),
-- seedy kontací s NOT EXISTS guardem.

SET NAMES utf8mb4;

-- ── Číselník pokladen — analytiky účtu 211 (rozhodnutí O4, R6) ───────────────
CREATE TABLE IF NOT EXISTS cash_registers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'CZK'
                COMMENT 'v1 vynuceně CZK (service), sloupec schema-ready pro valutovou pokladnu',
  account_code  VARCHAR(10) NOT NULL DEFAULT '211'
                COMMENT 'Účet/analytika pokladny (211, 211100…) — musí existovat v osnově firmy',
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cashreg_supplier_name    (supplier_id, name),
  UNIQUE KEY uq_cashreg_supplier_account (supplier_id, account_code),
  CONSTRAINT fk_cashreg_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pokladní doklady PPD/VPD (životní cyklus draft → posted → reversed, O2) ──
CREATE TABLE IF NOT EXISTS cash_documents (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  register_id          BIGINT UNSIGNED NOT NULL,
  doc_type             ENUM('in','out') NOT NULL COMMENT 'in = PPD (příjem), out = VPD (výdej)',
  purpose              ENUM('sale','purchase','invoice_payment','purchase_payment','transfer','other')
                       NOT NULL COMMENT 'určuje builder zaúčtování, viz CashDocumentService',
  doc_number           VARCHAR(30) NULL COMMENT 'PPD-2026-0001 — přiděleno při prvním post()',
  issue_date           DATE NOT NULL COMMENT 'datum vystavení = datum pokladního pohybu = entry_date',
  tax_date             DATE NULL COMMENT 'DUZP daňového dokladu (default = issue_date)',
  partner_name         VARCHAR(255) NULL COMMENT '§11/1/b — účastník (volný text, bez FK na clients v1)',
  partner_ic           VARCHAR(20) NULL,
  partner_dic          VARCHAR(20) NULL COMMENT 'pro KH A.4 nad 10 000 Kč (prodej)',
  description          VARCHAR(255) NOT NULL COMMENT '§11/1/c — obsah účetního případu',
  vat_mode             ENUM('none','vat') NOT NULL DEFAULT 'none',
  total_amount         DECIMAL(15,2) NOT NULL COMMENT 'celkem vč. DPH, > 0',
  currency_code        CHAR(3) NOT NULL DEFAULT 'CZK' COMMENT 'kopie z registru v okamžiku vzniku',
  fx_rate              DECIMAL(12,6) NOT NULL DEFAULT 1 COMMENT 'v1 vždy 1 (CZK-only)',
  rule_key             VARCHAR(64) NULL COMMENT 'kontace posting_rules (cash.revenue…), NULL = counter_account_code',
  counter_account_code VARCHAR(10) NULL COMMENT 'volný protiúčet pro purpose=other',
  invoice_id           BIGINT UNSIGNED NULL COMMENT 'úhrada FV hotově (purpose=invoice_payment)',
  purchase_invoice_id  BIGINT UNSIGNED NULL COMMENT 'úhrada PF hotově (purpose=purchase_payment)',
  invoice_payment_id   BIGINT UNSIGNED NULL COMMENT 'vytvořený záznam invoice_payments — cleanup při stornu',
  journal_entry_id     BIGINT UNSIGNED NULL,
  reversal_entry_id    BIGINT UNSIGNED NULL COMMENT 'protizápis při stornu',
  status               ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cashdoc_supplier_number (supplier_id, doc_number),
  KEY idx_cashdoc_register_date (register_id, issue_date),
  KEY idx_cashdoc_supplier_status (supplier_id, status, issue_date),
  KEY idx_cashdoc_invoice (invoice_id),
  KEY idx_cashdoc_purchase (purchase_invoice_id),
  CONSTRAINT fk_cashdoc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_cashdoc_register FOREIGN KEY (register_id) REFERENCES cash_registers(id),
  CONSTRAINT fk_cashdoc_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_cashdoc_pinvoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_cashdoc_entry    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DPH rozpad daňového cash dokladu per sazba (R1) ─────────────────────────
CREATE TABLE IF NOT EXISTS cash_document_vat_lines (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_document_id        BIGINT UNSIGNED NOT NULL,
  vat_rate                DECIMAL(5,2) NOT NULL COMMENT '21.00 / 12.00 — v1 jen sazby > 0 (O5c)',
  base_amount             DECIMAL(15,2) NOT NULL,
  vat_amount              DECIMAL(15,2) NOT NULL,
  vat_classification_code VARCHAR(10) NULL COMMENT 'override klasifikace; NULL = auto dle sazby a směru. V1 FE neposílá (E1)',
  KEY idx_cdvl_document (cash_document_id),
  CONSTRAINT fk_cdvl_document FOREIGN KEY (cash_document_id)
    REFERENCES cash_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ENUM appendy (append-only, fork hodnoty na konci) ───────────────────────
-- Řady PPD/VPD (fork tabulka 1016 — bez upstream rizika, D3):
ALTER TABLE accounting_document_series
  MODIFY COLUMN series_code
  ENUM('closing','opening','fx','transfer','manual','cash_in','cash_out') NOT NULL;

-- Zdroj platby (upstream tabulka 0108 — append-only, fork hodnota 'cash' na konci; D2):
ALTER TABLE invoice_payments
  MODIFY COLUMN source ENUM('manual','mark_paid','bank','legacy','cash') NOT NULL DEFAULT 'manual';

-- ── Seedy kontací pokladny (globální šablona supplier_id NULL, NOT EXISTS guard) ──
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
FROM (
              SELECT 'payment.receivable.cash' AS rule_key, 'Úhrada vydané faktury hotově'       AS description, '211' AS debit_account_code, '311' AS credit_account_code
    UNION ALL SELECT 'payment.payable.cash',              'Úhrada přijaté faktury hotově',                 '321', '211'
    UNION ALL SELECT 'cash.transfer.frombank',            'Dotace pokladny z banky (přes 261)',            '211', '261'
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
);
