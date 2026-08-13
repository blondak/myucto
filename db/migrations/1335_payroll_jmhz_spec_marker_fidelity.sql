-- MyÚčto.cz — MZ-22-W1a: bezeztrátová reprezentace značek a vazeb datového slovníku JMHZ.
-- Idempotentní hardening pro vývojové databáze, které získaly první podobu registru 1334.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_dictionary_attributes
  ADD COLUMN IF NOT EXISTS data_type_refinement VARCHAR(255) NULL AFTER data_type,
  ADD COLUMN IF NOT EXISTS regzec_xsd_mapping VARCHAR(1000) NULL AFTER cardinality,
  ADD COLUMN IF NOT EXISTS employer_registration_marker VARCHAR(255) NULL AFTER codebook_key,
  ADD COLUMN IF NOT EXISTS employee_registration_marker VARCHAR(255) NULL AFTER employer_registration_marker,
  MODIFY COLUMN codebook_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN monthly_marker VARCHAR(64) NULL;

-- Neplatnou vazbu nikdy netlumíme na NULL. Přidání FK buď potvrdí konzistenci,
-- nebo migrace hlasitě selže a vyžádá opravu zdrojového mapování.
ALTER TABLE payroll_jmhz_dictionary_attributes
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_jmhz_attribute_codebook
    (package_id, codebook_key)
    REFERENCES payroll_jmhz_codebooks(package_id, codebook_key)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
