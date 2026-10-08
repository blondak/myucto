-- MyUcto.cz - Zákonná evidence osoby: důchodové údaje.
--
-- PROČ
--
-- Kód D v ELDP (výdělečná činnost po dovršení důchodového věku nebo poživatel
-- předčasného starobního důchodu) a odečtené doby závisí na dni dosažení
-- důchodového věku a na pobíraném důchodu. Roční evidenční list je bral jen
-- z jednorázového potvrzení účetní, měsíční hlášení JMHZ je nemělo odkud vzít,
-- takže kód D v něm nikdy nevznikl. Obě cesty teď čtou jednu trvalou evidenci
-- osoby (PayrollPensionStatus).
--
-- payroll_person_pension_age: den dosažení důchodového věku. Jediný záznam na
-- osobu (effective_from = den dosažení, effective_to vždy NULL), řada se tak
-- vede stejným mechanismem jako ostatní sekce zákonné evidence.
--
-- payroll_person_pensions: pobíraný důchod od-do. Druh podle číselníku CIS
-- Druh důchodu (REGZEC, atribut 10113: 1 starobní, 2 invalidní 3. stupně,
-- 8 invalidní 1. nebo 2. stupně, A/B/C cizí). Předčasný starobní důchod je
-- druh 1 s příznakem early_retirement (atribut 10115), snížený důchodový věk
-- je atribut reducedAge. Překryv se hlídá v rámci druhu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_person_pension_age (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  basis              ENUM('statutory_table','cssz_information','employee_declaration') NOT NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_reference VARCHAR(500) NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_pension_age_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_pension_age_employee (supplier_id, employee_id),
  CONSTRAINT fk_pp_pension_age_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_pension_age_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_pension_age_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_pension_age_open
    CHECK (effective_to IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_pensions (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  employee_id            BIGINT UNSIGNED NOT NULL,
  pension_type_code      VARCHAR(3) NOT NULL,
  early_retirement       TINYINT(1) NOT NULL DEFAULT 0,
  reduced_retirement_age TINYINT(1) NOT NULL DEFAULT 0,
  effective_from         DATE NOT NULL,
  effective_to           DATE NULL,
  evidence_reference     VARCHAR(500) NULL,
  evidence_note          VARCHAR(500) NULL,
  created_by             BIGINT UNSIGNED NULL,
  updated_by             BIGINT UNSIGNED NULL,
  row_version            INT UNSIGNED NOT NULL DEFAULT 1,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_pensions_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_pensions_start (supplier_id, employee_id, pension_type_code, effective_from),
  KEY idx_pp_pensions_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_pensions_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_pensions_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_pensions_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_pensions_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_pensions_type
    CHECK (pension_type_code IN ('1','2','8','A','B','C')),
  CONSTRAINT chk_pp_pensions_flags
    CHECK (early_retirement IN (0,1) AND reduced_retirement_age IN (0,1)),
  CONSTRAINT chk_pp_pensions_early_old_age
    CHECK (early_retirement = 0 OR pension_type_code = '1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
