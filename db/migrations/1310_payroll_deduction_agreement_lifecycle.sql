-- MyÚčto.cz — lifecycle a verzování dohod o srážkách (MZ-13-W03).
--
-- Dohoda o srážce je od začátku anchorem append-only ledgeru
-- (`payroll_deduction_ledger`), takže se její řádek NESMÍ rozpadnout na víc
-- identit. Historii proto drží samostatná append-only tabulka verzí: každá
-- změna nebo přechod stavu zapíše novou účinnou verzi, aktuální řádek dohody
-- je jen projekcí poslední verze. Data použitá ve schválené mzdě zůstávají
-- v neměnném snapshotu revize, takže je změna dohody nemůže přepsat.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
  ADD COLUMN IF NOT EXISTS basis_points INT UNSIGNED NULL AFTER requested_minor;

ALTER TABLE payroll_deduction_agreements
  ADD COLUMN IF NOT EXISTS basis_amount_minor BIGINT UNSIGNED NULL AFTER basis_points;

ALTER TABLE payroll_deduction_agreements
  ADD COLUMN IF NOT EXISTS version_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER row_version;

ALTER TABLE payroll_deduction_agreements
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_deduction_agreement_percentage
    CHECK (
      basis_points IS NULL
      OR (basis_points BETWEEN 0 AND 10000 AND basis_amount_minor IS NOT NULL)
    );

CREATE TABLE IF NOT EXISTS payroll_deduction_agreement_versions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  agreement_id          BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  version_no            INT UNSIGNED NOT NULL,
  change_kind           ENUM(
    'created','updated','activated','paused','resumed','ended','cancelled'
  ) NOT NULL,
  title                 VARCHAR(190) NOT NULL,
  deduction_kind        ENUM(
    'advance','meal','contribution','damage','other'
  ) NOT NULL,
  status                ENUM('draft','active','paused','ended','cancelled') NOT NULL,
  priority_no           INT UNSIGNED NOT NULL,
  requested_minor       BIGINT UNSIGNED NOT NULL,
  basis_points          INT UNSIGNED NULL,
  basis_amount_minor    BIGINT UNSIGNED NULL,
  total_limit_minor     BIGINT UNSIGNED NULL,
  withheld_total_minor  BIGINT UNSIGNED NOT NULL,
  valid_from            DATE NOT NULL,
  valid_to              DATE NULL,
  recipient_reference   VARCHAR(190) NULL,
  note                  VARCHAR(500) NULL,
  effective_from        DATE NOT NULL,
  reason                VARCHAR(500) NULL,
  actor_user_id         BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_deduction_agreement_version
    (supplier_id, agreement_id, version_no),
  UNIQUE KEY uq_payroll_deduction_agreement_version_supplier_id
    (supplier_id, id),
  KEY idx_payroll_deduction_agreement_version_employee
    (supplier_id, employee_id, agreement_id, version_no),
  CONSTRAINT fk_payroll_deduction_agreement_version_owner
    FOREIGN KEY (supplier_id, agreement_id, employee_id)
    REFERENCES payroll_deduction_agreements
      (supplier_id, id, employee_id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_agreement_version_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_deduction_agreement_version_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_deduction_agreement_version_percentage
    CHECK (
      basis_points IS NULL
      OR (basis_points BETWEEN 0 AND 10000 AND basis_amount_minor IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
