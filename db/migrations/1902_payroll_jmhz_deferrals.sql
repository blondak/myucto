-- Odložení pracovního vztahu z řádného měsíčního hlášení JMHZ.
--
-- Jeden zaměstnanec s neúplnými daty nesmí zablokovat hlášení za ostatní.
-- Účetní u blokovaného vztahu rozhodne „odložit z tohoto hlášení": řádné
-- hlášení se sestaví bez jeho formuláře (pojistná část a souhrn ho dál
-- obsahují, aby pojistné i sleva byly uplatněny včas) a formulář se doplní
-- opravným hlášením. Rozhodnutí je vázané na schválenou revizi běhu
-- a na přípravu, nad kterou vzniklo; mazat ho nelze, jen odvolat.
--
-- `payroll_jmhz_deferral_submissions` eviduje, do kterého zmrazeného řádného
-- hlášení se odložení promítlo a kterým opravným hlášením se formulář doplnil.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_deferrals (
  id                             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                    INT UNSIGNED NOT NULL,
  run_id                         BIGINT UNSIGNED NOT NULL,
  source_revision_id             BIGINT UNSIGNED NOT NULL,
  period_start                   DATE NOT NULL,
  office_id                      BIGINT UNSIGNED NULL,
  employee_id                    BIGINT UNSIGNED NOT NULL,
  employment_id                  BIGINT UNSIGNED NOT NULL,
  reason                         VARCHAR(500) NOT NULL,
  blocker_codes_json             TEXT NOT NULL CHECK (JSON_VALID(blocker_codes_json)),
  decided_environment            ENUM('production','test') NOT NULL,
  decided_preparation_id         BIGINT UNSIGNED NOT NULL,
  decided_snapshot_fingerprint   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status                         ENUM('active','revoked') NOT NULL DEFAULT 'active',
  active_key                     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin
                                   AS (IF(status = 'active',
                                          CONCAT(source_revision_id, ':', employment_id),
                                          NULL)) PERSISTENT,
  created_by                     BIGINT UNSIGNED NOT NULL,
  created_at                     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_by                     BIGINT UNSIGNED NULL,
  revoked_at                     DATETIME(6) NULL,
  revoke_reason                  VARCHAR(500) NULL,
  row_version                    INT UNSIGNED NOT NULL DEFAULT 1,

  UNIQUE KEY uq_payroll_jmhz_deferral_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_deferral_active (supplier_id, active_key),
  KEY idx_payroll_jmhz_deferral_revision (supplier_id, source_revision_id, status),
  KEY idx_payroll_jmhz_deferral_run (supplier_id, run_id, period_start),

  CONSTRAINT fk_payroll_jmhz_deferral_revision
    FOREIGN KEY (supplier_id, source_revision_id, run_id)
    REFERENCES payroll_run_revisions (supplier_id, id, run_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_office
    FOREIGN KEY (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_preparation
    FOREIGN KEY (supplier_id, decided_environment, decided_preparation_id)
    REFERENCES payroll_jmhz_preparation_snapshots (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_revoker
    FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_jmhz_deferral_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_jmhz_deferral_reason
    CHECK (CHAR_LENGTH(TRIM(reason)) >= 3),
  CONSTRAINT chk_payroll_jmhz_deferral_fingerprint
    CHECK (decided_snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_deferral_revocation
    CHECK (
      (status = 'active' AND revoked_at IS NULL AND revoked_by IS NULL
        AND revoke_reason IS NULL)
      OR (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_by IS NOT NULL
        AND revoke_reason IS NOT NULL AND CHAR_LENGTH(TRIM(revoke_reason)) >= 3)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_deferral_submissions (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  deferral_id                BIGINT UNSIGNED NOT NULL,
  environment                ENUM('production','test') NOT NULL,
  regular_submission_id      BIGINT UNSIGNED NOT NULL,
  correction_submission_id   BIGINT UNSIGNED NULL,
  created_at                 DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at               DATETIME(6) NULL,

  UNIQUE KEY uq_payroll_jmhz_deferral_submission
    (supplier_id, deferral_id, environment, regular_submission_id),
  KEY idx_payroll_jmhz_deferral_submission_regular
    (supplier_id, environment, regular_submission_id),

  CONSTRAINT fk_payroll_jmhz_deferral_submission_deferral
    FOREIGN KEY (supplier_id, deferral_id)
    REFERENCES payroll_jmhz_deferrals (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_submission_regular
    FOREIGN KEY (supplier_id, environment, regular_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_deferral_submission_correction
    FOREIGN KEY (supplier_id, environment, correction_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_deferral_submission_completion
    CHECK ((correction_submission_id IS NULL) = (completed_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_deferral_update_guard//
CREATE TRIGGER trg_payroll_jmhz_deferral_update_guard
BEFORE UPDATE ON payroll_jmhz_deferrals
FOR EACH ROW
BEGIN
  IF OLD.status <> 'active'
     OR NEW.status <> 'revoked'
     OR NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.run_id <=> OLD.run_id)
     OR NOT (NEW.source_revision_id <=> OLD.source_revision_id)
     OR NOT (NEW.period_start <=> OLD.period_start)
     OR NOT (NEW.office_id <=> OLD.office_id)
     OR NOT (NEW.employee_id <=> OLD.employee_id)
     OR NOT (NEW.employment_id <=> OLD.employment_id)
     OR NOT (NEW.reason <=> OLD.reason)
     OR NOT (NEW.blocker_codes_json <=> OLD.blocker_codes_json)
     OR NOT (NEW.decided_environment <=> OLD.decided_environment)
     OR NOT (NEW.decided_preparation_id <=> OLD.decided_preparation_id)
     OR NOT (NEW.decided_snapshot_fingerprint <=> OLD.decided_snapshot_fingerprint)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NEW.row_version <> OLD.row_version + 1
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ deferral can only be revoked once';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_deferral_no_delete//
CREATE TRIGGER trg_payroll_jmhz_deferral_no_delete
BEFORE DELETE ON payroll_jmhz_deferrals
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_deferrals are append-only';
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_deferral_submission_update_guard//
CREATE TRIGGER trg_payroll_jmhz_deferral_submission_update_guard
BEFORE UPDATE ON payroll_jmhz_deferral_submissions
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.deferral_id <=> OLD.deferral_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.regular_submission_id <=> OLD.regular_submission_id)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NEW.correction_submission_id IS NULL
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ deferral binding only records its completion';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_deferral_submission_no_delete//
CREATE TRIGGER trg_payroll_jmhz_deferral_submission_no_delete
BEFORE DELETE ON payroll_jmhz_deferral_submissions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_deferral_submissions are append-only';
END//

DELIMITER ;
