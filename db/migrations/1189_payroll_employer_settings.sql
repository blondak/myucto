-- MyÚčto.cz — MZ-03: nastavení zaměstnavatele a mzdových účtáren.
--
-- Konfigurace je tenantový agregát s optimistickou konkurencí. Účty se ukládají
-- jako kódy, protože analytické účty si každá firma vytváří samostatně; API při
-- zápisu ověřuje jejich existenci, aktivitu a účetní typ proti osnově firmy.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_offices (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  code           VARCHAR(32) NOT NULL,
  name           VARCHAR(190) NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_office_code (supplier_id, code),
  UNIQUE KEY uq_payroll_office_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_office_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_office_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employer_settings (
  supplier_id                          INT UNSIGNED NOT NULL,
  default_office_id                    BIGINT UNSIGNED NOT NULL,
  employer_registration_number         VARCHAR(32) NULL,
  social_security_office_code          VARCHAR(16) NULL,
  health_insurance_payer_number         VARCHAR(32) NULL,
  default_health_insurer_code           VARCHAR(8) NULL,
  payroll_contact_name                 VARCHAR(190) NULL,
  payroll_contact_email                VARCHAR(190) NULL,
  payroll_contact_phone                VARCHAR(40) NULL,
  employment_gross_debit_account       VARCHAR(10) NOT NULL DEFAULT '521',
  employment_gross_credit_account      VARCHAR(10) NOT NULL DEFAULT '331',
  partner_gross_debit_account          VARCHAR(10) NOT NULL DEFAULT '522',
  partner_gross_credit_account         VARCHAR(10) NOT NULL DEFAULT '366',
  statutory_gross_debit_account        VARCHAR(10) NOT NULL DEFAULT '523',
  statutory_gross_credit_account       VARCHAR(10) NOT NULL DEFAULT '366',
  employer_insurance_debit_account     VARCHAR(10) NOT NULL DEFAULT '524',
  social_insurance_credit_account      VARCHAR(10) NOT NULL DEFAULT '336',
  health_insurance_credit_account      VARCHAR(10) NOT NULL DEFAULT '336',
  income_tax_credit_account            VARCHAR(10) NOT NULL DEFAULT '342',
  other_deductions_credit_account      VARCHAR(10) NOT NULL DEFAULT '379',
  row_version                          INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id),
  KEY idx_payroll_employer_default_office (supplier_id, default_office_id),
  CONSTRAINT fk_payroll_employer_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employer_default_office
    FOREIGN KEY (supplier_id, default_office_id)
    REFERENCES payroll_offices (supplier_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
