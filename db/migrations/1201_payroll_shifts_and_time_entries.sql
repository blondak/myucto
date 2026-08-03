-- MyÚčto.cz — MZ-06: publikované směny a skutečně odpracovaný čas.
-- Intervaly jsou uloženy jako UTC instants a vždy nesou původní IANA timezone.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_shifts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  calendar_id        BIGINT UNSIGNED NULL,
  series_key         CHAR(32) NOT NULL,
  revision_no        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_id      BIGINT UNSIGNED NULL,
  starts_at_utc      DATETIME NOT NULL,
  ends_at_utc        DATETIME NOT NULL,
  timezone_name      VARCHAR(64) NOT NULL,
  break_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  remote_work        TINYINT(1) NOT NULL DEFAULT 0,
  standby_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status             ENUM('draft','published','superseded') NOT NULL DEFAULT 'draft',
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  published_by       BIGINT UNSIGNED NULL,
  published_at       DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_shift_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_shift_revision (supplier_id, series_key, revision_no),
  KEY idx_payroll_shift_month (supplier_id, employment_id, starts_at_utc, status),
  KEY idx_payroll_shift_supersedes (supplier_id, supersedes_id),
  CONSTRAINT fk_payroll_shift_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_shift_calendar
    FOREIGN KEY (supplier_id, calendar_id)
    REFERENCES payroll_work_calendars (supplier_id, id),
  CONSTRAINT fk_payroll_shift_supersedes
    FOREIGN KEY (supplier_id, supersedes_id)
    REFERENCES payroll_shifts (supplier_id, id),
  CONSTRAINT fk_payroll_shift_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_shift_publisher
    FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_shift_interval CHECK (ends_at_utc > starts_at_utc),
  CONSTRAINT chk_payroll_shift_break CHECK (break_minutes <= 1440),
  CONSTRAINT chk_payroll_shift_standby CHECK (standby_minutes <= 10080),
  CONSTRAINT chk_payroll_shift_remote CHECK (remote_work IN (0, 1)),
  CONSTRAINT chk_payroll_shift_publication
    CHECK (
      (status = 'draft' AND published_at IS NULL)
      OR status = 'superseded'
      OR (status = 'published' AND published_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_time_entries (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  series_key         CHAR(32) NOT NULL,
  revision_no        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_id      BIGINT UNSIGNED NULL,
  category           ENUM(
                       'regular',
                       'overtime',
                       'night',
                       'weekend',
                       'holiday',
                       'difficult_environment'
                     ) NOT NULL,
  starts_at_utc      DATETIME NOT NULL,
  ends_at_utc        DATETIME NOT NULL,
  timezone_name      VARCHAR(64) NOT NULL,
  break_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_kind        ENUM('manual','import','schedule') NOT NULL DEFAULT 'manual',
  source_reference   VARCHAR(191) NULL,
  source_hash        BINARY(32) NOT NULL,
  status             ENUM('draft','approved','superseded') NOT NULL DEFAULT 'draft',
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  approved_by        BIGINT UNSIGNED NULL,
  approved_at        DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_entry_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_time_entry_revision (supplier_id, series_key, revision_no),
  UNIQUE KEY uq_payroll_time_entry_source
    (supplier_id, employment_id, source_kind, source_hash),
  KEY idx_payroll_time_entry_month
    (supplier_id, employment_id, starts_at_utc, status, category),
  KEY idx_payroll_time_entry_supersedes (supplier_id, supersedes_id),
  CONSTRAINT fk_payroll_time_entry_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_entry_supersedes
    FOREIGN KEY (supplier_id, supersedes_id)
    REFERENCES payroll_time_entries (supplier_id, id),
  CONSTRAINT fk_payroll_time_entry_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_time_entry_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_time_entry_interval CHECK (ends_at_utc > starts_at_utc),
  CONSTRAINT chk_payroll_time_entry_break CHECK (break_minutes <= 1440),
  CONSTRAINT chk_payroll_time_entry_approval
    CHECK (
      (status = 'draft' AND approved_at IS NULL)
      OR status = 'superseded'
      OR (status = 'approved' AND approved_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
