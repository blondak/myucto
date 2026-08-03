-- MyÚčto.cz — MZ-09: idempotentní stavové příkazy mzdového běhu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_run_commands (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NULL,
  command_name          VARCHAR(32) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  request_hash          CHAR(64) NOT NULL,
  expected_row_version  INT UNSIGNED NOT NULL,
  from_status           VARCHAR(32) NOT NULL,
  to_status             VARCHAR(32) NOT NULL,
  result_json           LONGTEXT NOT NULL CHECK (JSON_VALID(result_json)),
  actor_user_id         BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_run_command_idempotency
    (supplier_id, idempotency_key_hash),
  UNIQUE KEY uq_payroll_run_command_supplier_id (supplier_id, id),
  KEY idx_payroll_run_command_timeline (supplier_id, run_id, id),
  CONSTRAINT fk_payroll_run_command_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_command_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_command_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_run_command_request_hash
    CHECK (request_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_run_revision_immutable_update
BEFORE UPDATE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  IF OLD.status IN ('approved', 'superseded') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Approved payroll run revision is immutable';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_run_revision_immutable_delete
BEFORE DELETE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll run revisions are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_run_event_append_only_update
BEFORE UPDATE ON payroll_run_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll run events are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_run_event_append_only_delete
BEFORE DELETE ON payroll_run_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll run events are append-only';
END//

DELIMITER ;
