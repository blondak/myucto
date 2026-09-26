-- MyÚčto.cz - adresná výjimka pro přebalení mzdových šifrovaných hodnot
-- na nový master klíč (rotace `app.secret_encryption_key`).
--
-- PROČ
--
-- Mzdové snapshoty, platební pokyny, artefakty podání a další citlivé hodnoty
-- jsou šifrované `SecretEncryption::encryptFor()` (formát `enc:v2:<keyId>:…`)
-- a leží v tabulkách, které jsou záměrně append-only: UPDATE triggery
-- odmítají jakoukoli změnu. To je správně pro obsah dokladu, jenže zároveň to
-- znemožňovalo rotaci klíče - ciphertext pod starým klíčem nešel přepsat,
-- takže starý klíč musel zůstat v `secret_encryption_previous_keys` navždy
-- a kompromitovaný klíč nešlo nikdy vyřadit.
--
-- CO SE MĚNÍ
--
-- Každý dotčený trigger pustí UPDATE jen tehdy, když si o něj spojení řekne
-- pro KONKRÉTNÍ řádek přes session proměnnou `@payroll_key_rewrap_<tabulka>`
-- (vzor z migrace 1758) a zároveň se změnil VÝHRADNĚ ciphertext, který
-- zůstává ve formátu `enc:v2:`. Všechny ostatní sloupce musí zůstat beze
-- změny. Proměnnou nastavuje jen `PayrollKeyRotationService` těsně před
-- UPDATE a hned ji uklidí; aplikace přebalený ciphertext před zápisem ověří
-- zpětným dešifrováním. Cokoli jiného - omylem spuštěný UPDATE, chybná
-- migrace, ruční dotaz - narazí na tutéž hlášku jako dosud.
--
-- U bankovních účtů zaměstnanců (`payroll_person_accounts`) se při výměně
-- ciphertextu mazalo ověření účtu; přebalení obsah nemění, takže ověření
-- zůstává. U účtů institucí (`payroll_institution_accounts`) přebalení
-- nevyžaduje novou verzi řádku ze stejného důvodu.
--
-- Těla triggerů jsou vygenerovaná ze struktury tabulek k migraci 1900;
-- sloupec přidaný později do výčtu „beze změny" nespadne a při přebalení
-- by se změnit mohl - aplikace ale mění jen ciphertext.
--
-- IDEMPOTENCE
--
-- `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER`, jen DDL.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_registration_a1_profile_immutable_update//
CREATE TRIGGER trg_payroll_registration_a1_profile_immutable_update
BEFORE UPDATE ON payroll_registration_a1_profiles
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_registration_a1_profiles <=> OLD.id
    AND NEW.profile_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.effective_on <=> OLD.effective_on
    AND NEW.status <=> OLD.status
    AND NEW.profile_hash <=> OLD.profile_hash
    AND NEW.reference_hash <=> OLD.reference_hash
    AND NEW.row_version <=> OLD.row_version
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll REGZEC A1 profile versions are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_annual_revision_immutable_update//
CREATE TRIGGER trg_payroll_annual_revision_immutable_update
BEFORE UPDATE ON payroll_annual_document_revisions
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_annual_document_revisions <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.tax_year <=> OLD.tax_year
    AND NEW.purpose <=> OLD.purpose
    AND NEW.revision_no <=> OLD.revision_no
    AND NEW.previous_revision_id <=> OLD.previous_revision_id
    AND NEW.snapshot_hash <=> OLD.snapshot_hash
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_hash <=> OLD.source_manifest_hash
    AND NEW.approved_by <=> OLD.approved_by
    AND NEW.approved_at <=> OLD.approved_at
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Annual payroll document revisions are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employment_exit_revision_immutable_update//
CREATE TRIGGER trg_payroll_employment_exit_revision_immutable_update
BEFORE UPDATE ON payroll_employment_exit_revisions
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_employment_exit_revisions <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.purpose <=> OLD.purpose
    AND NEW.employment_end_date <=> OLD.employment_end_date
    AND NEW.revision_no <=> OLD.revision_no
    AND NEW.previous_revision_id <=> OLD.previous_revision_id
    AND NEW.snapshot_hash <=> OLD.snapshot_hash
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_hash <=> OLD.source_manifest_hash
    AND NEW.approved_by <=> OLD.approved_by
    AND NEW.approved_at <=> OLD.approved_at
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employment exit document revisions are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_item_immutable_update//
CREATE TRIGGER trg_payroll_payment_item_immutable_update
BEFORE UPDATE ON payroll_payment_items
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_payment_items <=> OLD.id
    AND NEW.instruction_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.batch_id <=> OLD.batch_id
    AND NEW.item_reference <=> OLD.item_reference
    AND NEW.recipient_reference <=> OLD.recipient_reference
    AND NEW.amount_minor <=> OLD.amount_minor
    AND NEW.instruction_hash <=> OLD.instruction_hash
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment items are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_batch_immutable_update//
CREATE TRIGGER trg_payroll_payment_batch_immutable_update
BEFORE UPDATE ON payroll_payment_batches
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_payment_batches <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.batch_reference <=> OLD.batch_reference
    AND NEW.channel <=> OLD.channel
    AND NEW.export_format <=> OLD.export_format
    AND NEW.direction <=> OLD.direction
    AND NEW.planned_payment_date <=> OLD.planned_payment_date
    AND NEW.currency_code <=> OLD.currency_code
    AND NEW.payer_reference <=> OLD.payer_reference
    AND NEW.declared_total_minor <=> OLD.declared_total_minor
    AND NEW.declared_item_count <=> OLD.declared_item_count
    AND NEW.snapshot_hash <=> OLD.snapshot_hash
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment batches are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_submission_artifact_update//
CREATE TRIGGER trg_payroll_submission_artifact_update
BEFORE UPDATE ON payroll_submission_artifacts
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_submission_artifacts <=> OLD.id
    AND NEW.content_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.submission_id <=> OLD.submission_id
    AND NEW.part_id <=> OLD.part_id
    AND NEW.artifact_kind <=> OLD.artifact_kind
    AND NEW.direction <=> OLD.direction
    AND NEW.mime_type <=> OLD.mime_type
    AND NEW.byte_size <=> OLD.byte_size
    AND NEW.artifact_sha256 <=> OLD.artifact_sha256
    AND NEW.xsd_version <=> OLD.xsd_version
    AND NEW.catalog_version <=> OLD.catalog_version
    AND NEW.channel <=> OLD.channel
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll submission artifacts are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_form_outcome_update//
CREATE TRIGGER trg_payroll_jmhz_form_outcome_update
BEFORE UPDATE ON payroll_jmhz_protocol_form_outcomes
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_jmhz_protocol_form_outcomes <=> OLD.id
    AND NEW.errors_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.submission_id <=> OLD.submission_id
    AND NEW.receipt_id <=> OLD.receipt_id
    AND NEW.artifact_id <=> OLD.artifact_id
    AND NEW.part_id <=> OLD.part_id
    AND NEW.form_guid <=> OLD.form_guid
    AND NEW.protocol_status_code <=> OLD.protocol_status_code
    AND NEW.protocol_status_name <=> OLD.protocol_status_name
    AND NEW.remote_status <=> OLD.remote_status
    AND NEW.external_person_reference <=> OLD.external_person_reference
    AND NEW.external_employment_reference <=> OLD.external_employment_reference
    AND NEW.error_count <=> OLD.error_count
    AND NEW.errors_sha256 <=> OLD.errors_sha256
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'jmhz protocol form outcomes are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_xmlzam_request_no_update//
CREATE TRIGGER trg_xmlzam_request_no_update
BEFORE UPDATE ON payroll_enforcement_xmlzam_requests
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_enforcement_xmlzam_requests <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.inbox_message_id <=> OLD.inbox_message_id
    AND NEW.source_document_id <=> OLD.source_document_id
    AND NEW.source_document_file_id <=> OLD.source_document_file_id
    AND NEW.request_identifier <=> OLD.request_identifier
    AND NEW.issued_on <=> OLD.issued_on
    AND NEW.executor_box_id <=> OLD.executor_box_id
    AND NEW.source_xml_sha256 <=> OLD.source_xml_sha256
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.imported_by <=> OLD.imported_by
    AND NEW.imported_at <=> OLD.imported_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM request snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_xmlzam_response_no_update//
CREATE TRIGGER trg_xmlzam_response_no_update
BEFORE UPDATE ON payroll_enforcement_xmlzam_responses
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_enforcement_xmlzam_responses <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.xml_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.request_id <=> OLD.request_id
    AND NEW.case_id <=> OLD.case_id
    AND NEW.response_identifier <=> OLD.response_identifier
    AND NEW.includes_wages <=> OLD.includes_wages
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_sha256 <=> OLD.source_manifest_sha256
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.xml_sha256 <=> OLD.xml_sha256
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.approved_by <=> OLD.approved_by
    AND NEW.approved_at <=> OLD.approved_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM response snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_regzel_snapshot_immutable_update//
CREATE TRIGGER trg_payroll_regzel_snapshot_immutable_update
BEFORE UPDATE ON payroll_regzel_payload_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_regzel_payload_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.office_id <=> OLD.office_id
    AND NEW.document_type <=> OLD.document_type
    AND NEW.interaction_code <=> OLD.interaction_code
    AND NEW.mapping_version <=> OLD.mapping_version
    AND NEW.xsd_version <=> OLD.xsd_version
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_snapshot_hash <=> OLD.source_snapshot_hash
    AND NEW.xml_sha256 <=> OLD.xml_sha256
    AND NEW.xml_byte_size <=> OLD.xml_byte_size
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll REGZEL payload snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_eldp_no_update//
CREATE TRIGGER trg_payroll_jmhz_eldp_no_update
BEFORE UPDATE ON payroll_jmhz_eldp_evidence_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_jmhz_eldp_evidence_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.run_id <=> OLD.run_id
    AND NEW.source_revision_id <=> OLD.source_revision_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.period_start <=> OLD.period_start
    AND NEW.schema_reference <=> OLD.schema_reference
    AND NEW.section_count <=> OLD.section_count
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_sha256 <=> OLD.source_manifest_sha256
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_jmhz_eldp_evidence_snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_no_update//
CREATE TRIGGER trg_payroll_jmhz_ordinary_no_update
BEFORE UPDATE ON payroll_jmhz_ordinary_evidence_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_jmhz_ordinary_evidence_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.run_id <=> OLD.run_id
    AND NEW.source_revision_id <=> OLD.source_revision_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.period_start <=> OLD.period_start
    AND NEW.schema_reference <=> OLD.schema_reference
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_sha256 <=> OLD.source_manifest_sha256
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.confirmed_by <=> OLD.confirmed_by
    AND NEW.confirmed_at <=> OLD.confirmed_at
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_jmhz_ordinary_evidence_snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_no_update//
CREATE TRIGGER trg_payroll_jmhz_preparation_no_update
BEFORE UPDATE ON payroll_jmhz_preparation_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_jmhz_preparation_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.run_id <=> OLD.run_id
    AND NEW.source_revision_id <=> OLD.source_revision_id
    AND NEW.period_start <=> OLD.period_start
    AND NEW.scenario_key <=> OLD.scenario_key
    AND NEW.scenario_set_json <=> OLD.scenario_set_json
    AND NEW.builder_version <=> OLD.builder_version
    AND NEW.readiness_status <=> OLD.readiness_status
    AND NEW.issue_count <=> OLD.issue_count
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_sha256 <=> OLD.source_manifest_sha256
    AND NEW.readiness_json <=> OLD.readiness_json
    AND NEW.readiness_sha256 <=> OLD.readiness_sha256
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_jmhz_preparation_snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_registration_identity_snapshot_no_update//
CREATE TRIGGER trg_payroll_registration_identity_snapshot_no_update
BEFORE UPDATE ON payroll_registration_identity_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_registration_identity_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.submission_id <=> OLD.submission_id
    AND NEW.source_revision_id <=> OLD.source_revision_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.agenda_code <=> OLD.agenda_code
    AND NEW.effective_on <=> OLD.effective_on
    AND NEW.schema_reference <=> OLD.schema_reference
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_hash <=> OLD.source_manifest_hash
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_registration_identity_snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_registration_event_immutable_update//
CREATE TRIGGER trg_payroll_registration_event_immutable_update
BEFORE UPDATE ON payroll_registration_event_snapshots
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_registration_event_snapshots <=> OLD.id
    AND NEW.snapshot_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.environment <=> OLD.environment
    AND NEW.interaction_code <=> OLD.interaction_code
    AND NEW.action_code <=> OLD.action_code
    AND NEW.effective_on <=> OLD.effective_on
    AND NEW.source_kind <=> OLD.source_kind
    AND NEW.source_reference <=> OLD.source_reference
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_hash <=> OLD.source_manifest_hash
    AND NEW.snapshot_fingerprint <=> OLD.snapshot_fingerprint
    AND NEW.approved_by <=> OLD.approved_by
    AND NEW.approved_at <=> OLD.approved_at
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll registration event snapshots are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_statement_no_update//
CREATE TRIGGER trg_payroll_eldp_statement_no_update
BEFORE UPDATE ON payroll_eldp_statements
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_key_rewrap_eldp_statements <=> OLD.id
    AND NEW.statement_ciphertext LIKE 'enc:v2:%'
    AND NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.environment <=> OLD.environment
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.employment_id <=> OLD.employment_id
    AND NEW.statement_year <=> OLD.statement_year
    AND NEW.statement_kind <=> OLD.statement_kind
    AND NEW.period_from <=> OLD.period_from
    AND NEW.period_to <=> OLD.period_to
    AND NEW.schema_reference <=> OLD.schema_reference
    AND NEW.builder_version <=> OLD.builder_version
    AND NEW.section_count <=> OLD.section_count
    AND NEW.insurance_days <=> OLD.insurance_days
    AND NEW.excluded_days_total <=> OLD.excluded_days_total
    AND NEW.deducted_days_total <=> OLD.deducted_days_total
    AND NEW.deadline_ruleset_id <=> OLD.deadline_ruleset_id
    AND NEW.deadline_ruleset_hash <=> OLD.deadline_ruleset_hash
    AND NEW.earliest_submission_on <=> OLD.earliest_submission_on
    AND NEW.due_on <=> OLD.due_on
    AND NEW.xsd_package_key <=> OLD.xsd_package_key
    AND NEW.xsd_bundle_sha256 <=> OLD.xsd_bundle_sha256
    AND NEW.xml_sha256 <=> OLD.xml_sha256
    AND NEW.source_manifest_json <=> OLD.source_manifest_json
    AND NEW.source_manifest_sha256 <=> OLD.source_manifest_sha256
    AND NEW.statement_fingerprint <=> OLD.statement_fingerprint
    AND NEW.request_fingerprint <=> OLD.request_fingerprint
    AND NEW.idempotency_key_hash <=> OLD.idempotency_key_hash
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_eldp_statements are immutable';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_person_account_verify_update//
CREATE TRIGGER trg_payroll_person_account_verify_update
BEFORE UPDATE ON payroll_person_accounts
FOR EACH ROW
BEGIN
  IF NOT (
    NEW.bank_account_ciphertext <=> OLD.bank_account_ciphertext
    AND NEW.bank_account_hash <=> OLD.bank_account_hash
    AND NEW.effective_from <=> OLD.effective_from
    AND NEW.effective_to <=> OLD.effective_to
    AND NEW.is_active <=> OLD.is_active
  ) AND NOT (
      @payroll_key_rewrap_person_accounts <=> OLD.id
      AND NEW.bank_account_ciphertext LIKE 'enc:v2:%'
      AND NEW.id <=> OLD.id
      AND NEW.supplier_id <=> OLD.supplier_id
      AND NEW.employee_id <=> OLD.employee_id
      AND NEW.label <=> OLD.label
      AND NEW.bank_account_hash <=> OLD.bank_account_hash
      AND NEW.bank_account_masked <=> OLD.bank_account_masked
      AND NEW.allocation_basis_points <=> OLD.allocation_basis_points
      AND NEW.effective_from <=> OLD.effective_from
      AND NEW.effective_to <=> OLD.effective_to
      AND NEW.is_active <=> OLD.is_active
      AND NEW.row_version <=> OLD.row_version
      AND NEW.verification_source <=> OLD.verification_source
      AND NEW.verified_on <=> OLD.verified_on
      AND NEW.verified_by <=> OLD.verified_by
      AND NEW.created_at <=> OLD.created_at
  ) THEN
    SET NEW.verification_source = NULL;
    SET NEW.verified_on = NULL;
    SET NEW.verified_by = NULL;
  END IF;

  IF NOT (
    (
      NEW.verification_source IS NULL
      AND NEW.verified_on IS NULL
      AND NEW.verified_by IS NULL
    )
    OR
    (
      NEW.verification_source IS NOT NULL
      AND NEW.verified_on IS NOT NULL
      AND NEW.verified_by IS NOT NULL
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll person account verification is incomplete';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_institution_account_payment_update//
CREATE TRIGGER trg_payroll_institution_account_payment_update
BEFORE UPDATE ON payroll_institution_accounts
FOR EACH ROW
BEGIN
  IF NOT (
    NEW.bank_account_ciphertext <=> OLD.bank_account_ciphertext
    AND NEW.bank_account_hash <=> OLD.bank_account_hash
    AND NEW.currency_code <=> OLD.currency_code
    AND NEW.variable_symbol <=> OLD.variable_symbol
    AND NEW.specific_symbol <=> OLD.specific_symbol
    AND NEW.constant_symbol <=> OLD.constant_symbol
    AND NEW.valid_from <=> OLD.valid_from
    AND NEW.valid_to <=> OLD.valid_to
    AND NEW.source_kind <=> OLD.source_kind
    AND NEW.source_reference <=> OLD.source_reference
    AND NEW.verified_on <=> OLD.verified_on
    AND NEW.verified_by <=> OLD.verified_by
  ) AND OLD.bank_account_ciphertext <> 'pending:v1'
    AND NEW.row_version <= OLD.row_version
    AND NOT (
      @payroll_key_rewrap_institution_accounts <=> OLD.id
      AND NEW.bank_account_ciphertext LIKE 'enc:v2:%'
      AND NEW.id <=> OLD.id
      AND NEW.supplier_id <=> OLD.supplier_id
      AND NEW.institution_id <=> OLD.institution_id
      AND NEW.institution_name <=> OLD.institution_name
      AND NEW.bank_account_hash <=> OLD.bank_account_hash
      AND NEW.bank_account_masked <=> OLD.bank_account_masked
      AND NEW.currency_code <=> OLD.currency_code
      AND NEW.variable_symbol <=> OLD.variable_symbol
      AND NEW.specific_symbol <=> OLD.specific_symbol
      AND NEW.constant_symbol <=> OLD.constant_symbol
      AND NEW.valid_from <=> OLD.valid_from
      AND NEW.valid_to <=> OLD.valid_to
      AND NEW.source_kind <=> OLD.source_kind
      AND NEW.source_reference <=> OLD.source_reference
      AND NEW.verified_on <=> OLD.verified_on
      AND NEW.verified_by <=> OLD.verified_by
      AND NEW.created_by <=> OLD.created_by
      AND NEW.updated_by <=> OLD.updated_by
      AND NEW.row_version <=> OLD.row_version
      AND NEW.created_at <=> OLD.created_at
    ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target change requires a new row version';
  END IF;
  IF NEW.verified_by IS NULL OR NEW.verified_on IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target verification is incomplete';
  END IF;
END//
DELIMITER ;
