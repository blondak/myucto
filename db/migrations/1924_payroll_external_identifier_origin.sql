-- MyÚčto.cz — původ ručně zapsaných OIČ a ID PPV.
--
-- `source_kind = verified_manual_import` spojuje dvě věci s různou důvěrou:
-- číslo, které účetní opsala ručně, a číslo převzaté z validovaného exportu
-- zaměstnanců z ePortálu ČSSZ (Seznam zaměstnanců). Pro odhlášku REGZEC A2
-- u zaměstnance převzatého z ONZ bez dohlášení A3 to rozdíl je: číslo
-- z exportu ČSSZ stačí, ruční číslo jen s výslovným potvrzením.
--
-- NULL = starší zápis bez známého původu (bere se jako ruční).

SET NAMES utf8mb4;

ALTER TABLE payroll_person_external_ids
  ADD COLUMN IF NOT EXISTS source_origin
    ENUM('manual', 'cssz_employee_export', 'registration_import', 'jmhz_import')
    NULL DEFAULT NULL
    AFTER source_reference_hash;

ALTER TABLE payroll_employment_external_ids
  ADD COLUMN IF NOT EXISTS source_origin
    ENUM('manual', 'cssz_employee_export', 'registration_import', 'jmhz_import')
    NULL DEFAULT NULL
    AFTER source_reference_hash;
