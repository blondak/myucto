-- MyÚčto.cz — E4: jednotná policy automatického účtování

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS auto_posting_policy (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  operation_type VARCHAR(40) NOT NULL,
  level          ENUM('off','suggest','auto') NOT NULL DEFAULT 'suggest',
  updated_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app (supplier_id, operation_type),
  CONSTRAINT fk_app_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_app_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS automation_level ENUM('off','suggest','assisted','full')
    NOT NULL DEFAULT 'suggest' COMMENT 'UI preset; hromadně nastaví policy řádky (master §3.4)',
  ADD COLUMN IF NOT EXISTS automation_daily_limit_czk DECIMAL(14,2) NULL
    COMMENT 'denní součet auto zápisů; nad limit degradace na suggest',
  ADD COLUMN IF NOT EXISTS automation_digest_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS automation_digest_hour TINYINT UNSIGNED NOT NULL DEFAULT 7
    COMMENT 'hodina odeslání ranního digestu (staví na ní E6/F-E)';
