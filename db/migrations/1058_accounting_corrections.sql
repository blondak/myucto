-- MyÚčto.cz — Epic AUTOMATIZACE F-F (Učení): jednotný event log korekcí.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_corrections (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  event_type         ENUM(
                       'approve_override',
                       'reject',
                       'unpost',
                       'manual_post',
                       'rule_promotion_suggested',
                       'rule_promoted',
                       'rule_demoted',
                       'rule_disabled',
                       'rule_mined',
                       'classify_override'
                     ) NOT NULL,
  entity_type        ENUM('bank_transaction','invoice','purchase_invoice',
                          'cash_document','journal_entry','bank_posting_rule') NOT NULL,
  entity_id          BIGINT UNSIGNED NOT NULL,
  suggestion_id      BIGINT UNSIGNED NULL,
  suggestion_source  ENUM('rule','learned','knn','llm','detector','manual',
                          'payment_match','schedule') NULL,
  rule_id            BIGINT UNSIGNED NULL,
  suggested_debit    VARCHAR(10) NULL,
  suggested_credit   VARCHAR(10) NULL,
  final_debit        VARCHAR(10) NULL,
  final_credit       VARCHAR(10) NULL,
  amount             DECIMAL(14,2) NULL,
  model              VARCHAR(64) NULL,
  prompt_version     VARCHAR(32) NULL,
  reason             VARCHAR(255) NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_ac_supplier_entity (supplier_id, entity_type, entity_id, created_at),
  KEY idx_ac_supplier_rule   (supplier_id, rule_id, created_at),
  KEY idx_ac_supplier_event  (supplier_id, event_type, created_at),
  CONSTRAINT fk_ac_supplier   FOREIGN KEY (supplier_id)   REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ac_suggestion FOREIGN KEY (suggestion_id) REFERENCES bank_posting_suggestions(id) ON DELETE SET NULL,
  CONSTRAINT fk_ac_rule       FOREIGN KEY (rule_id)       REFERENCES bank_posting_rules(id) ON DELETE SET NULL,
  CONSTRAINT fk_ac_user       FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
