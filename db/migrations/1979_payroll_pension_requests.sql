-- MyÚčto.cz — výzvy a žádosti v důchodovém pojištění.
--
-- Zaměstnavatel plní část povinností k důchodovému pojištění jen na podnět
-- zvenčí a každá z nich má vlastní lhůtu, která běží od doručení:
--
-- * evidenční list na výzvu ČSSZ/ÚSSZ (§ 38a odst. 2 a 3, čl. V bod 8
--   zák. č. 360/2025 Sb., do roku 2025 § 39 odst. 3) a list při úmrtí,
-- * sdělení nebo oprava údajů měsíčním hlášením na výzvu (§ 38a odst. 1),
-- * potvrzení o době důchodového pojištění v roce (§ 42),
-- * potvrzení o náhradách za ztrátu na výdělku (§ 37 odst. 2),
-- * potvrzení podle znění do 31. 12. 2025 (čl. V body 2 až 4 zák. č. 360/2025 Sb.).
--
-- Lhůtu počítá aplikace (PayrollPensionRequestDeadlinePolicy) z doručení,
-- vyřízení se zapisuje ručně nebo vazbou na připravený evidenční list.
--
-- IDEMPOTENCE: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_pension_requests (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,
  employment_id        BIGINT UNSIGNED NULL,
  request_kind         ENUM('eldp','jmh_correction','insurance_period_confirmation',
                            'compensation_confirmation','legacy_confirmation') NOT NULL,
  legacy_kind          ENUM('excluded_periods','deep_mining','risky_work','rescuer') NULL,
  requester            ENUM('cssz','ossz','employee','former_employee','survivor') NOT NULL,
  requester_reference  VARCHAR(190) NULL,
  received_on          DATE NOT NULL,
  period_year          SMALLINT UNSIGNED NULL,
  period_from          DATE NULL,
  period_to            DATE NULL,
  death_on             DATE NULL,
  stated_due_on        DATE NULL,
  due_on               DATE NOT NULL,
  deadline_rule        VARCHAR(128) NOT NULL,
  eldp_statement_id    BIGINT UNSIGNED NULL,
  eldp_environment     ENUM('production','test') NULL,
  completed_on         DATE NULL,
  completion_kind      ENUM('eldp_statement','document','manual') NULL,
  completion_reference VARCHAR(190) NULL,
  copy_delivered_on    DATE NULL,
  note                 VARCHAR(500) NULL,
  row_version          INT UNSIGNED NOT NULL DEFAULT 1,
  created_by           BIGINT UNSIGNED NULL,
  completed_by         BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_payroll_pension_request_open (supplier_id, completed_on, due_on),
  KEY idx_payroll_pension_request_employee (supplier_id, employee_id, received_on),
  KEY idx_payroll_pension_request_statement (supplier_id, eldp_statement_id),
  CONSTRAINT fk_payroll_pension_request_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pension_request_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_pension_request_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_pension_request_completed_by
    FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_pension_request_due CHECK (due_on >= received_on),
  CONSTRAINT chk_payroll_pension_request_period CHECK (
    period_from IS NULL OR period_to IS NULL OR period_to >= period_from
  ),
  CONSTRAINT chk_payroll_pension_request_legacy CHECK (
    (request_kind = 'legacy_confirmation') = (legacy_kind IS NOT NULL)
  ),
  CONSTRAINT chk_payroll_pension_request_statement CHECK (
    (eldp_statement_id IS NULL) = (eldp_environment IS NULL)
  ),
  CONSTRAINT chk_payroll_pension_request_completion CHECK (
    (completed_on IS NULL AND completion_kind IS NULL)
    OR (completed_on IS NOT NULL AND completion_kind IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
