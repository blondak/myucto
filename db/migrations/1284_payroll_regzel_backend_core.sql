-- MyÚčto.cz — MZ-20: specializovaný REGZELDOPL payload snapshot.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_regzel_employer_profiles (
  supplier_id              INT UNSIGNED NOT NULL,
  social_enterprise        TINYINT(1) NOT NULL,
  employment_agency        TINYINT(1) NOT NULL,
  protected_labor_market   TINYINT(1) NOT NULL,
  evidence_confirmed_by    BIGINT UNSIGNED NOT NULL,
  evidence_confirmed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id),
  CONSTRAINT fk_payroll_regzel_profile_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_regzel_profile_confirmer
    FOREIGN KEY (evidence_confirmed_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_regzel_profile_flags CHECK (
    social_enterprise IN (0, 1)
    AND employment_agency IN (0, 1)
    AND protected_labor_market IN (0, 1)
  ),
  CONSTRAINT chk_payroll_regzel_profile_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_regzel_payload_snapshots (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  environment              ENUM('production','test') NOT NULL,
  office_id                BIGINT UNSIGNED NOT NULL,
  document_type            ENUM('REGZELDOPL25') NOT NULL,
  interaction_code         ENUM('supplemental_information') NOT NULL,
  mapping_version          VARCHAR(64) NOT NULL,
  xsd_version              VARCHAR(32) NOT NULL,
  source_manifest_json     JSON NOT NULL,
  snapshot_ciphertext      MEDIUMTEXT NOT NULL,
  source_snapshot_hash     CHAR(64) CHARACTER SET ascii
                             COLLATE ascii_bin NOT NULL,
  xml_sha256               CHAR(64) CHARACTER SET ascii
                             COLLATE ascii_bin NOT NULL,
  xml_byte_size            INT UNSIGNED NOT NULL,
  request_fingerprint      CHAR(64) CHARACTER SET ascii
                             COLLATE ascii_bin NOT NULL,
  idempotency_key_hash     CHAR(64) CHARACTER SET ascii
                             COLLATE ascii_bin NOT NULL,
  created_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_regzel_snapshot_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_regzel_snapshot_environment_id (
    supplier_id, environment, id
  ),
  UNIQUE KEY uq_payroll_regzel_snapshot_idempotency (
    supplier_id, environment, idempotency_key_hash
  ),
  KEY idx_payroll_regzel_snapshot_source (
    supplier_id, environment, document_type, source_snapshot_hash
  ),
  KEY idx_payroll_regzel_snapshot_office (
    supplier_id, office_id, created_at
  ),
  CONSTRAINT fk_payroll_regzel_snapshot_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_regzel_snapshot_office
    FOREIGN KEY (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_regzel_snapshot_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_regzel_snapshot_hashes CHECK (
    source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND xml_sha256 REGEXP '^[0-9a-f]{64}$'
    AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
    AND idempotency_key_hash REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_regzel_snapshot_size CHECK (xml_byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_regzel_snapshot_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_regzel_snapshot_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_regzel_snapshot_immutable_update
BEFORE UPDATE ON payroll_regzel_payload_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll REGZEL payload snapshots are immutable';
END//

CREATE TRIGGER trg_payroll_regzel_snapshot_immutable_delete
BEFORE DELETE ON payroll_regzel_payload_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll REGZEL payload snapshots are immutable';
END//

DELIMITER ;
