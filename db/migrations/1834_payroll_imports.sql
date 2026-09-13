-- MyÚčto.cz — Mzdy → Importy: import měsíčních podkladů z docházkového systému.
--
-- `payroll_import_links` pamatuje, ke kterému pracovnímu vztahu patří osoba
-- z cizího systému (podle osobního čísla nebo jména), aby se párování nemuselo
-- opakovat každý měsíc. `payroll_attendance_imports` + `_rows` jsou evidence
-- dávky s původem každého čísla (soubor!list!buňka); hodiny se nepřevádějí na
-- peníze ani na intervaly docházky. `payroll_import_profiles` drží uložená
-- pravidla mapování sloupců podle hlaviček.
--
-- CHECK omezení jsou jen uvnitř CREATE TABLE IF NOT EXISTS: MariaDB neumí
-- ADD CONSTRAINT IF NOT EXISTS u CHECK, takže opakované spuštění je no-op.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_import_links (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  source_system      VARCHAR(32) NOT NULL,
  key_kind           ENUM('personal_number','name') NOT NULL,
  external_key       VARCHAR(191) NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_import_link_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_import_link_key (supplier_id, source_system, key_kind, external_key),
  KEY idx_payroll_import_link_employment (supplier_id, employment_id),
  KEY idx_payroll_import_link_employee (supplier_id, employee_id),
  CONSTRAINT fk_payroll_import_link_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_import_link_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_import_link_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_import_link_key CHECK (CHAR_LENGTH(TRIM(external_key)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_attendance_imports (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  period_start       DATE NOT NULL,
  source_system      VARCHAR(32) NOT NULL,
  content_sha256     BINARY(32) NOT NULL,
  files_json         LONGTEXT NOT NULL,
  rules_json         LONGTEXT NOT NULL,
  person_count       INT UNSIGNED NOT NULL DEFAULT 0,
  metric_count       INT UNSIGNED NOT NULL DEFAULT 0,
  input_import_id    BIGINT UNSIGNED NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_attendance_import_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_attendance_import_content (supplier_id, period_start, content_sha256),
  KEY idx_payroll_attendance_import_period (supplier_id, period_start, id),
  KEY idx_payroll_attendance_import_input (supplier_id, input_import_id),
  CONSTRAINT fk_payroll_attendance_import_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_attendance_import_input
    FOREIGN KEY (supplier_id, input_import_id)
    REFERENCES payroll_input_imports (supplier_id, id),
  CONSTRAINT fk_payroll_attendance_import_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_attendance_import_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_attendance_import_files CHECK (JSON_VALID(files_json)),
  CONSTRAINT chk_payroll_attendance_import_rules CHECK (JSON_VALID(rules_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_attendance_import_rows (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  import_id            BIGINT UNSIGNED NOT NULL,
  employment_id        BIGINT UNSIGNED NOT NULL,
  meaning              VARCHAR(48) NOT NULL,
  component_code       VARCHAR(64) NOT NULL DEFAULT '',
  quantity_millihours  BIGINT NULL,
  amount_minor         BIGINT NULL,
  source_ref           VARCHAR(191) NOT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_attendance_import_row_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_attendance_import_row (import_id, employment_id, meaning, component_code),
  KEY idx_payroll_attendance_import_row_import (supplier_id, import_id),
  KEY idx_payroll_attendance_import_row_employment (supplier_id, employment_id),
  CONSTRAINT fk_payroll_attendance_import_row_import
    FOREIGN KEY (supplier_id, import_id)
    REFERENCES payroll_attendance_imports (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_attendance_import_row_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_attendance_import_row_value
    CHECK (quantity_millihours IS NOT NULL OR amount_minor IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_import_profiles (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  source_system      VARCHAR(32) NOT NULL,
  name               VARCHAR(120) NOT NULL,
  rules_json         LONGTEXT NOT NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_import_profile_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_import_profile_name (supplier_id, source_system, name),
  CONSTRAINT fk_payroll_import_profile_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_import_profile_user
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_import_profile_rules CHECK (JSON_VALID(rules_json)),
  CONSTRAINT chk_payroll_import_profile_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
