-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález H-5:
-- tuzemský režim přenesené povinnosti šel vykázat jen jako stavební a montážní práce
--
-- Kód předmětu plnění (KH VetaA1/VetaB1.kod_pred_pl) visí v tomhle návrhu na KLASIFIKACI,
-- a globální seed měl jen dvě: '25s' (dodavatel) a '5' (příjemce), obě natvrdo s kódem 4
-- = stavební a montážní práce (§ 92e). Kdo vykupuje odpad a šrot (§ 92c, kód 5) nebo
-- dodává nemovitou věc (§ 92d, kód 3), musel použít kód 4 a hlásil do KH jiný režim, než
-- jaký ve skutečnosti nastal — nesoulad s protistranou, která hlásí ten svůj správně.
--
-- Doplňují se proto varianty pro dva zbylé běžné české režimy, v obou směrech. Řádek
-- přiznání i sekce KH zůstávají shodné s výchozí variantou (ř. 25 / ř. 10, A.1 / B.1),
-- liší se JEN kód předmětu plnění — přesně jako se dnes '1m' liší od '1'.
--
-- Zlato (§ 92b) a další režimy si tenant doplní v Číselnících sám; tvarová validace už
-- pouští i kódy s písmenným sufixem ('1a', '3a'), které dřív odmítala.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id — NULL je v MariaDB unikátně distinct, proto explicitní guard).

SET NAMES utf8mb4;

-- Dodavatel (vystavené) — ř. 25 pln_rez_pren + KH A.1
INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '25s5', 'Tuzemský přenos – odpad a šrot dle přílohy č. 5 (§ 92c) – dodavatel', 'sale', '25', 'A.1', NULL, 1, '5', 24
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '25s5');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '25s3', 'Tuzemský přenos – dodání nemovité věci (§ 92d) – dodavatel', 'sale', '25', 'A.1', NULL, 1, '3', 25
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '25s3');

-- Příjemce (přijaté) — ř. 10 + KH B.1
INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '5c', 'Tuzemský přenos – odpad a šrot dle přílohy č. 5 (§ 92c) – příjemce', 'purchase', '10', 'B.1', 21.00, 1, '5', 51
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '5c');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '5d', 'Tuzemský přenos – dodání nemovité věci (§ 92d) – příjemce', 'purchase', '10', 'B.1', 21.00, 1, '3', 52
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '5d');
