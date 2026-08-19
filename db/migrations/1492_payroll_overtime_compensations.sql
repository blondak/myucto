-- MyÚčto.cz — MZ-06/07: náhradní volno za práci přesčas (§ 93 odst. 5).
--
-- CO ZÁKON ŘÍKÁ
--
--   § 93 odst. 5 — „Do počtu hodin nejvýše přípustné práce přesčas ve
--                  vyrovnávacím období podle odstavce 4 se nezahrnuje práce
--                  přesčas, za kterou bylo zaměstnanci poskytnuto náhradní
--                  volno."
--
-- Vynětí míří VÝHRADNĚ na vyrovnávací období podle odst. 4. Nařízený přesčas
-- podle odst. 2 (8 h týdně, 150 h ročně) se náhradním volnem nesnižuje — tam
-- žádnou takovou výjimku zákon nemá. Hlídání proto odečítá kompenzované
-- minuty jen z klouzavého okna, ne z týdenního ani ročního počítadla.
--
-- KLÍČ JE DEN PŘESČASU, NE DEN ČERPÁNÍ
--
-- Odečítá se „práce přesčas, za kterou bylo poskytnuto náhradní volno" — tedy
-- hodiny na straně přesčasu. Rozhodné je proto datum, na které přesčas
-- připadl (`overtime_date`); den, kdy si zaměstnanec volno vybral, je jen
-- doklad (`granted_on`). Kdyby se odečítalo ke dni čerpání, vypadl by přesčas
-- z okna v nesprávném týdnu a u volna vybraného až po skončení okna by
-- nevypadl vůbec.
--
-- JEDEN ŘÁDEK NA DEN
--
-- Dvojí započtení je u tohohle ustanovení typická vada: buď se hodiny odečtou
-- dvakrát (dva zápisy k témuž dni), nebo neodečtou vůbec. UNIQUE přes
-- (supplier_id, employment_id, overtime_date) první možnost vylučuje na
-- úrovni databáze; horní mez „nelze kompenzovat víc, než kolik se ten den
-- přesčasu odpracovalo" hlídá aplikace, protože rozsah přesčasu se počítá až
-- z časových intervalů v `payroll_time_entries`.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_overtime_compensations (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  overtime_date      DATE NOT NULL,
  minutes            INT UNSIGNED NOT NULL,
  granted_on         DATE NULL,
  document_reference VARCHAR(191) NULL,
  note               VARCHAR(500) NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_overtime_compensation_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_overtime_compensation_day
    (supplier_id, employment_id, overtime_date),
  KEY idx_payroll_overtime_compensation_range
    (supplier_id, employment_id, overtime_date),
  CONSTRAINT fk_payroll_overtime_compensation_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_overtime_compensation_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_overtime_compensations
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_compensation_minutes;
ALTER TABLE payroll_overtime_compensations
  ADD CONSTRAINT chk_payroll_overtime_compensation_minutes
  CHECK (minutes > 0 AND minutes <= 1440);

ALTER TABLE payroll_overtime_compensations
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_compensation_granted;
ALTER TABLE payroll_overtime_compensations
  ADD CONSTRAINT chk_payroll_overtime_compensation_granted
  CHECK (granted_on IS NULL OR granted_on >= overtime_date);

ALTER TABLE payroll_overtime_compensations
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_compensation_row_version;
ALTER TABLE payroll_overtime_compensations
  ADD CONSTRAINT chk_payroll_overtime_compensation_row_version
  CHECK (row_version > 0);
