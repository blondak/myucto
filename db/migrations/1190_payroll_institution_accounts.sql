-- MyÚčto.cz — MZ-03-W03: účinná historie účtů mzdových institucí.
--
-- Identita instituce je oddělena od verzovaných platebních údajů. Bankovní účet
-- se ukládá pouze jako kontextově vázaný ciphertext, tenantový keyed hash a maska.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_institutions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  institution_type  VARCHAR(32) NOT NULL,
  institution_code  VARCHAR(32) NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_institution_identity
    (supplier_id, institution_type, institution_code),
  UNIQUE KEY uq_payroll_institution_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_institution_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_institution_type CHECK (
    institution_type IN (
      'social_security',
      'tax_office',
      'health_insurer',
      'statutory_insurance',
      'other_recipient'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_institution_accounts (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  institution_id           BIGINT UNSIGNED NOT NULL,
  institution_name         VARCHAR(190) NOT NULL,
  bank_account_ciphertext  VARCHAR(1024) NOT NULL,
  bank_account_hash        BINARY(32) NOT NULL,
  bank_account_masked      VARCHAR(191) NOT NULL,
  currency_code            CHAR(3) NOT NULL DEFAULT 'CZK',
  variable_symbol          VARCHAR(10) NULL,
  specific_symbol          VARCHAR(10) NULL,
  constant_symbol          CHAR(4) NULL,
  valid_from               DATE NOT NULL,
  valid_to                 DATE NULL,
  source_kind              VARCHAR(32) NOT NULL,
  source_reference         VARCHAR(500) NOT NULL,
  verified_on              DATE NOT NULL,
  verified_by              BIGINT UNSIGNED NULL,
  created_by               BIGINT UNSIGNED NULL,
  updated_by               BIGINT UNSIGNED NULL,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_institution_account_supplier_id (supplier_id, id),
  KEY idx_payroll_institution_account_effective
    (supplier_id, institution_id, currency_code, valid_from, valid_to),
  KEY idx_payroll_institution_account_hash (supplier_id, bank_account_hash),
  KEY idx_payroll_institution_account_verified_by (verified_by),
  KEY idx_payroll_institution_account_created_by (created_by),
  KEY idx_payroll_institution_account_updated_by (updated_by),
  CONSTRAINT fk_payroll_institution_account_institution
    FOREIGN KEY (supplier_id, institution_id)
    REFERENCES payroll_institutions (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_institution_account_verified_by
    FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_institution_account_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_institution_account_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_institution_account_dates CHECK (
    valid_to IS NULL OR valid_to >= valid_from
  ),
  CONSTRAINT chk_payroll_institution_account_source CHECK (
    source_kind IN (
      'official_registry',
      'official_document',
      'institution_notice',
      'user_verified',
      'imported'
    )
  ),
  CONSTRAINT chk_payroll_institution_account_currency CHECK (
    currency_code REGEXP '^[A-Z]{3}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
