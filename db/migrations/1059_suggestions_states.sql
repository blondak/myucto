-- MyÚčto.cz — E4: rozšířené stavy a provenance bankovních návrhů

SET NAMES utf8mb4;

-- Sloupce status/source nelze změnit, dokud je status použit v pending_tx.
ALTER TABLE bank_posting_suggestions DROP INDEX IF EXISTS uq_bps_pending;
ALTER TABLE bank_posting_suggestions DROP COLUMN IF EXISTS pending_tx;

ALTER TABLE bank_posting_suggestions
  MODIFY COLUMN status ENUM('pending','approved','rejected','auto_posted','superseded',
                            'needs_input','blocked') NOT NULL DEFAULT 'pending',
  MODIFY COLUMN source ENUM('rule','learned','payment_match','transfer','detector','schedule','knn','llm') NOT NULL,
  ADD COLUMN IF NOT EXISTS confidence DECIMAL(3,2) NULL COMMENT 'škála master §3.3; NULL = před-1059',
  ADD COLUMN IF NOT EXISTS detector VARCHAR(40) NULL COMMENT 'tax_remittance, own_transfer…',
  ADD COLUMN IF NOT EXISTS operation_type VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS tax_advance_schedule_id INT UNSIGNED NULL;

ALTER TABLE bank_posting_suggestions
  ADD COLUMN IF NOT EXISTS pending_tx BIGINT UNSIGNED
    AS (IF(status IN ('pending','needs_input','blocked'), bank_transaction_id, NULL)) PERSISTENT;
CREATE UNIQUE INDEX IF NOT EXISTS uq_bps_pending ON bank_posting_suggestions (pending_tx);

ALTER TABLE bank_posting_suggestions DROP FOREIGN KEY IF EXISTS fk_bps_schedule;
ALTER TABLE bank_posting_suggestions
  ADD CONSTRAINT fk_bps_schedule
    FOREIGN KEY (tax_advance_schedule_id) REFERENCES tax_advance_schedules(id) ON DELETE SET NULL;

UPDATE bank_posting_suggestions
   SET status = 'blocked'
 WHERE status = 'pending' AND note = 'period_closed';

UPDATE bank_posting_suggestions
   SET status = 'blocked'
 WHERE status = 'pending' AND note = 'cross_currency';

UPDATE bank_posting_suggestions
   SET status = 'needs_input'
 WHERE status = 'pending' AND note IN ('already_paid_verify');

UPDATE bank_posting_suggestions
   SET status = 'needs_input'
 WHERE status = 'pending' AND note LIKE 'duplicate_suspect:%';
