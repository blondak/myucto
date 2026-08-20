-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález L-3:
-- řádky 23 a 24 přiznání neměly v číselníku žádný kód, takže byly nedosažitelné
--
-- DphPriznaniBuilder umí naplnit ř. 23 (dodání nového dopravního prostředku osobě
-- neregistrované k dani v JČS, § 19) i ř. 24 (zasílání zboží, § 8) a oba jsou
-- v USER_SELECTABLE_LINES, jenže žádná klasifikace na ně nemířila — uživatel je tedy
-- nemohl vykázat jinak než ručním per-tenant kódem v Číselnících.
--
-- Obojí jsou plnění s nárokem na odpočet bez daně na výstupu (oddíl C, jen základ),
-- do kontrolního ani souhrnného hlášení nepatří:
--   • nový dopravní prostředek neregistrované osobě se hlásí samostatným hlášením
--     dle § 19 odst. 4, ne souhrnným,
--   • zasílání zboží je zdaněné v zemi spotřeby (dnes typicky přes OSS; kód je pro
--     firmy, které režim OSS nepoužívají).
--
-- Kódy se nepřiřazují automaticky — ze sazby ani ze země je poznat nelze, vybírá je
-- uživatel. Přemístění obchodního majetku do JČS (§ 13 odst. 6, SH kód plnění 1) se
-- ZÁMĚRNĚ nedoplňuje: věta souhrnného hlášení by potřebovala vlastní DIČ tenanta
-- v cílovém státě, které aplikace zatím nemodeluje.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id — NULL je v MariaDB unikátně distinct, proto explicitní guard).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
SELECT NULL, '23n', 'Dodání nového dopravního prostředku osobě neregistrované k dani v JČS (§ 19)', 'sale', '23', NULL, 0.00, 26
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '23n');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
SELECT NULL, '24z', 'Zasílání zboží do jiného členského státu (§ 8) – mimo režim OSS', 'sale', '24', NULL, 0.00, 27
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '24z');
