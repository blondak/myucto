-- MyÚčto.cz — ruční potvrzení přijetí podání podle aplikace úřadu.
--
-- Účetní vidí v aplikaci ČSSZ, že hlášení přijato bylo (případně ho tam ČSSZ
-- nebo ona sama upravila), ale ověřený protokol do MyÚčta nedorazil. Podání
-- se pak ručně označí za přijaté a tahle tabulka drží, KDO to tvrdil, KDY
-- (UTC), O CO se opřel (povinná poznámka, volitelně příloha) a z jakého stavu.
--
-- Záměrně to NENÍ řádek v `payroll_submission_receipts`: ten je výrok úřadu
-- doložený podepsaným protokolem. Ruční potvrzení je výrok člověka a musí
-- zůstat poznat i poté, co pozdější ověřený protokol řekne něco jiného.
--
-- Řádky jsou neměnné. Opakované potvrzení po rozporu s protokolem je nový
-- řádek, takže historie zůstává celá.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_submission_manual_acceptances (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  environment                   ENUM('production','test') NOT NULL,
  submission_id                 BIGINT UNSIGNED NOT NULL,
  obligation_id                 BIGINT UNSIGNED NOT NULL,
  variant                       ENUM('unchanged','changed_by_authority') NOT NULL,
  status_before                 ENUM(
    'submitted','processing','waiting_for_identity',
    'partially_accepted','rejected','correction_required'
  ) NOT NULL,
  submission_row_version_before INT UNSIGNED NOT NULL,
  note                          VARCHAR(1000) NOT NULL,
  authority_accepted_on         DATE NULL,
  attachment_artifact_id        BIGINT UNSIGNED NULL,
  superseded_predecessor        TINYINT(1) NOT NULL DEFAULT 0,
  request_fingerprint           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash          BINARY(32) NOT NULL,
  recorded_by                   BIGINT UNSIGNED NOT NULL,
  recorded_at                   DATETIME NOT NULL,

  UNIQUE KEY uq_payroll_submission_manual_acceptances_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_manual_acceptance_idempotency
    (supplier_id, environment, idempotency_key_hash),
  KEY idx_payroll_submission_manual_acceptances_submission
    (supplier_id, environment, submission_id, id),
  KEY idx_payroll_submission_manual_acceptances_obligation
    (supplier_id, environment, obligation_id),
  KEY idx_payroll_submission_manual_acceptances_attachment
    (supplier_id, environment, submission_id, attachment_artifact_id),

  CONSTRAINT fk_payroll_submission_manual_acceptance_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_manual_acceptance_obligation
    FOREIGN KEY (supplier_id, environment, obligation_id)
    REFERENCES payroll_obligations (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_manual_acceptance_attachment
    FOREIGN KEY (supplier_id, environment, submission_id, attachment_artifact_id)
    REFERENCES payroll_submission_artifacts (supplier_id, environment, submission_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_manual_acceptance_user
    FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_manual_acceptance_shape CHECK (
    submission_row_version_before > 0
    AND superseded_predecessor IN (0, 1)
    AND CHAR_LENGTH(TRIM(note)) BETWEEN 10 AND 1000
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_submission_manual_acceptance_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_submission_manual_acceptance_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_submission_manual_acceptance_immutable_update
BEFORE UPDATE ON payroll_submission_manual_acceptances
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission manual acceptance is immutable';
END//

CREATE TRIGGER trg_payroll_submission_manual_acceptance_immutable_delete
BEFORE DELETE ON payroll_submission_manual_acceptances
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll submission manual acceptance is immutable';
END//

DELIMITER ;
