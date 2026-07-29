-- MyÚčto.cz — E8: AI návrhy, fronta úloh, metriky a kill-switch

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS ai_assist_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ai_assist_scope SET('bank_tx','purchase_invoices') NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS ai_pseudo_salt VARBINARY(32) NULL,
  ADD COLUMN IF NOT EXISTS ai_dpa_confirmations JSON NULL;

ALTER TABLE bank_posting_suggestions
  ADD COLUMN IF NOT EXISTS ai_reasoning VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS ai_model VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS ai_provider VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS ai_prompt_version VARCHAR(16) NULL;

CREATE TABLE IF NOT EXISTS ai_suggestions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  entity_type ENUM('purchase_invoice','invoice','cash_transaction') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  source ENUM('knn','llm') NOT NULL,
  payload_json JSON NOT NULL,
  confidence DECIMAL(3,2) NOT NULL,
  model VARCHAR(64) NULL,
  provider VARCHAR(32) NULL,
  prompt_version VARCHAR(16) NULL,
  reasoning VARCHAR(500) NULL,
  status ENUM('pending','accepted','rejected','superseded','expired') NOT NULL DEFAULT 'pending',
  decided_by BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pending_entity VARCHAR(80) AS (IF(status='pending', CONCAT(entity_type,':',entity_id), NULL)) PERSISTENT,
  UNIQUE KEY uq_ais_pending (supplier_id, pending_entity),
  KEY idx_ais_supplier_status (supplier_id, status, entity_type),
  KEY idx_ais_entity (entity_type, entity_id),
  CONSTRAINT fk_ais_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ais_user FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_ais_conf CHECK (confidence >= 0 AND confidence <= 0.40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  job_type ENUM('embed_write','embed_backfill','classify_bank_tx','classify_purchase') NOT NULL,
  entity_type ENUM('bank_transaction','purchase_invoice','invoice','cash_transaction') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','running','done','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  available_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  open_key VARCHAR(100) AS (IF(status IN ('queued','running'), CONCAT(job_type,':',entity_type,':',entity_id), NULL)) PERSISTENT,
  UNIQUE KEY uq_aij_open (supplier_id, open_key),
  KEY idx_aij_supplier_status (supplier_id, status, available_at, id),
  CONSTRAINT fk_aij_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  source ENUM('knn','llm','anomaly') NOT NULL,
  metric_date DATE NOT NULL,
  suggested_count INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
  overridden_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  cost_czk DECIMAL(10,4) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_aim (supplier_id, source, metric_date),
  CONSTRAINT fk_aim_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_source_mutes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  source ENUM('knn','llm') NOT NULL,
  muted_at DATETIME NOT NULL,
  reason_json JSON NOT NULL,
  unmuted_at DATETIME NULL,
  unmuted_by BIGINT UNSIGNED NULL,
  active_key VARCHAR(20) AS (IF(unmuted_at IS NULL, CONCAT(supplier_id,':',source), NULL)) PERSISTENT,
  UNIQUE KEY uq_asm_active (active_key),
  CONSTRAINT fk_asm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_asm_user FOREIGN KEY (unmuted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
