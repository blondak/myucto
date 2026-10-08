-- MyUcto.cz - ELDP: odečtené doby po dovršení důchodového věku (kód D).
--
-- PROČ
--
-- Sestavovač evidenčního listu odvozuje u sekcí s kódem D odečtené doby
-- (§ 38 odst. 4 písm. h) zákona č. 582/1991 Sb., údaj 40 ELDP) z nepřítomností.
-- Dny pojištění takové sekce jsou interval Od-Do minus odečtené doby a
-- vyloučené doby leží uvnitř odečtených (logické testy ELDP12 č. 39 a 48).
-- Dosavadní omezení "odečtené doby <= dny pojištění" a "vyloučené doby <= dny
-- pojištění" proto platí jen pro listy bez kódu D; list s měsícem bez účasti
-- ("X") po dovršení věku je porušoval a uložit by nešel.
--
-- Úhrny listu jsou součty sekcí: u sekce bez D je vyloučená doba nejvýš dny
-- pojištění, u sekce s D nejvýš odečtené doby. Za celý list proto platí
-- vyloučené doby <= dny pojištění + odečtené doby.

SET NAMES utf8mb4;

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_days;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_days
    CHECK (
      insurance_days BETWEEN 0 AND 366
      AND deducted_days_total BETWEEN 0 AND 366
      AND excluded_days_total <= insurance_days + deducted_days_total
    );
