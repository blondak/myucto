-- MyÚčto.cz — textové odkazy na podklady jsou ve mzdách volitelné.
-- Ověřený stav zůstává výslovným rozhodnutím uživatele; DB dál hlídá věcné
-- údaje, intervaly a nepřípustné kombinace, ale nevyžaduje ruční dohledávku.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_health_coverage_history
  DROP CONSTRAINT IF EXISTS chk_pp_health_coverage_country;
ALTER TABLE payroll_person_health_coverage_history
  ADD CONSTRAINT chk_pp_health_coverage_country
    CHECK (
      (jurisdiction = 'foreign_regime_verified'
       AND foreign_country_code REGEXP '^[A-Z]{2}$')
      OR
      (jurisdiction <> 'foreign_regime_verified'
       AND foreign_country_code IS NULL
       AND jurisdiction_evidence_reference IS NULL)
    );

ALTER TABLE payroll_person_health_coverage_history
  DROP CONSTRAINT IF EXISTS chk_pp_health_coverage_insurer;
ALTER TABLE payroll_person_health_coverage_history
  ADD CONSTRAINT chk_pp_health_coverage_insurer
    CHECK (
      (insurer_status = 'verified'
       AND insurer_code REGEXP '^[0-9]{3}$')
      OR
      (insurer_status = 'unverified'
       AND (insurer_code IS NULL OR insurer_code REGEXP '^[0-9]{3}$')
       AND insurer_evidence_reference IS NULL)
      OR
      (insurer_status = 'not_applicable'
       AND insurer_code IS NULL
       AND insurer_evidence_reference IS NULL)
    );

ALTER TABLE payroll_person_health_minimum_reductions
  DROP CONSTRAINT IF EXISTS chk_pp_health_reduction_evidence;
ALTER TABLE payroll_person_health_minimum_reductions
  ADD CONSTRAINT chk_pp_health_reduction_evidence
    CHECK (reason <> 'unverified' OR evidence_reference IS NULL);

ALTER TABLE payroll_person_health_month_evidence
  DROP CONSTRAINT IF EXISTS chk_pp_health_month_responsibility;
ALTER TABLE payroll_person_health_month_evidence
  ADD CONSTRAINT chk_pp_health_month_responsibility
    CHECK (
      top_up_responsibility = 'employer_obstacle_verified'
      OR top_up_responsibility_evidence_reference IS NULL
    );

ALTER TABLE payroll_person_health_month_evidence
  DROP CONSTRAINT IF EXISTS chk_pp_health_month_selected_employer;
ALTER TABLE payroll_person_health_month_evidence
  ADD CONSTRAINT chk_pp_health_month_selected_employer
    CHECK (
      selected_top_up_employer_reference IS NOT NULL
      OR selected_top_up_employer_evidence_reference IS NULL
    );

ALTER TABLE payroll_person_health_other_employer_bases
  MODIFY COLUMN evidence_reference VARCHAR(500) NULL
    COMMENT 'Volitelná dohledávka podkladu k základu u jiného zaměstnavatele.';

ALTER TABLE payroll_person_tax_declarations
  DROP CONSTRAINT IF EXISTS chk_pp_tax_declaration_evidence;
ALTER TABLE payroll_person_tax_declarations
  ADD CONSTRAINT chk_pp_tax_declaration_evidence
    CHECK (status <> 'unverified' OR evidence_reference IS NULL);

ALTER TABLE payroll_person_tax_residences
  DROP CONSTRAINT IF EXISTS chk_pp_tax_residence_evidence;
ALTER TABLE payroll_person_tax_residences
  ADD CONSTRAINT chk_pp_tax_residence_evidence
    CHECK (
      (residence = 'czech-resident' AND country_code = 'CZ')
      OR
      (residence = 'non-resident'
       AND country_code REGEXP '^[A-Z]{2}$'
       AND country_code <> 'CZ')
      OR
      (residence = 'unverified'
       AND country_code IS NULL
       AND evidence_reference IS NULL)
    );

ALTER TABLE payroll_person_tax_credit_claims
  DROP CONSTRAINT IF EXISTS chk_pp_tax_credit_evidence;
ALTER TABLE payroll_person_tax_credit_claims
  ADD CONSTRAINT chk_pp_tax_credit_evidence
    CHECK (evidence_status = 'verified' OR evidence_reference IS NULL);

ALTER TABLE payroll_person_tax_child_claims
  DROP CONSTRAINT IF EXISTS chk_pp_tax_child_evidence;
ALTER TABLE payroll_person_tax_child_claims
  ADD CONSTRAINT chk_pp_tax_child_evidence
    CHECK (evidence_status = 'verified' OR evidence_reference IS NULL);

ALTER TABLE payroll_person_social_jurisdictions
  DROP CONSTRAINT IF EXISTS chk_pp_social_jurisdiction_country;
ALTER TABLE payroll_person_social_jurisdictions
  ADD CONSTRAINT chk_pp_social_jurisdiction_country
    CHECK (
      (jurisdiction = 'foreign_regime_verified'
       AND foreign_country_code REGEXP '^[A-Z]{2}$')
      OR
      (jurisdiction <> 'foreign_regime_verified'
       AND foreign_country_code IS NULL
       AND jurisdiction_evidence_reference IS NULL)
    );

ALTER TABLE payroll_person_social_jurisdictions
  DROP CONSTRAINT IF EXISTS chk_pp_social_jurisdiction_a1;
ALTER TABLE payroll_person_social_jurisdictions
  ADD CONSTRAINT chk_pp_social_jurisdiction_a1
    CHECK (
      (a1_status = 'verified' AND a1_valid_until IS NOT NULL)
      OR
      (a1_status <> 'verified'
       AND a1_certificate_reference IS NULL
       AND a1_valid_until IS NULL)
    );

ALTER TABLE payroll_person_social_discount_claims
  DROP CONSTRAINT IF EXISTS chk_pp_social_discount_evidence;
ALTER TABLE payroll_person_social_discount_claims
  ADD CONSTRAINT chk_pp_social_discount_evidence
    CHECK (status = 'verified' OR evidence_reference IS NULL);

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_evidence;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_evidence
    CHECK (
      (request_status = 'requested' AND requested_on IS NOT NULL)
      OR (request_status <> 'requested' AND requested_on IS NULL)
    );

ALTER TABLE payroll_annual_settlement_certificates
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_certificate_evidence;
