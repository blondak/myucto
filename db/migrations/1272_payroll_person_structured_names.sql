-- MyÚčto.cz — MZ-04/MZ-16: strukturované historické jméno pro úřední výstupy.
--
-- Stávající full_name zůstává zobrazovacím SSOT. Jméno a příjmení se z něj
-- záměrně nedopočítávají, protože dělení víceslovných jmen není spolehlivé.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_identity_history
  ADD COLUMN IF NOT EXISTS first_name
    VARCHAR(96) NULL
    AFTER full_name,
  ADD COLUMN IF NOT EXISTS last_name
    VARCHAR(96) NULL
    AFTER first_name;
