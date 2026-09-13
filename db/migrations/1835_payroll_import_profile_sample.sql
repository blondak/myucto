-- MyÚčto.cz — Mzdy → Importy: profily mapování nesou definice mzdových složek
-- a ukázkový profil (Vzor GIRITON) se každé firmě založí jednou.
--
-- `payroll_import_profiles.components_json` = mzdové složky, které pravidla
-- profilu potřebují (import je na potvrzení založí), `is_sample` = ukázkový
-- profil. `supplier.payroll_attendance_sample_seeded_at` je značka, že firma
-- ukázku už dostala — smazaný vzor se znovu nezaloží (stejný princip jako
-- ukázkové napojení Integračního centra, migrace 1830).
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE payroll_import_profiles
  ADD COLUMN IF NOT EXISTS components_json LONGTEXT NULL AFTER rules_json,
  ADD COLUMN IF NOT EXISTS is_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER components_json;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payroll_attendance_sample_seeded_at DATETIME NULL DEFAULT NULL
    COMMENT 'kdy aplikace založila ukázkový profil importu docházky';
