-- 1064: Řádek 51 „Bez nároku na odpočet" pro § 76 odst. 4.
--
-- Příležitostná finanční a nemovitostní plnění zůstávají současně na ř. 50,
-- ale přes secondary 51b se odečtou ze jmenovatele vypořádacího koeficientu.

SET NAMES utf8mb4;

INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
     kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '3m', 'Příležitostné osvobozené plnění vyloučené z koeficientu § 76 odst. 4',
       'sale', '50', '51b', NULL, 0.00, 0, 15
 WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '3m'
 );
