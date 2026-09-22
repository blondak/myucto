-- MyÚčto.cz — položka přijatého dokladu mimo předmět DPH
--
-- Doklad se samovyměřením (reverse_charge = 1) zařazuje evidence DPH po položkách. Položku
-- bez kódu bere jako součást přenesené povinnosti: kód odvodí ze země dodavatele (24e / 24 / 5)
-- a u sazby 0 % dosadí sazbu kódu (issue #116 - doklad převzatý z cizího dokladu se sazbou 0 %).
-- Položka, která předmětem daně není - typicky kurzový rozdíl mezi částkou faktury a základem
-- samovyměření z převodu z POHODY, Money S3 nebo PREMIER -, tak neměla jak zůstat mimo
-- přiznání a dostala daň navíc na ř. 5/43 a v KH A.2.
--
-- Kód nemá řádek přiznání ani oddíl KH a sazbu má 0 %: evidence DPH položku zná, daň z ní
-- nedopočte a do přiznání ani do KH ji nepošle.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, 'mimo', 'Mimo předmět DPH – nevstupuje do přiznání ani do KH (např. kurzový rozdíl k samovyměření)', 'purchase', NULL, NULL, 0.00, 0, NULL, 70
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = 'mimo');
