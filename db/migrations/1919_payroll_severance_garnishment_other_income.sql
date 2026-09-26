-- MyÚčto.cz: srážky z odstupného podle § 299 odst. 4 o. s. ř.
--
-- Z odstupného se srážky počítají zvlášť z každého násobku průměrného
-- výdělku; počet násobků nese množství vstupu odstupného. Věta druhá téhož
-- odstavce: nastoupí-li povinný v době poskytování odstupného do práce nebo
-- mu vznikne jiný příjem podle § 299 odst. 1 až 3, považují se násobky za
-- měsíční příjem vedle toho druhého. Den vzniku jiného příjmu a potvrzení,
-- že nezabavitelnou částku za tyto měsíce započítává druhý plátce, zadává
-- účetní v kartě Skončení vztahu.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terminations
    ADD COLUMN IF NOT EXISTS other_income_from DATE NULL
        AFTER working_time_account_applies,
    ADD COLUMN IF NOT EXISTS other_payer_applies_protected_amount TINYINT(1) NOT NULL DEFAULT 0
        AFTER other_income_from;

ALTER TABLE payroll_employment_terminations
    DROP CONSTRAINT IF EXISTS chk_payroll_employment_termination_other_payer;

ALTER TABLE payroll_employment_terminations
    ADD CONSTRAINT chk_payroll_employment_termination_other_payer CHECK (
        other_payer_applies_protected_amount IN (0, 1)
        AND (other_payer_applies_protected_amount = 0 OR other_income_from IS NOT NULL)
    );
