-- MyÚčto.cz — MZ-06: historické pracovní kalendáře a fond pracovní doby.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_work_calendars (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  name               VARCHAR(191) NOT NULL,
  timezone_name      VARCHAR(64) NOT NULL DEFAULT 'Europe/Prague',
  schedule_type      ENUM('regular','irregular','shift') NOT NULL DEFAULT 'regular',
  week_pattern       JSON NOT NULL,
  weekly_minutes     SMALLINT UNSIGNED NOT NULL,
  valid_from         DATE NOT NULL,
  valid_to           DATE NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_calendar_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_calendar_start (supplier_id, employment_id, valid_from),
  KEY idx_payroll_calendar_effective
    (supplier_id, employment_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_calendar_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_calendar_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_calendar_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_calendar_weekly_minutes
    CHECK (weekly_minutes <= 10080)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_calendar_days (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  calendar_id        BIGINT UNSIGNED NOT NULL,
  day_date           DATE NOT NULL,
  day_kind           ENUM('workday','non_working','holiday') NOT NULL,
  planned_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  holiday_code       VARCHAR(32) NULL,
  holiday_name       VARCHAR(191) NULL,
  note               VARCHAR(255) NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_calendar_day_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_calendar_day_date (supplier_id, calendar_id, day_date),
  CONSTRAINT fk_payroll_calendar_day_calendar
    FOREIGN KEY (supplier_id, calendar_id)
    REFERENCES payroll_work_calendars (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_calendar_day_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_calendar_day_minutes
    CHECK (planned_minutes <= 1440),
  CONSTRAINT chk_payroll_calendar_holiday
    CHECK (
      day_kind <> 'holiday'
      OR (holiday_code IS NOT NULL AND holiday_name IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
