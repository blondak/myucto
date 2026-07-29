-- MyÚčto.cz — E3: registr vlastních bankovních účtů

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_bank_accounts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  currency_id       INT UNSIGNED NULL,
  label             VARCHAR(120) NULL,
  account_number    VARCHAR(34) NULL,
  bank_code         CHAR(4) NULL,
  iban              VARCHAR(34) NULL,
  currency          CHAR(3) NULL,
  account_canonical VARCHAR(20) NOT NULL,
  bank_code_norm    CHAR(4) NOT NULL DEFAULT '',
  kind              ENUM('current','savings','term_deposit') NOT NULL DEFAULT 'current',
  analytic_suffix   VARCHAR(6) NULL,
  source            ENUM('currencies','statement','manual') NOT NULL DEFAULT 'manual',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_sba_account (supplier_id, account_canonical, bank_code_norm),
  KEY idx_sba_supplier (supplier_id, is_active),
  CONSTRAINT fk_sba_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sba_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL,
  CONSTRAINT chk_sba_bank_norm CHECK (bank_code_norm = COALESCE(bank_code, ''))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO supplier_bank_accounts
       (supplier_id, currency_id, label, account_number, bank_code, bank_code_norm, iban, currency,
        account_canonical, kind, source)
SELECT src.supplier_id,
       CASE WHEN COUNT(DISTINCT src.code) = 1 THEN MIN(src.id) ELSE NULL END,
       MIN(src.label), MAX(src.account_number), src.bank_code_norm, COALESCE(src.bank_code_norm, ''),
       MAX(src.iban),
       CASE WHEN COUNT(DISTINCT src.code) = 1 THEN MIN(src.code) ELSE NULL END,
       src.account_canonical, 'current', 'currencies'
  FROM (
    SELECT c.*,
      CASE
        WHEN UPPER(REPLACE(COALESCE(c.account_number, ''), ' ', '')) REGEXP '^CZ[0-9]{22}$'
          THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REPLACE(c.account_number, ' ', '')), 9, 16))
        WHEN c.account_number IS NOT NULL AND TRIM(c.account_number) <> ''
          THEN TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(c.account_number, '/', 1), '[^0-9]', ''))
        WHEN UPPER(REPLACE(COALESCE(c.iban, ''), ' ', '')) REGEXP '^CZ[0-9]{22}$'
          THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REPLACE(c.iban, ' ', '')), 9, 16))
        ELSE NULL
      END AS account_canonical,
      COALESCE(NULLIF(c.bank_code, ''),
        CASE WHEN UPPER(REPLACE(COALESCE(c.iban, ''), ' ', '')) REGEXP '^CZ[0-9]{22}$'
          THEN SUBSTRING(UPPER(REPLACE(c.iban, ' ', '')), 5, 4) END) AS bank_code_norm
      FROM currencies c
     WHERE c.account_number IS NOT NULL OR c.iban IS NOT NULL
  ) src
 WHERE src.account_canonical IS NOT NULL AND src.account_canonical <> ''
   AND NOT EXISTS (
     SELECT 1 FROM supplier_bank_accounts s
      WHERE s.supplier_id = src.supplier_id
        AND s.account_canonical = src.account_canonical
        AND s.bank_code_norm = COALESCE(src.bank_code_norm, '')
   )
 GROUP BY src.supplier_id, src.account_canonical, src.bank_code_norm;

INSERT INTO supplier_bank_accounts
       (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source)
SELECT src.supplier_id, CONCAT('Účet ', src.account_number), src.account_number,
       NULLIF(src.bank_code, ''), COALESCE(NULLIF(src.bank_code, ''), ''), src.currency,
       src.account_canonical, 'current', 'statement'
  FROM (
    SELECT MIN(owned.account_number) AS account_number, owned.bank_code,
           CASE WHEN COUNT(DISTINCT owned.currency) = 1 THEN MIN(owned.currency) ELSE NULL END AS currency,
           owned.account_canonical,
           MIN(owned.supplier_id) AS supplier_id,
           COUNT(DISTINCT owned.supplier_id) AS owners
      FROM (
        SELECT bs.account_number,
               COALESCE(NULLIF(bs.bank_code, ''),
                 CASE WHEN UPPER(REPLACE(COALESCE(bs.account_number, ''), ' ', '')) REGEXP '^CZ[0-9]{22}$'
                   THEN SUBSTRING(UPPER(REPLACE(bs.account_number, ' ', '')), 5, 4) END) AS bank_code,
               bs.currency,
               CASE
                 WHEN UPPER(REPLACE(COALESCE(bs.account_number, ''), ' ', '')) REGEXP '^CZ[0-9]{22}$'
                   THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REPLACE(bs.account_number, ' ', '')), 9, 16))
                 ELSE TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(bs.account_number, '/', 1), '[^0-9]', ''))
               END AS account_canonical,
               o.supplier_id
          FROM bank_statements bs
          JOIN bank_transactions bt ON bt.statement_id = bs.id
          JOIN (
            SELECT bt1.id AS tx_id, i.supplier_id
              FROM bank_transactions bt1
              JOIN invoices i ON i.id = bt1.matched_invoice_id
             WHERE bt1.matched_invoice_id IS NOT NULL
            UNION ALL
            SELECT ip.bank_transaction_id, ip.supplier_id
              FROM invoice_payments ip
             WHERE ip.bank_transaction_id IS NOT NULL
            UNION ALL
            SELECT pm.bank_transaction_id, pm.supplier_id
              FROM payment_matches pm
          ) o ON o.tx_id = bt.id
      ) owned
     WHERE owned.account_canonical <> ''
     GROUP BY owned.bank_code, owned.account_canonical
    HAVING COUNT(DISTINCT owned.supplier_id) = 1
  ) src
 WHERE NOT EXISTS (
   SELECT 1 FROM supplier_bank_accounts s
    WHERE s.supplier_id = src.supplier_id
      AND s.account_canonical = src.account_canonical
      AND s.bank_code_norm = COALESCE(NULLIF(src.bank_code, ''), '')
 );
