-- MyÚčto.cz — MZ-06: idempotentní CSV import docházky s řádkovými chybami.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_time_imports (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  period_start       DATE NOT NULL,
  format             ENUM('csv','xlsx') NOT NULL,
  original_name      VARCHAR(191) NOT NULL,
  content_hash       BINARY(32) NOT NULL,
  status             ENUM('preview','imported','partial','failed','manual_review') NOT NULL,
  total_rows         INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_import_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_time_import_content (supplier_id, period_start, content_hash),
  CONSTRAINT fk_payroll_time_import_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_import_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_time_import_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_time_import_counts
    CHECK (accepted_rows + rejected_rows + duplicate_rows <= total_rows)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_time_import_errors (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  import_id          BIGINT UNSIGNED NOT NULL,
  csv_row_number     INT UNSIGNED NOT NULL,
  error_code         VARCHAR(64) NOT NULL,
  field_name         VARCHAR(64) NULL,
  error_message      VARCHAR(500) NOT NULL,
  row_hash           BINARY(32) NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_import_error_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_time_import_error_row
    (supplier_id, import_id, csv_row_number, error_code, field_name),
  CONSTRAINT fk_payroll_time_import_error_import
    FOREIGN KEY (supplier_id, import_id)
    REFERENCES payroll_time_imports (supplier_id, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
