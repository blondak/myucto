-- MyÚčto.cz: srážky ze mzdy podle § 147 odst. 1 písm. c) až e) zákoníku práce.
--
-- Zákoník práce dovoluje zaměstnavateli srazit bez dohody se zaměstnancem
-- zálohu na mzdu, na kterou nevzniklo právo (písm. c), nevyúčtovanou zálohu
-- na cestovní náhrady nebo jinou zálohu k plnění pracovních úkolů (písm. d)
-- a náhradu mzdy za dovolenou nebo podle § 192, na kterou právo nevzniklo
-- (písm. e). Evidovaly se jako dobrovolná dohoda o srážkách, takže bez dne
-- doručení dohody spadly v pořadí za všechny exekuce a nesly titul, který
-- neexistuje. Titul srážky je teď samostatný údaj; u srážky ze zákona je
-- den zahájení srážek povinný (určuje pořadí) a srážka k náhradě škody
-- (§ 147 odst. 3 ZP) ze zákona být nesmí.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
    ADD COLUMN IF NOT EXISTS legal_basis
        ENUM('agreement', 'zp_147_1_c', 'zp_147_1_d', 'zp_147_1_e')
        NOT NULL DEFAULT 'agreement' AFTER deduction_kind;

ALTER TABLE payroll_deduction_agreement_versions
    ADD COLUMN IF NOT EXISTS legal_basis
        ENUM('agreement', 'zp_147_1_c', 'zp_147_1_d', 'zp_147_1_e')
        NOT NULL DEFAULT 'agreement' AFTER deduction_kind;

ALTER TABLE payroll_deduction_agreements
    DROP CONSTRAINT IF EXISTS chk_payroll_deduction_agreement_statutory_basis;

ALTER TABLE payroll_deduction_agreements
    ADD CONSTRAINT chk_payroll_deduction_agreement_statutory_basis CHECK (
        legal_basis = 'agreement'
        OR (delivered_on IS NOT NULL AND deduction_kind <> 'damage')
    );
