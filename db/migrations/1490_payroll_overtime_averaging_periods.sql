-- MyÚčto.cz — MZ-06/07: vyrovnávací období pro § 93 odst. 4 zákoníku práce.
--
-- PROČ VLASTNÍ TABULKA
--
-- § 93 odst. 4 zní: „Celkový rozsah práce přesčas nesmí činit v průměru více
-- než 8 hodin týdně v období, které může činit nejvýše 26 týdnů po sobě
-- jdoucích. Jen kolektivní smlouva může vymezit toto období nejvýše na
-- 52 týdnů po sobě jdoucích."
--
-- Délka vyrovnávacího období tedy NENÍ zákonná konstanta ani parametr
-- rulesetu — je to údaj konkrétního zaměstnavatele a delší než 26 týdnů smí
-- být jen tehdy, existuje-li kolektivní smlouva, která ho takto vymezuje.
-- Modul dosud četl délku z rulesetu (`overtime.averaging.max_weeks`), tedy
-- ze společného číselníku pro všechny firmy: buď měly všechny 26, nebo by
-- 52 dostaly i firmy bez kolektivní smlouvy. Obojí je špatně.
--
-- DOLOŽENOST JE SOUČÁSTÍ PODMÍNKY
--
-- Zákon delší období neváže na rozhodnutí zaměstnavatele, ale na kolektivní
-- smlouvu. Proto CHECK: se `basis = 'statutory'` je strop 26 týdnů a odkaz na
-- kolektivní smlouvu nesmí být vyplněn; se `basis = 'collective_agreement'`
-- je strop 52 týdnů a odkaz na smlouvu je POVINNÝ. Nedoložených 52 týdnů se
-- tak do databáze nedostane a hlídání limitu nemá jak tiše změknout.
--
-- OBDOBÍ, NE PŘÍZNAK
--
-- Kolektivní smlouva má vlastní dobu platnosti a po jejím skončení se
-- vyrovnávací období vrací na zákonných 26 týdnů. Nastavení je proto
-- verzované v čase stejně jako `payroll_employer_policies` a vyhodnocení se
-- ptá „co platilo k tomuhle dni?". Bez řádku platí zákonných 26 týdnů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_overtime_averaging_periods (
  id                             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                    INT UNSIGNED NOT NULL,
  valid_from                     DATE NOT NULL,
  valid_to                       DATE NULL,
  weeks                          TINYINT UNSIGNED NOT NULL DEFAULT 26,
  basis                          VARCHAR(24) NOT NULL DEFAULT 'statutory',
  collective_agreement_reference VARCHAR(255) NULL,
  note                           VARCHAR(500) NULL,
  row_version                    INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                     BIGINT UNSIGNED NULL,
  created_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_overtime_averaging_start (supplier_id, valid_from),
  UNIQUE KEY uq_payroll_overtime_averaging_supplier_id (supplier_id, id),
  KEY idx_payroll_overtime_averaging_effective
    (supplier_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_overtime_averaging_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_overtime_averaging_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_overtime_averaging_periods
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_averaging_dates;
ALTER TABLE payroll_overtime_averaging_periods
  ADD CONSTRAINT chk_payroll_overtime_averaging_dates
  CHECK (valid_to IS NULL OR valid_to >= valid_from);

ALTER TABLE payroll_overtime_averaging_periods
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_averaging_basis;
ALTER TABLE payroll_overtime_averaging_periods
  ADD CONSTRAINT chk_payroll_overtime_averaging_basis
  CHECK (
    (basis = 'statutory'
      AND weeks BETWEEN 1 AND 26
      AND collective_agreement_reference IS NULL)
    OR
    (basis = 'collective_agreement'
      AND weeks BETWEEN 1 AND 52
      AND collective_agreement_reference IS NOT NULL)
  );

ALTER TABLE payroll_overtime_averaging_periods
  DROP CONSTRAINT IF EXISTS chk_payroll_overtime_averaging_row_version;
ALTER TABLE payroll_overtime_averaging_periods
  ADD CONSTRAINT chk_payroll_overtime_averaging_row_version
  CHECK (row_version > 0);
