-- MyÚčto.cz — MZ-06/07: zákazy práce přesčas u chráněných skupin.
--
-- CO ZÁKON ZAKAZUJE
--
--   § 245 odst. 1 — „Zakazuje se zaměstnávat mladistvé zaměstnance prací
--                   přesčas…" Mladistvý je podle § 350 odst. 2 zaměstnanec
--                   mladší než 18 let. Zákaz je ABSOLUTNÍ: neprolomí ho ani
--                   dohoda podle § 93 odst. 3.
--   § 240 odst. 3 věta první  — „Zakazuje se zaměstnávat těhotné zaměstnankyně
--                   prací přesčas." Rovněž ABSOLUTNÍ zákaz.
--   § 240 odst. 3 věta druhá  — „Zaměstnankyním a zaměstnancům, kteří pečují
--                   o dítě mladší než 1 rok, nesmí zaměstnavatel NAŘÍDIT práci
--                   přesčas." Zákaz PODMÍNĚNÝ: dohodnutý přesčas podle
--                   § 93 odst. 3 zakázaný není.
--
-- PROČ JEN DVĚ HODNOTY
--
-- Mladistvost se neeviduje — plyne z data narození zaměstnance
-- (`payroll_employees.birth_date`) a zapisovat ji ručně by znamenalo vytvořit
-- druhý zdroj pravdy, který se rozejde s prvním. Těhotenství a péči o dítě
-- mladší 1 roku ale modul odjinud nezná: `payroll_dependants` je evidence pro
-- daňové zvýhodnění (dítě tam je i tehdy, když o ně zaměstnanec fakticky
-- nepečuje) a absence typu `ppm` / `parental` říkají, kdy zaměstnanec
-- NEPRACUJE, ne kdy je chráněn při práci. Proto vlastní evidence.
--
-- OBDOBÍ, NE PŘÍZNAK
--
-- Obě skutečnosti mají přirozený konec (porod, první narozeniny dítěte), takže
-- se evidují jako interval platnosti. Ukončená ochrana se nemaže — doklad
-- o tom, že v konkrétním týdnu zákaz platil, je důkaz pro kontrolu
-- inspektorátu práce.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_overtime_protections (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  protection         VARCHAR(24) NOT NULL,
  valid_from         DATE NOT NULL,
  valid_to           DATE NULL,
  document_reference VARCHAR(191) NULL,
  note               VARCHAR(500) NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_overtime_protection_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_overtime_protection_start
    (supplier_id, employment_id, protection, valid_from),
  KEY idx_payroll_overtime_protection_window
    (supplier_id, employment_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_overtime_protection_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_overtime_protection_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_overtime_protections
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_protection_kind;
ALTER TABLE payroll_overtime_protections
  ADD CONSTRAINT chk_payroll_overtime_protection_kind
  CHECK (protection IN ('pregnancy', 'child_under_one'));

ALTER TABLE payroll_overtime_protections
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_protection_window;
ALTER TABLE payroll_overtime_protections
  ADD CONSTRAINT chk_payroll_overtime_protection_window
  CHECK (valid_to IS NULL OR valid_to >= valid_from);

ALTER TABLE payroll_overtime_protections
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_protection_row_version;
ALTER TABLE payroll_overtime_protections
  ADD CONSTRAINT chk_payroll_overtime_protection_row_version
  CHECK (row_version > 0);
