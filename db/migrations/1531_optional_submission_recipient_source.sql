-- Odkaz na zdroj ID datové schránky je užitečný auditní údaj, ale vlastní
-- firemní číselník nesmí blokovat. Systémové seedované záznamy jej dál uvádějí.
ALTER TABLE submission_recipients
  DROP CONSTRAINT IF EXISTS chk_submission_recipients_source;

ALTER TABLE submission_recipients
  MODIFY COLUMN source_url VARCHAR(500) NULL
    COMMENT 'Volitelný odkaz, odkud bylo ID datové schránky převzato';
