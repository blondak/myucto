-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález H-3:
-- služba s místem plnění mimo tuzemsko (3. země) se hlásila jako VÝVOZ ZBOŽÍ
--
-- Číselník měl pro zahraničního odběratele mimo EU jediný kód '26' = „Vývoz zboží do
-- 3. země" (§ 66) → DPHDP3 ř. 22 (pln_vyvoz). Poradenství, IT nebo licence pro US/CH
-- klienta ale žádný vývoz zboží není: je to plnění s místem plnění mimo tuzemsko
-- s nárokem na odpočet (§ 9 odst. 1 ve spojení s § 72 odst. 1 písm. d) → ř. 26 (pln_ost).
-- Řádek 26 přitom v číselníku NEMĚL ŽÁDNÝ KÓD, takže byl z aplikace nedosažitelný,
-- přestože ho DphPriznaniBuilder umí naplnit (mapa řádků i USER_SELECTABLE_LINES).
--
-- Do koeficientu § 76 vstupují oba řádky stejně (čitatel „s nárokem na odpočet"), takže
-- oprava nemění vypočtenou daň — mění řádek, na kterém je plnění přiznané.
--
-- Souhrnné hlášení se ani jednoho kódu netýká (SH je jen pro plnění do JČS: 20/22/31).
--
-- Rozlišení zboží vs. služba dělá InvoiceRepository::defaultSaleClassificationCode()
-- podle měrné jednotky položky (sdílená heuristika classifyUnitsGoodsVsServices).
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id — NULL je v MariaDB unikátně distinct, proto explicitní guard).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
SELECT NULL, '26s', 'Poskytnutí služby s místem plnění mimo tuzemsko – 3. země (§ 9 odst. 1)', 'sale', '26', NULL, 0.00, 22
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '26s');
