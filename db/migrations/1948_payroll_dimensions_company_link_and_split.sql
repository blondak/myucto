-- MyÚčto.cz — mzdy na firemní dimenze (PLAN-DIMENZE-UCTOVANI F3).
--
-- 1) `payroll_dimensions.dimension_value_id` váže mzdové středisko (zakázku,
--    činnost) na hodnotu firemního číselníku Firma → Dimenze. Jedno středisko
--    tak platí pro faktury i mzdy a mzdový předpis dostane do deníku
--    `journal_entry_line_dimensions` stejně jako ostatní doklady. Hodnota musí
--    patřit firmě nebo její skupině; hlídá to aplikace (FK hlídá jen existenci).
--    Bez vazby se účtuje přesně jako dosud.
--
-- 2) `payroll_employment_dimensions.share_percent` dovolí rozdělit pracovní
--    vztah mezi víc hodnot téhož typu (např. 70 / 30). Existující přiřazení
--    mají 100 %, tedy totéž co dosud. Překrývat se smí jen přiřazení s podílem
--    pod 100 % a s různou dimenzí; součet 100 % v každém dni hlídá aplikace
--    (PayrollEmploymentDimensionRepository), protože rozpad vzniká víc řádky
--    v jedné transakci a trigger by viděl rozpracovaný stav.
--
-- 3) `payroll_posting_allocations.dimensions` drží firemní dimenze cílové
--    alokace (JSON typ → hodnota). Opravná dávka počítá rozdíl proti uloženým
--    alokacím, takže dimenze musí být uložené u nich, ne jen v deníku. Report
--    nákladů na zaměstnance po dimenzi čte právě tenhle sloupec. NULL = alokace
--    bez firemní dimenze, tedy stav všech dosavadních dávek.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, CHECK přes DROP CONSTRAINT IF EXISTS
-- + ADD (MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK), FK přes
-- DROP FOREIGN KEY IF EXISTS + ADD, triggery přes DROP TRIGGER IF EXISTS.

SET NAMES utf8mb4;

ALTER TABLE payroll_dimensions
  ADD COLUMN IF NOT EXISTS dimension_value_id BIGINT UNSIGNED NULL
    COMMENT 'Hodnota firemní dimenze (dimension_values), na kterou se mzdy účtují'
    AFTER default_account_code;

ALTER TABLE payroll_dimensions
  ADD KEY IF NOT EXISTS idx_payroll_dimension_value (dimension_value_id);

ALTER TABLE payroll_dimensions
  DROP FOREIGN KEY IF EXISTS fk_payroll_dimension_value;
ALTER TABLE payroll_dimensions
  ADD CONSTRAINT fk_payroll_dimension_value
    FOREIGN KEY (dimension_value_id) REFERENCES dimension_values (id) ON DELETE SET NULL;

ALTER TABLE payroll_employment_dimensions
  ADD COLUMN IF NOT EXISTS share_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00
    COMMENT 'Podíl vztahu na hodnotě dimenze; součet podílů jednoho typu = 100'
    AFTER dimension_id;

ALTER TABLE payroll_employment_dimensions
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_dimension_share;
ALTER TABLE payroll_employment_dimensions
  ADD CONSTRAINT chk_payroll_employment_dimension_share
    CHECK (share_percent > 0 AND share_percent <= 100);

ALTER TABLE payroll_posting_allocations
  ADD COLUMN IF NOT EXISTS dimensions VARCHAR(500) NULL
    COMMENT 'Firemní dimenze alokace: JSON {dimension_type_id: dimension_value_id}';

ALTER TABLE payroll_posting_allocations
  DROP CONSTRAINT IF EXISTS chk_payroll_posting_allocation_dimensions;
ALTER TABLE payroll_posting_allocations
  ADD CONSTRAINT chk_payroll_posting_allocation_dimensions
    CHECK (dimensions IS NULL OR JSON_VALID(dimensions));

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_insert//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_insert
BEFORE INSERT ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
       AND (NEW.share_percent >= 100
            OR ed.share_percent >= 100
            OR ed.dimension_id = NEW.dimension_id)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_update//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_update
BEFORE UPDATE ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension row version must increase';
  END IF;

  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND ed.id <> NEW.id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
       AND (NEW.share_percent >= 100
            OR ed.share_percent >= 100
            OR ed.dimension_id = NEW.dimension_id)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DELIMITER ;
