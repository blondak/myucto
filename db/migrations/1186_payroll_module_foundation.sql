-- 1186: samostatný bounded context úplných mezd (MZ-00, základ MZ-01).
--
-- Aktivace je per firma a nezávislá na účetním režimu. Vlastnictví období brání
-- tomu, aby se stejný měsíc zpracoval legacy Mzdovou rekapitulací i novým modulem.

CREATE TABLE IF NOT EXISTS payroll_module_state (
  supplier_id       INT UNSIGNED NOT NULL,
  status            ENUM('disabled','setup','active','suspended') NOT NULL DEFAULT 'disabled',
  start_period      DATE NULL COMMENT 'první den prvního období nového modulu',
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  activated_by      BIGINT UNSIGNED NULL,
  activated_at      DATETIME NULL,
  suspended_at      DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  CONSTRAINT fk_payroll_module_state_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_module_state_activated_by
    FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_module_state_start
    CHECK (
      (status = 'disabled' AND start_period IS NULL)
      OR (status IN ('setup','active','suspended') AND start_period IS NOT NULL)
    ),
  CONSTRAINT chk_payroll_module_state_period_day
    CHECK (start_period IS NULL OR DAY(start_period) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_period_ownership (
  supplier_id       INT UNSIGNED NOT NULL,
  period_start      DATE NOT NULL,
  processor         ENUM('legacy','payroll') NOT NULL,
  source_type       VARCHAR(64) NOT NULL,
  source_id         BIGINT UNSIGNED NULL,
  claimed_by        BIGINT UNSIGNED NULL,
  claimed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, period_start),
  KEY idx_payroll_period_owner_source (supplier_id, processor, source_type, source_id),
  CONSTRAINT fk_payroll_period_owner_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_period_owner_user
    FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_period_owner_day CHECK (DAY(period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nové permission klíče dostanou pouze systémové staff role. Vlastní role jsou
-- fail-closed a správce jim práva přidělí vědomě v editoru rolí.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, permissions.permission_key, permissions.access_level
FROM roles r
JOIN (
  SELECT 'accountant' system_key, 'payroll' permission_key, 2 access_level
  UNION ALL SELECT 'accountant', 'payroll.person.write', 2
  UNION ALL SELECT 'accountant', 'payroll.employment.write', 2
  UNION ALL SELECT 'accountant', 'payroll.time.write', 2
  UNION ALL SELECT 'accountant', 'payroll.inputs.write', 2
  UNION ALL SELECT 'accountant', 'payroll.calculate', 2
  UNION ALL SELECT 'accountant', 'payroll.review', 2
  UNION ALL SELECT 'accountant', 'payroll.post', 2
  UNION ALL SELECT 'accountant', 'payroll.payments', 2
  UNION ALL SELECT 'accountant', 'payroll.submissions', 2
  UNION ALL SELECT 'accountant', 'payroll.reports', 2
  UNION ALL SELECT 'accountant', 'payroll.documents', 2
) permissions ON permissions.system_key = r.system_key;
