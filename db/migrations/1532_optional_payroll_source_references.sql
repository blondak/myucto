-- Uživatelské reference podkladů jsou volitelné. Správnost vstupních hodnot
-- potvrzuje uživatel; textová dohledávka nesmí blokovat uložení ani schválení.
ALTER TABLE payroll_statutory_accumulator_openings
  DROP CONSTRAINT IF EXISTS chk_payroll_statutory_opening_source;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_confirmation_note;
