-- ==========================================================================
-- 1325 — ruční bankovní pravidla s nohou DPH na zúčtovací 343.900
-- ==========================================================================
-- Doplněk k migraci 1323. Ta přepnula GLOBÁLNÍ kontace (`posting_rules`), ale
-- `bank_posting_rules` je jiná tabulka: per-tenant pravidla, která si uživatel
-- píše sám v UI Šablony → Kontace bank. Typicky tam vzniknou položky jako
-- „Platba DPH" (343/221) nebo „Vratka nadměrného odpočtu DPH od FÚ" (221/343).
--
-- Takové pravidlo má PŘEDNOST před detektorem úhrad daní
-- ({@see MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector}), takže
-- by dál účtovalo na plochou syntetiku. Zůstatek 343.900, na který zúčtovací
-- doklad převedl daň období, by pak úhrada nikdy nevynulovala a saldo vůči
-- správci daně by trvale viselo.
--
-- Samostatná migrace (ne dopsání do 1323) proto, že migrátor si drží stav podle
-- JMÉNA souboru: instalace, která 1323 už aplikovala, by editovanou verzi znovu
-- nespustila.
--
-- Podmínka „protistrana je bankovní účet (221…)" je záměrná: pohyb na 343 proti
-- bance JE z definice vypořádání daně s FÚ. Pravidla, kde 343 stojí proti něčemu
-- jinému, se nesahají. Cílí se jen na PŘESNÉ '343' — kdo už má vlastní analytiku,
-- zůstává na své.
--
-- Idempotence: UPDATE je podmíněný konkrétní starou hodnotou, opakovaný běh nemá
-- co měnit.

SET NAMES utf8mb4;

UPDATE bank_posting_rules b
   SET b.debit_account_code = '343.900'
 WHERE b.debit_account_code = '343'
   AND b.credit_account_code LIKE '221%'
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = b.supplier_id AND c.account_code = '343.900');

UPDATE bank_posting_rules b
   SET b.credit_account_code = '343.900'
 WHERE b.credit_account_code = '343'
   AND b.debit_account_code LIKE '221%'
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = b.supplier_id AND c.account_code = '343.900');
