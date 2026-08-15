-- MZ-22-W06: append-only ledger pokusů o odeslání mzdového podání.
--
-- Vzorem je `tax_submission_attempts` z 1141, ale s tenantovým i prostředím
-- scopovaným klíčem jako ostatní payroll tabulky: FK na `payroll_submissions`
-- je kompozitní, aby pokus nemohl přeskočit z testu do produkce.
--
-- Řádek je důkaz o jednom pokusu, ne stavová proměnná. Po založení se smí měnit
-- jen stav a jeho doprovod (odpověď, chyba, časy, plán opakování); identita
-- pokusu — podání, kanál, pořadí, idempotenční klíč a otisk požadavku — je
-- neměnná a mazat se nesmí nic.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_submission_transport_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  channel               ENUM(
    'manual_upload','isds','vrep_apep','pikr','health_portal','other'
  ) NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  status                ENUM(
    'prepared','sent','awaiting_protocol','completed','failed','expired'
  ) NOT NULL DEFAULT 'prepared',
  idempotency_key_hash  BINARY(32) NOT NULL,
  correlation_reference VARCHAR(128) NULL,
  request_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  response_http_status  SMALLINT UNSIGNED NULL,
  error_code            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  error_message         VARCHAR(500) NULL,
  next_retry_at         DATETIME NULL,
  sent_at               DATETIME NULL,
  completed_at          DATETIME NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_transport_attempts_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_transport_attempts_environment_id
    (supplier_id, environment, id),
  -- Idempotenční klíč už tenanta i prostředí obsahuje, takže shodný otisk
  -- napříč firmami by znamenal shodný klíč — unikát je proto globální.
  UNIQUE KEY uq_payroll_transport_attempts_idempotency
    (idempotency_key_hash),
  UNIQUE KEY uq_payroll_transport_attempts_order
    (supplier_id, environment, submission_id, attempt_no),
  KEY idx_payroll_transport_attempts_retry
    (supplier_id, status, next_retry_at),
  KEY idx_payroll_transport_attempts_correlation
    (supplier_id, environment, channel, correlation_reference),

  CONSTRAINT fk_payroll_transport_attempts_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_transport_attempts_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_transport_attempts_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_transport_attempts_order
    CHECK (attempt_no > 0 AND row_version > 0),
  CONSTRAINT chk_payroll_transport_attempts_request
    CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_transport_attempts_correlation
    CHECK (
      correlation_reference IS NULL
      OR correlation_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
    ),
  CONSTRAINT chk_payroll_transport_attempts_error_code
    CHECK (
      error_code IS NULL
      OR error_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
    ),
  -- Neúspěch bez kódu chyby by z ledgeru udělal nečitelný záznam a znemožnil
  -- rozhodnout, jestli se smí opakovat.
  CONSTRAINT chk_payroll_transport_attempts_failure
    CHECK (
      status <> 'failed'
      OR (error_code IS NOT NULL AND error_message IS NOT NULL)
    ),
  CONSTRAINT chk_payroll_transport_attempts_sent
    CHECK (
      (status = 'prepared' AND sent_at IS NULL)
      OR (
        status IN ('sent','awaiting_protocol','completed')
        AND sent_at IS NOT NULL
      )
      OR status IN ('failed','expired')
    ),
  CONSTRAINT chk_payroll_transport_attempts_completed
    CHECK (
      (status = 'completed' AND completed_at IS NOT NULL)
      OR (status <> 'completed' AND completed_at IS NULL)
    ),
  CONSTRAINT chk_payroll_transport_attempts_timeline
    CHECK (
      completed_at IS NULL
      OR sent_at IS NULL
      OR completed_at >= sent_at
    ),
  -- Odeslané podání musí nést correlation reference, jinak se protokol
  -- nedá spárovat zpět a podání se ztratí.
  CONSTRAINT chk_payroll_transport_attempts_tracking
    CHECK (
      status NOT IN ('awaiting_protocol','completed')
      OR correlation_reference IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_transport_attempts_insert_guard//
CREATE TRIGGER trg_payroll_transport_attempts_insert_guard
BEFORE INSERT ON payroll_submission_transport_attempts
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_submissions submission
     WHERE submission.supplier_id = NEW.supplier_id
       AND submission.environment = NEW.environment
       AND submission.id = NEW.submission_id
       AND submission.channel = NEW.channel
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt channel must match its submission';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_transport_attempts_update_guard//
CREATE TRIGGER trg_payroll_transport_attempts_update_guard
BEFORE UPDATE ON payroll_submission_transport_attempts
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.submission_id <=> OLD.submission_id)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.attempt_no <=> OLD.attempt_no)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.request_sha256 <=> OLD.request_sha256)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt identity is immutable';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt row_version must advance by one';
  END IF;

  -- Correlation reference je jednorázové přiřazení: přepsat ji znamená
  -- přehodit důkaz o odeslání na jiné podání.
  IF OLD.correlation_reference IS NOT NULL
     AND NOT (NEW.correlation_reference <=> OLD.correlation_reference)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt correlation reference is single-assignment';
  END IF;

  IF OLD.status IN ('completed','expired') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'terminal transport attempt cannot be reopened';
  END IF;

  -- Jednou odeslaný pokus zůstává odeslaný. Bez tohohle šlo pokus vrátit
  -- z 'sent' na 'prepared' a vynulovat sent_at, čímž zmizel důkaz o tom,
  -- že zpráva u ČSSZ byla — a ledger, který smí zapomenout odeslání, není
  -- ledger, ale stavová proměnná.
  IF OLD.sent_at IS NOT NULL AND NOT (NEW.sent_at <=> OLD.sent_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt sent_at is single-assignment';
  END IF;

  IF OLD.status <> 'prepared' AND NEW.status = 'prepared' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt cannot return to prepared';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_transport_attempts_no_delete//
CREATE TRIGGER trg_payroll_transport_attempts_no_delete
BEFORE DELETE ON payroll_submission_transport_attempts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_submission_transport_attempts are append-only';
END//

DELIMITER ;
