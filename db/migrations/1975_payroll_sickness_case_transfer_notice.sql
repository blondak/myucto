-- MyUcto.cz - případ dávky: druhé oznámení NEMPRI ke dni převedení na jinou práci.
--
-- Všeobecné zásady NEMPRI 2025 (položka Převedení na jinou práci): byla-li
-- pojištěnka převedena na jinou práci z důvodu těhotenství, je třeba ÚSSZ
-- předložit současně také další Oznámení zaměstnavatele o žádosti zaměstnance
-- o dávku s rozhodným obdobím vztahujícím se ke dni převedení (§ 19 odst. 6
-- zák. č. 187/2006 Sb.). Případ dosud nesl jen jedno oznámení s výhodnějším
-- obdobím a druhé se podávalo přes ePortál ČSSZ.
--
-- Druhé oznámení je samostatné podání téhož formuláře NEMPRI25 se stejnou
-- lhůtou, ale vlastním stavem, dnem doručení, důvodem odmítnutí a vazbou na
-- podání (stejně jako NEMPRI a HZUPN v migraci 1967). `NULL` ve stavu znamená,
-- že se druhé oznámení nepodává: převedení chybí, nebo leží ve stejném měsíci
-- jako rozhodný den, takže by obě oznámení nesla totéž rozhodné období.
--
-- Převod dat: otevřené případy (NEMPRI ještě nevyřízené) s převedením
-- z důvodu těhotenství, mateřství nebo kojení v dřívějším měsíci, než je
-- rozhodný den, dostanou druhé oznámení ve stavu `pending`. U vyřízených
-- případů se stav nezakládá: podání odešlo podle dosavadních pravidel.
-- Opakovaný běh nic nezmění (přepisuje se jen `NULL`).

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS nempri_transfer_status
    ENUM('pending','accepted','rejected','predecessor') NULL DEFAULT NULL
    AFTER hzupn_rejection_reason,
  ADD COLUMN IF NOT EXISTS nempri_transfer_accepted_on DATE NULL
    AFTER nempri_transfer_status,
  ADD COLUMN IF NOT EXISTS nempri_transfer_rejection_reason VARCHAR(190) NULL
    AFTER nempri_transfer_accepted_on,
  ADD COLUMN IF NOT EXISTS nempri_transfer_submission_id BIGINT UNSIGNED NULL
    AFTER hzupn_submission_id;

ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT fk_payroll_sickness_case_nempri_transfer_submission
    FOREIGN KEY IF NOT EXISTS (supplier_id, environment, nempri_transfer_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se omezení nejdřív zahodí.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_nempri_transfer_receipt;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_nempri_transfer_receipt
    CHECK (
      (nempri_transfer_status IS NULL AND nempri_transfer_accepted_on IS NULL
        AND nempri_transfer_rejection_reason IS NULL)
      OR (nempri_transfer_status = 'accepted' AND nempri_transfer_accepted_on IS NOT NULL
        AND nempri_transfer_rejection_reason IS NULL)
      OR (nempri_transfer_status = 'rejected' AND nempri_transfer_accepted_on IS NULL
        AND nempri_transfer_rejection_reason IS NOT NULL)
      OR (nempri_transfer_status = 'predecessor' AND nempri_transfer_rejection_reason IS NULL)
      OR (nempri_transfer_status = 'pending' AND nempri_transfer_accepted_on IS NULL
        AND nempri_transfer_rejection_reason IS NULL)
    );

UPDATE payroll_sickness_cases sickness
  LEFT JOIN payroll_employments employment
    ON employment.supplier_id = sickness.supplier_id
   AND employment.id = sickness.employment_id
   SET sickness.nempri_transfer_status = 'pending'
 WHERE sickness.nempri_transfer_status IS NULL
   AND sickness.cancelled = 0
   AND sickness.nempri_status IN ('pending','rejected')
   AND sickness.benefit_kind <> 'OPP'
   AND (sickness.benefit_kind NOT IN ('OSE','DLO') OR sickness.action_start = 1)
   AND sickness.transferred_other_work = 1
   AND sickness.transfer_reason IN ('pregnancy','maternity','breastfeeding')
   AND sickness.transferred_on IS NOT NULL
   AND DATE_FORMAT(sickness.transferred_on, '%Y-%m') < DATE_FORMAT(
         CASE
           WHEN employment.end_date IS NOT NULL AND sickness.incapacity_from > employment.end_date
             THEN DATE_ADD(employment.end_date, INTERVAL 1 DAY)
           ELSE sickness.incapacity_from
         END,
         '%Y-%m'
       );

-- Společný stav případu, jen odvozený (viz 1967), nově i z druhého oznámení:
-- případ je vyřízený, až když je vyřízené i ono, a odmítnuté druhé oznámení
-- vrací případ mezi odmítnuté.
ALTER TABLE payroll_sickness_cases
  DROP COLUMN IF EXISTS status;
ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS status
    ENUM('draft','prepared','submitted','accepted','rejected','cancelled')
    GENERATED ALWAYS AS (
      CASE
        WHEN cancelled = 1 THEN 'cancelled'
        WHEN nempri_status = 'rejected' OR hzupn_status = 'rejected'
             OR nempri_transfer_status = 'rejected'
          THEN 'rejected'
        WHEN nempri_status IN ('accepted','predecessor')
             AND (benefit_kind <> 'NEM' OR hzupn_status IN ('accepted','predecessor'))
             AND (nempri_transfer_status IS NULL
                  OR nempri_transfer_status IN ('accepted','predecessor'))
          THEN 'accepted'
        WHEN nempri_status IN ('accepted','predecessor')
             OR hzupn_status IN ('accepted','predecessor')
             OR nempri_transfer_status IN ('accepted','predecessor')
          THEN 'submitted'
        WHEN nempri_submission_id IS NOT NULL OR hzupn_submission_id IS NOT NULL
             OR nempri_transfer_submission_id IS NOT NULL
          THEN 'prepared'
        ELSE 'draft'
      END
    ) VIRTUAL
    AFTER external_reference;

ALTER TABLE payroll_sickness_cases
  ADD INDEX IF NOT EXISTS idx_payroll_sickness_case_transfer_notice
    (supplier_id, environment, cancelled, nempri_transfer_status);
