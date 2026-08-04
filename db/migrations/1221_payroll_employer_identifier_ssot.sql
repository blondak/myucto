-- MyÚčto.cz — MZ-03: jediný zdroj identifikátorů odvodů zaměstnavatele.
--
-- Osobní pole na supplier patří pouze OSVČ. U právnické osoby odstraníme jen
-- hodnotu, která už byla bezpečně přenesena do kanonického mzdového záznamu.
-- Neodpovídající legacy hodnotu migrace nemaže, aby při neúplném starém
-- nastavení nedošlo ke ztrátě údaje; runtime ji už jako zaměstnavatelský údaj
-- nepoužívá a další uložení Nastavení firmy ji normalizuje na NULL.

SET NAMES utf8mb4;

UPDATE supplier supplier
SET supplier.cssz_vsdp = NULL
WHERE supplier.taxpayer_type = 'po'
  AND supplier.cssz_vsdp IS NOT NULL
  AND EXISTS (
    SELECT 1
      FROM payroll_offices office
     WHERE office.supplier_id = supplier.id
       AND office.social_security_variable_symbol IS NOT NULL
       AND REGEXP_REPLACE(office.social_security_variable_symbol, '[^0-9]', '')
           = REGEXP_REPLACE(supplier.cssz_vsdp, '[^0-9]', '')
  );

UPDATE supplier supplier
SET supplier.cssz_ossz_code = NULL
WHERE supplier.taxpayer_type = 'po'
  AND supplier.cssz_ossz_code IS NOT NULL
  AND EXISTS (
    SELECT 1
      FROM payroll_employer_settings settings
     WHERE settings.supplier_id = supplier.id
       AND settings.social_security_office_code IS NOT NULL
       AND TRIM(settings.social_security_office_code)
           = TRIM(supplier.cssz_ossz_code)
  );

UPDATE supplier supplier
SET supplier.health_insurance_number = NULL
WHERE supplier.taxpayer_type = 'po'
  AND supplier.health_insurance_number IS NOT NULL
  AND EXISTS (
    SELECT 1
      FROM payroll_institution_accounts account
      JOIN payroll_institutions institution
        ON institution.supplier_id = account.supplier_id
       AND institution.id = account.institution_id
     WHERE account.supplier_id = supplier.id
       AND institution.institution_type = 'health_insurer'
       AND account.variable_symbol IS NOT NULL
       AND REGEXP_REPLACE(account.variable_symbol, '[^0-9]', '')
           = REGEXP_REPLACE(supplier.health_insurance_number, '[^0-9]', '')
  );
