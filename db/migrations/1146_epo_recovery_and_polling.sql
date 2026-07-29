-- 1146: Bezpečné řešení nejistých EPO pokusů a řízený polling.

ALTER TABLE tax_submission_attempts
  ADD COLUMN IF NOT EXISTS poll_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_status_at,
  ADD COLUMN IF NOT EXISTS next_poll_at TIMESTAMP NULL AFTER poll_count,
  ADD COLUMN IF NOT EXISTS resolution_code VARCHAR(64) NULL AFTER next_poll_at,
  ADD COLUMN IF NOT EXISTS resolution_note VARCHAR(500) NULL AFTER resolution_code,
  ADD COLUMN IF NOT EXISTS resolved_by BIGINT UNSIGNED NULL AFTER resolution_note,
  ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP NULL AFTER resolved_by,
  ADD KEY IF NOT EXISTS idx_tsa_epo_poll (channel, next_poll_at, status);

ALTER TABLE tax_submission_attempts
  DROP FOREIGN KEY IF EXISTS fk_tsa_resolved_by;

ALTER TABLE tax_submission_attempts
  ADD CONSTRAINT fk_tsa_resolved_by
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL;
