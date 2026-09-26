-- MyÚčto.cz: jednorázová náhrada při skončení pracovního poměru (§ 271ca ZP).
--
-- Náhradu 12× průměrného výdělku vyplácí buď zaměstnavatel, nebo přímo
-- pojišťovna ze zákonného pojištění odpovědnosti zaměstnavatele. Aplikace to
-- sama neví, proto se na plátce a den výplaty ptá tlačítko „Založit náhradu"
-- v kartě Skončení vztahu. Vyplácí-li zaměstnavatel, vznikne mzdový vstup
-- (v den skončení do posledního měsíce, později jako odložený příjem);
-- vyplácí-li pojišťovna, mzdový vstup nevzniká a zůstane jen záznam pro
-- odhlášku A2.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terminations
    ADD COLUMN IF NOT EXISTS work_injury_compensation_payer ENUM('employer', 'insurer') NULL
        AFTER other_payer_applies_protected_amount,
    ADD COLUMN IF NOT EXISTS work_injury_compensation_paid_on DATE NULL
        AFTER work_injury_compensation_payer;

ALTER TABLE payroll_employment_terminations
    DROP CONSTRAINT IF EXISTS chk_payroll_employment_termination_work_injury;

ALTER TABLE payroll_employment_terminations
    ADD CONSTRAINT chk_payroll_employment_termination_work_injury CHECK (
        (work_injury_compensation_payer IS NULL) = (work_injury_compensation_paid_on IS NULL)
    );
