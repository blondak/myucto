-- MyÚčto.cz — úplná roční daňová evidence a bezpečná finalizace DPFO.
-- Aditivní migrace k auditu private/DANOVA_EVIDENCE-AUDIT.md.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_vat_status_history (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  effective_from           DATE NOT NULL,
  is_vat_payer             TINYINT(1) NOT NULL,
  annual_deduction_percent DECIMAL(5,2) NULL COMMENT 'skutečný roční koeficient §76; NULL = nepoužije se',
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by               INT UNSIGNED NULL,
  UNIQUE KEY uq_supplier_vat_status (supplier_id, effective_from),
  KEY idx_supplier_vat_status_date (supplier_id, effective_from, id),
  CONSTRAINT fk_supplier_vat_status_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
SELECT id, '1900-01-01', is_vat_payer FROM supplier;

CREATE TABLE IF NOT EXISTS tax_profile_activities (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  year             SMALLINT UNSIGNED NOT NULL,
  name             VARCHAR(190) NOT NULL,
  nace_code        VARCHAR(6) NOT NULL,
  expense_mode     ENUM('actual','pausal') NOT NULL DEFAULT 'pausal',
  expense_rate     TINYINT UNSIGNED NOT NULL DEFAULT 60,
  income_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  expense_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  active_months    TINYINT UNSIGNED NOT NULL DEFAULT 12,
  allocation_note  VARCHAR(500) NULL,
  order_index      INT NOT NULL DEFAULT 0,
  KEY idx_tax_activity_profile (supplier_id, year, order_index, id),
  CONSTRAINT fk_tax_activity_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_tax_activity_rate CHECK (expense_rate IN (30,40,60,80)),
  CONSTRAINT chk_tax_activity_months CHECK (active_months BETWEEN 0 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_profile_children (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  year                   SMALLINT UNSIGNED NOT NULL,
  first_name             VARCHAR(36) NOT NULL,
  last_name              VARCHAR(36) NOT NULL,
  birth_number           VARCHAR(10) NULL,
  birth_date             DATE NULL,
  shared_household_proved TINYINT(1) NOT NULL DEFAULT 0,
  other_parent_not_claimed_proved TINYINT(1) NOT NULL DEFAULT 0,
  evidence_ref           VARCHAR(190) NULL,
  order_index            INT NOT NULL DEFAULT 0,
  KEY idx_tax_child_profile (supplier_id, year, order_index, id),
  CONSTRAINT fk_tax_child_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_tax_child_identity CHECK (birth_number IS NOT NULL OR birth_date IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_profile_child_months (
  child_id      BIGINT UNSIGNED NOT NULL,
  month         TINYINT UNSIGNED NOT NULL,
  child_order   TINYINT UNSIGNED NOT NULL,
  ztpp          TINYINT(1) NOT NULL DEFAULT 0,
  claimed       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (child_id, month),
  CONSTRAINT fk_tax_child_month_child FOREIGN KEY (child_id) REFERENCES tax_profile_children(id) ON DELETE CASCADE,
  CONSTRAINT chk_tax_child_month CHECK (month BETWEEN 1 AND 12),
  CONSTRAINT chk_tax_child_order CHECK (child_order BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_profile_spouse_claims (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  year                       SMALLINT UNSIGNED NOT NULL,
  first_name                 VARCHAR(36) NOT NULL,
  last_name                  VARCHAR(36) NOT NULL,
  birth_number               VARCHAR(10) NULL,
  birth_date                 DATE NULL,
  eligible_months            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ztpp                       TINYINT(1) NOT NULL DEFAULT 0,
  own_income                 DECIMAL(15,2) NOT NULL DEFAULT 0,
  income_proved              TINYINT(1) NOT NULL DEFAULT 0,
  shared_household_proved    TINYINT(1) NOT NULL DEFAULT 0,
  child_under_three_proved   TINYINT(1) NOT NULL DEFAULT 0,
  evidence_ref               VARCHAR(190) NULL,
  UNIQUE KEY uq_tax_spouse_claim (supplier_id, year),
  CONSTRAINT fk_tax_spouse_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_tax_spouse_months CHECK (eligible_months BETWEEN 0 AND 12),
  CONSTRAINT chk_tax_spouse_identity CHECK (birth_number IS NOT NULL OR birth_date IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cash_document_vat_lines
  ADD COLUMN IF NOT EXISTS vat_deduction ENUM('full','none','proportional','reduced') NOT NULL DEFAULT 'full'
    COMMENT 'rozsah nároku na odpočet pro přímý hotovostní nákup' AFTER vat_classification_code,
  ADD COLUMN IF NOT EXISTS vat_deduction_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00
    COMMENT 'poměrný nebo skutečný roční koeficient odpočtu' AFTER vat_deduction,
  ADD COLUMN IF NOT EXISTS tax_treatment ENUM('deductible','non_deductible','not_expense') NOT NULL DEFAULT 'deductible'
    COMMENT 'uznatelnost pro daň z příjmů nezávislá na DPH' AFTER vat_deduction_percent;

ALTER TABLE tax_profiles
  ADD COLUMN IF NOT EXISTS mortgage_months TINYINT UNSIGNED NOT NULL DEFAULT 12 AFTER mortgage_pre_2021,
  ADD COLUMN IF NOT EXISTS dip_contrib DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER life_insurance,
  ADD COLUMN IF NOT EXISTS long_term_care DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER dip_contrib,
  ADD COLUMN IF NOT EXISTS disability_12_months TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER long_term_care,
  ADD COLUMN IF NOT EXISTS disability_3_months TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER disability_12_months,
  ADD COLUMN IF NOT EXISTS ztpp_months TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER disability_3_months;

CREATE TABLE IF NOT EXISTS tax_evidence_closings (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  year               SMALLINT UNSIGNED NOT NULL,
  status             ENUM('draft','final') NOT NULL DEFAULT 'draft',
  checklist          JSON NOT NULL DEFAULT ('{}'),
  opening_balances   JSON NOT NULL DEFAULT ('{}'),
  closing_balances   JSON NOT NULL DEFAULT ('{}') COMMENT '§7b: majetek, zásoby, pohledávky, dluhy, rezervy',
  unsupported_cases  JSON NOT NULL DEFAULT ('[]'),
  source_snapshot    JSON NULL,
  source_hash        CHAR(64) NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  finalized_at       DATETIME NULL,
  finalized_by       INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tax_evidence_closing (supplier_id, year),
  KEY idx_tax_evidence_closing_status (supplier_id, status, year),
  CONSTRAINT fk_tax_evidence_closing_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_evidence_non_cash_adjustments (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  closing_id     BIGINT UNSIGNED NOT NULL,
  adjustment_on  DATE NOT NULL,
  kind           ENUM('setoff','barter','in_kind_income','debt_forgiveness','private_use','shortage','damage','inventory','receivable','payable','section23_other') NOT NULL,
  direction      ENUM('increase','decrease','neutral') NOT NULL,
  amount         DECIMAL(15,2) NOT NULL,
  description    VARCHAR(500) NOT NULL,
  evidence_ref   VARCHAR(190) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED NULL,
  KEY idx_te_adjustment_closing (closing_id, adjustment_on, id),
  KEY idx_te_adjustment_supplier (supplier_id, adjustment_on),
  CONSTRAINT fk_te_adjustment_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_te_adjustment_closing FOREIGN KEY (closing_id) REFERENCES tax_evidence_closings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_osvc_month_statuses (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  year                  SMALLINT UNSIGNED NOT NULL,
  month                 TINYINT UNSIGNED NOT NULL,
  activity_status       ENUM('inactive','main','secondary') NOT NULL DEFAULT 'inactive',
  social_participates   TINYINT(1) NOT NULL DEFAULT 0,
  health_minimum_applies TINYINT(1) NOT NULL DEFAULT 0,
  state_insured         TINYINT(1) NOT NULL DEFAULT 0,
  employed              TINYINT(1) NOT NULL DEFAULT 0,
  new_osvc              TINYINT(1) NOT NULL DEFAULT 0,
  assessment_base       DECIMAL(15,2) NULL,
  note                  VARCHAR(500) NULL,
  UNIQUE KEY uq_osvc_month (supplier_id, year, month),
  CONSTRAINT fk_osvc_month_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_osvc_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_tax_finalization_overrides (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tax_return_id  INT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL,
  check_key      VARCHAR(100) NOT NULL,
  reason         VARCHAR(1000) NOT NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tax_override_return (tax_return_id, id),
  CONSTRAINT fk_tax_override_return FOREIGN KEY (tax_return_id) REFERENCES income_tax_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_tax_override_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_tax_return_snapshots (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tax_return_id    INT UNSIGNED NOT NULL,
  supplier_id      INT UNSIGNED NOT NULL,
  revision_no      INT UNSIGNED NOT NULL,
  snapshot_json    JSON NOT NULL,
  source_manifest  JSON NOT NULL,
  source_sha256    CHAR(64) NOT NULL,
  xml_content      LONGTEXT NOT NULL,
  xml_sha256       CHAR(64) NOT NULL,
  business_status  ENUM('passed','failed') NOT NULL,
  business_errors  JSON NOT NULL DEFAULT ('[]'),
  finalized_by     INT UNSIGNED NOT NULL,
  finalized_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_income_tax_snapshot_revision (tax_return_id, revision_no),
  KEY idx_income_tax_snapshot_supplier (supplier_id, tax_return_id, id),
  CONSTRAINT fk_income_tax_snapshot_return FOREIGN KEY (tax_return_id) REFERENCES income_tax_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_income_tax_snapshot_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE income_tax_returns
  ADD COLUMN IF NOT EXISTS final_snapshot_id BIGINT UNSIGNED NULL AFTER computed,
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL AFTER final_snapshot_id,
  ADD COLUMN IF NOT EXISTS finalized_by INT UNSIGNED NULL AFTER finalized_at;

CREATE INDEX IF NOT EXISTS idx_itr_final_snapshot ON income_tax_returns (final_snapshot_id);

CREATE TABLE IF NOT EXISTS de_movement_classification_history (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  source_type         ENUM('bank','cash') NOT NULL,
  source_id           BIGINT UNSIGNED NOT NULL,
  previous_tax_bucket VARCHAR(30) NULL,
  new_tax_bucket      VARCHAR(30) NULL,
  changed_by          INT UNSIGNED NULL,
  changed_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_de_class_history (supplier_id, source_type, source_id, id),
  CONSTRAINT fk_de_class_history_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
