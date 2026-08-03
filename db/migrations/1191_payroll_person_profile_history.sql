-- MyÚčto.cz — MZ-04: historická osobní karta, kontakty a výplatní metoda.
--
-- `payroll_employees` zůstává jedinou identitou osoby. Všechny nové tabulky jsou
-- tenantová rozšíření přes composite FK; kontakty, identifikátory a bankovní účty
-- obsahují pouze kontextový ciphertext, keyed hash a bezpečnou masku.

SET NAMES utf8mb4;

ALTER TABLE payroll_employee_profiles
  ADD COLUMN IF NOT EXISTS payout_method
    ENUM('cash','bank','mixed') NOT NULL DEFAULT 'cash'
    AFTER profile_status,
  ADD COLUMN IF NOT EXISTS cash_allocation_basis_points
    SMALLINT UNSIGNED NOT NULL DEFAULT 10000
    AFTER payout_method,
  ADD COLUMN IF NOT EXISTS payout_effective_on
    DATE NULL
    AFTER cash_allocation_basis_points,
  ADD COLUMN IF NOT EXISTS secure_delivery_channel
    ENUM('portal','paper') NOT NULL DEFAULT 'portal'
    AFTER payout_effective_on,
  ADD COLUMN IF NOT EXISTS row_version
    INT UNSIGNED NOT NULL DEFAULT 1
    AFTER secure_delivery_channel,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_profile_cash_allocation
    CHECK (cash_allocation_basis_points <= 10000);

CREATE TABLE IF NOT EXISTS payroll_person_identity_history (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL,
  full_name       VARCHAR(191) NOT NULL,
  birth_surname   VARCHAR(191) NULL,
  effective_from  DATE NOT NULL,
  effective_to    DATE NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_identity_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_identity_start (supplier_id, employee_id, effective_from),
  KEY idx_payroll_identity_effective (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_payroll_identity_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_identity_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_addresses (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL,
  address_type    ENUM('residence','mailing') NOT NULL,
  street_line     VARCHAR(255) NOT NULL,
  city            VARCHAR(128) NOT NULL,
  postal_code     VARCHAR(24) NOT NULL,
  country_code    CHAR(2) NOT NULL DEFAULT 'CZ',
  effective_from  DATE NOT NULL,
  effective_to    DATE NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_address_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_address_start (supplier_id, employee_id, address_type, effective_from),
  KEY idx_payroll_address_effective
    (supplier_id, employee_id, address_type, effective_from, effective_to),
  CONSTRAINT fk_payroll_address_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_address_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_contacts (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  employee_id    BIGINT UNSIGNED NOT NULL,
  contact_type   ENUM('email','phone') NOT NULL,
  contact_value_ciphertext VARCHAR(512) NOT NULL,
  contact_value_hash       BINARY(32) NOT NULL,
  contact_value_masked     VARCHAR(191) NOT NULL,
  is_primary     TINYINT(1) NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_contact_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_contact_value
    (supplier_id, employee_id, contact_type, contact_value_hash),
  KEY idx_payroll_contact_employee (supplier_id, employee_id, is_active),
  CONSTRAINT fk_payroll_contact_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_contact_primary CHECK (is_primary IN (0, 1)),
  CONSTRAINT chk_payroll_contact_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_identifiers (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  identifier_type    ENUM(
                       'birth_number',
                       'ecp',
                       'vcp',
                       'foreign_tax_identifier'
                     ) NOT NULL,
  value_ciphertext   VARCHAR(512) NOT NULL,
  value_hash         BINARY(32) NOT NULL,
  value_masked       VARCHAR(191) NOT NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_identifier_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_identifier_type (supplier_id, employee_id, identifier_type),
  UNIQUE KEY uq_payroll_identifier_tenant_hash
    (supplier_id, identifier_type, value_hash),
  CONSTRAINT fk_payroll_identifier_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_accounts (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  label                      VARCHAR(128) NOT NULL,
  bank_account_ciphertext    VARCHAR(512) NOT NULL,
  bank_account_hash          BINARY(32) NOT NULL,
  bank_account_masked        VARCHAR(191) NOT NULL,
  allocation_basis_points    SMALLINT UNSIGNED NOT NULL DEFAULT 10000,
  effective_from             DATE NOT NULL,
  effective_to               DATE NULL,
  is_active                  TINYINT(1) NOT NULL DEFAULT 1,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_account_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_account_hash (supplier_id, employee_id, bank_account_hash),
  KEY idx_payroll_account_effective
    (supplier_id, employee_id, is_active, effective_from, effective_to),
  CONSTRAINT fk_payroll_account_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_account_allocation CHECK (allocation_basis_points <= 10000),
  CONSTRAINT chk_payroll_account_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_payroll_account_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
