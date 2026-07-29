-- 1063: Prodej dlouhodobého majetku vyloučený z koeficientu § 76 odst. 4.
--
-- Plnění zůstává na ř. 1/2 (základ + daň) a současně se vykáže na ř. 51
-- „S nárokem na odpočet" přes secondary line. Samostatné kódy brání tomu, aby
-- se běžná tuzemská plnění z koeficientu vylučovala automaticky.

SET NAMES utf8mb4;

INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
     kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '1m', 'Prodej dlouhodobého majetku – základní sazba (vyloučeno z koeficientu § 76)',
       'sale', '1', '51', 'A.4', 21.00, 0, 13
 WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '1m'
 );

INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
     kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '2m', 'Prodej dlouhodobého majetku – snížená sazba (vyloučeno z koeficientu § 76)',
       'sale', '2', '51', 'A.4', 12.00, 0, 14
 WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '2m'
 );
