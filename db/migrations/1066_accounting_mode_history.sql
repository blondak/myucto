SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_accounting_modes (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  effective_from  DATE NOT NULL,
  accounting_mode ENUM('tax_evidence','double_entry') NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_accounting_mode_date (supplier_id, effective_from),
  KEY idx_supplier_accounting_mode_lookup (supplier_id, effective_from, accounting_mode),
  CONSTRAINT fk_supplier_accounting_mode_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
SELECT id, '1900-01-01', accounting_mode FROM supplier;
