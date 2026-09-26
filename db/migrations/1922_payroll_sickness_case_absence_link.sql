-- MyUcto.cz - případ dávky nemocenského pojištění vzniká ze schválené absence.
--
-- ## Proč
--
-- Lhůta NEMPRI podle § 97 odst. 2 zák. č. 187/2006 Sb. běží od 15. dne
-- trvání dočasné pracovní neschopnosti bez ohledu na to, jestli si toho někdo
-- všiml. Hlídač termínů ji ale znal jen u RUČNĚ založeného případu: schválená
-- neschopnost v absencích vyrobila náhradu mzdy, ale žádný případ, takže
-- lhůta, kterou nikdo nezaložil, nebyla hlídaná vůbec.
--
-- Schválení absence druhu, ze kterého plyne dávka (neschopnost, karanténa,
-- ošetřování, dlouhodobá péče, mateřská, otcovská), proto případ založí nebo
-- naváže na existující. `absence_id` drží, ze které absence případ vznikl:
-- podle něj se pozná, že případ už existuje, a zrušení absence zruší i případ,
-- ze kterého se ještě nic nepodalo.
--
-- Navazující absence téže neschopnosti (zapsaná po měsících) se k případu
-- nepřipojuje novým řádkem, ale prodlouží jeho konec; vazba zůstává na první
-- absenci.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS absence_id BIGINT UNSIGNED NULL AFTER employment_id;

ALTER TABLE payroll_sickness_cases
  ADD INDEX IF NOT EXISTS idx_payroll_sickness_case_absence (supplier_id, absence_id);

-- MariaDB neumí IF NOT EXISTS u cizího klíče, takže se nejdřív zahodí.
ALTER TABLE payroll_sickness_cases
  DROP FOREIGN KEY IF EXISTS fk_payroll_sickness_case_absence;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT fk_payroll_sickness_case_absence
    FOREIGN KEY (supplier_id, absence_id)
    REFERENCES payroll_absences (supplier_id, id) ON DELETE RESTRICT;
