-- MyÚčto.cz — krátkodobé jednorázové granty pro artefakty mzdových podání.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_submission_artifact_download_grants (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  artifact_id           BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  token_hash            BINARY(32) NOT NULL,
  created_at            DATETIME(6) NOT NULL,
  expires_at            DATETIME(6) NOT NULL,
  used_at               DATETIME(6) NULL,

  UNIQUE KEY uq_payroll_submission_artifact_grant_token (token_hash),
  KEY idx_payroll_submission_artifact_grant_scope (
    supplier_id, environment, submission_id, artifact_id, expires_at
  ),
  KEY idx_payroll_submission_artifact_grant_user (
    supplier_id, user_id, expires_at
  ),
  CONSTRAINT fk_payroll_submission_artifact_grant_artifact
    FOREIGN KEY (supplier_id, environment, submission_id, artifact_id)
    REFERENCES payroll_submission_artifacts (
      supplier_id, environment, submission_id, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_artifact_grant_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_artifact_grant_ttl CHECK (
    expires_at >= DATE_ADD(created_at, INTERVAL 30 SECOND)
    AND expires_at <= DATE_ADD(created_at, INTERVAL 900 SECOND)
  ),
  CONSTRAINT chk_payroll_submission_artifact_grant_used CHECK (
    used_at IS NULL OR used_at >= created_at
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
