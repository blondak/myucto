-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález M-3:
-- zvláštní režimy § 89 / § 90 nešly vykázat, protože pro ně nebyl žádný kód
--
-- Migrace 0131 přidala klasifikacím sloupec `kh_regime_code` (VetaA4.kod_rezim_pl:
-- 0 běžný, 1 cestovní služba § 89, 2 použité zboží § 90) a KontrolniHlaseniBuilder ho
-- do XML zapisuje. Jenže v seedu ho NEMĚLA ANI JEDNA klasifikace, takže kod_rezim_pl byl
-- v každé větě A.4 vždy '0' a cestovní kancelář ani bazar zvláštní režim nevykázaly,
-- dokud si per-tenant kód nezaložily ručně v Číselnících.
--
-- Doplňují se čtyři globální kódy — základní a snížená sazba pro každý režim. Řádek
-- přiznání i sekce KH jsou shodné s běžným plněním ('1'/'2', ř. 1/2, A.4); liší se jen
-- kod_rezim_pl, stejně jako se '1m' od '1' liší jen vyloučením z koeficientu § 76.
--
-- POZOR na základ: u obou režimů se daň odvádí z PŘIRÁŽKY (§ 89 odst. 3, § 90 odst. 4),
-- ne z celé úplaty. Do základu se proto zadává přirážka — kódy se AUTOMATICKY NEPŘIŘAZUJÍ,
-- vybírá je uživatel, protože povahu plnění ze sazby ani ze země poznat nelze.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id — NULL je v MariaDB unikátně distinct, proto explicitní guard).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, kh_regime_code, display_order)
SELECT NULL, '1c', 'Cestovní služba § 89 – přirážka, základní sazba', 'sale', '1', 'A.4', 21.00, '1', 16
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '1c');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, kh_regime_code, display_order)
SELECT NULL, '2c', 'Cestovní služba § 89 – přirážka, snížená sazba', 'sale', '2', 'A.4', 12.00, '1', 17
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '2c');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, kh_regime_code, display_order)
SELECT NULL, '1p', 'Použité zboží § 90 – přirážka, základní sazba', 'sale', '1', 'A.4', 21.00, '2', 18
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '1p');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, kh_regime_code, display_order)
SELECT NULL, '2p', 'Použité zboží § 90 – přirážka, snížená sazba', 'sale', '2', 'A.4', 12.00, '2', 19
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '2p');
