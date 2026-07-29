-- MyÚčto.cz — §7: dávkové storno a odložení položek kokpitu Automat

SET NAMES utf8mb4;

ALTER TABLE bank_posting_suggestions
  ADD COLUMN IF NOT EXISTS batch_id CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS snoozed_until DATETIME NULL,
  ADD COLUMN IF NOT EXISTS snooze_reason VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS snoozed_by BIGINT UNSIGNED NULL;

CREATE INDEX IF NOT EXISTS idx_bps_supplier_batch
  ON bank_posting_suggestions (supplier_id, batch_id);

CREATE INDEX IF NOT EXISTS idx_bps_supplier_snooze
  ON bank_posting_suggestions (supplier_id, status, snoozed_until);

ALTER TABLE bank_posting_suggestions DROP FOREIGN KEY IF EXISTS fk_bps_snoozed_by;
ALTER TABLE bank_posting_suggestions
  ADD CONSTRAINT fk_bps_snoozed_by
    FOREIGN KEY (snoozed_by) REFERENCES users(id) ON DELETE SET NULL;
