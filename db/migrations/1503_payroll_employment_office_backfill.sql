-- MyÚčto.cz — mzdová účtárna pracovního vztahu je povinná.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tahle migrace je
-- ─────────────────────────────────────────────────────────────────────────────
-- `payroll_employments.office_id` a `payroll_employment_terms.office_id` byly
-- od migrace 1195 nepovinné, jenže nepovinné být nemohou:
--
--   * variabilní symbol zaměstnavatele pro odvod sociálního pojistného vychází
--     VÝHRADNĚ z `payroll_offices` (matice rozsahu, § odvody), takže vztah bez
--     účtárny není čím vykázat,
--   * mzdový běh se dá zúžit na jednu účtárnu — vztah bez účtárny by do
--     takového běhu nikdy nespadl a tiše by z výplat vypadl,
--   * kontrolní součty schválení účtárnu u každého vztahu vyžadují.
--
-- Formulář vztahu účtárnu nikdy nenabízel, takže dosavadní data ji mají jen
-- tam, kde ji nastavil import nebo skript. Doplňuje se z výchozí účtárny
-- zaměstnavatele — `payroll_employer_settings.default_office_id` je NOT NULL,
-- takže každá firma, která mzdy vůbec vede, ji má.
--
-- Sloupce ZŮSTÁVAJÍ nullable. Firma, která nemá `payroll_employer_settings`
-- (nebo má výchozí účtárnu deaktivovanou), nemá čím doplnit a vymýšlet jí
-- účtárnu by znamenalo přiřadit odvod k symbolu, který nikdo nezvolil. Takové
-- vztahy proto zůstanou s NULL a ozve se na ně pojmenovaný blocker
-- `employment_without_office` při uzamčení vstupů běhu — tedy v okamžiku, kdy
-- se to dá napravit, ne až hláškou kontrolních součtů při schvalování.
-- Zápisová cesta (`PayrollEmploymentRepository::resolveOffice()`) od téhle
-- chvíle NULL nezaloží.

SET NAMES utf8mb4;

UPDATE payroll_employments employment
   JOIN payroll_employer_settings settings
     ON settings.supplier_id = employment.supplier_id
   JOIN payroll_offices office
     ON office.supplier_id = settings.supplier_id
    AND office.id = settings.default_office_id
    AND office.is_active = 1
    SET employment.office_id = settings.default_office_id
  WHERE employment.office_id IS NULL;

UPDATE payroll_employment_terms terms
   JOIN payroll_employments employment
     ON employment.supplier_id = terms.supplier_id
    AND employment.id = terms.employment_id
    SET terms.office_id = employment.office_id
  WHERE terms.office_id IS NULL
    AND employment.office_id IS NOT NULL;
