-- 1169: Trvalé rozlišení ostrého a zkušebního prostředí u EPO pokusů.

DELETE FROM migrations
WHERE filename = '1201_epo_test_environment.sql';

ALTER TABLE tax_submission_attempts
  ADD COLUMN IF NOT EXISTS epo_environment ENUM('production','test')
    NOT NULL DEFAULT 'production' AFTER channel,
  ADD KEY IF NOT EXISTS idx_tsa_epo_environment
    (epo_environment, channel, status, requested_at);

ALTER TABLE tax_submission_status_events
  ADD COLUMN IF NOT EXISTS epo_environment ENUM('production','test')
    NOT NULL DEFAULT 'production' AFTER attempt_id,
  ADD KEY IF NOT EXISTS idx_tsse_epo_environment
    (epo_environment, created_at);
