-- MyÚčto.cz — volitelné účetní alokace rekapitulace DPH přijaté faktury.
-- Zdrojové položky a rekapitulace dodavatele zůstávají beze změny; alokace pouze
-- určují rozsah odpočtu a účet MD pro jednotlivé části stejného dokladu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_invoice_vat_allocations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  purchase_invoice_id   BIGINT UNSIGNED NOT NULL,
  description           VARCHAR(190) NOT NULL,
  usage_type            ENUM('business','personal','mixed','non_deductible') NOT NULL DEFAULT 'business',
  vat_rate              DECIMAL(5,2) NOT NULL,
  base_amount           DECIMAL(12,2) NOT NULL,
  vat_amount            DECIMAL(12,2) NOT NULL,
  total_amount          DECIMAL(12,2) NOT NULL,
  vat_deduction         ENUM('full','none','proportional','reduced') NOT NULL DEFAULT 'full',
  vat_deduction_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  tax_treatment         ENUM('deductible','non_deductible','not_expense') NOT NULL DEFAULT 'deductible',
  account_code          VARCHAR(10) NOT NULL,
  vat_classification_code VARCHAR(10) NULL,
  order_index           INT NOT NULL DEFAULT 0,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_piva_invoice (purchase_invoice_id, order_index, id),
  KEY idx_piva_supplier (supplier_id, purchase_invoice_id),
  CONSTRAINT fk_piva_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_piva_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_piva_account FOREIGN KEY (supplier_id, account_code)
    REFERENCES chart_of_accounts(supplier_id, account_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
