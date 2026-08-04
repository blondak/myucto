-- MyÚčto.cz — účast na pojištění je jiná klasifikace než vyměřovací základ.

SET NAMES utf8mb4;

ALTER TABLE payroll_component_definitions
  ADD COLUMN IF NOT EXISTS social_participation_treatment
    ENUM('included','excluded','manual_review') NULL
    AFTER tax_treatment,
  ADD COLUMN IF NOT EXISTS health_participation_treatment
    ENUM('included','excluded','manual_review') NULL
    AFTER social_treatment;

UPDATE payroll_component_definitions
   SET social_participation_treatment = social_treatment
 WHERE social_participation_treatment IS NULL;

UPDATE payroll_component_definitions
   SET health_participation_treatment = health_treatment
 WHERE health_participation_treatment IS NULL;

ALTER TABLE payroll_component_definitions
  MODIFY COLUMN social_participation_treatment
    ENUM('included','excluded','manual_review') NOT NULL
    AFTER tax_treatment,
  MODIFY COLUMN health_participation_treatment
    ENUM('included','excluded','manual_review') NOT NULL
    AFTER social_treatment;
