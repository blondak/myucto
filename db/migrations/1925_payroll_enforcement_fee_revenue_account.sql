-- MyÚčto.cz — paušální náhrada nákladů plátce mzdy na výnos.
--
-- Plátce mzdy si ze sražené částky odečte paušální náhradu nákladů (§ 270
-- odst. 2 o. s. ř., § 3 nař. vlády č. 595/2006 Sb., 50 Kč měsíčně) a oprávněnému
-- pošle jen zbytek. Mzdový můstek ale účtoval na závazek 379.200 CELOU sraženou
-- částku, kdežto platba oprávněnému ho snížila jen o zbytek — na účtu tak každý
-- měsíc zůstávalo 50 Kč, které se ničím nevyrovnaly.
--
-- Nová předkontace `enforcement_fee_revenue_credit` převede paušál z 379.200 na
-- výnos (MD 379.200 / D 648 Ostatní provozní výnosy podle směrné osnovy).
--
-- ZPĚTNÁ KOMPATIBILITA (stejně jako migrace 1618, 1648, 1658):
--   * Zaúčtované revize se nemění: klíč je v PayrollAccountingDefaults::
--     SNAPSHOT_GATED_ACCOUNTS, takže se převod uplatní jen u běhu, jehož
--     zmrazený snapshot předkontaci nese — tedy u vstupů zamčených PO téhle
--     migraci.
--   * Firmě, která 648 v osnově nemá, zůstane předkontace prázdná a účtuje se
--     přesně jako dřív; výnosový účet si může vybrat v Nastavení mezd.
--   * Firmě s aktivním 648 se předkontace rovnou nastaví: převod je oprava
--     účetní chyby, ne nová funkce, a jiný výnosový účet si firma vybere sama.
--     Zůstatek na 379.200 z dřívějších měsíců se tím nerozpustí — ten je nutné
--     jednorázově přeúčtovat ručně (MD 379.200 / D 648).
--
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS; UPDATE mění jen prázdnou hodnotu.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS enforcement_fee_revenue_credit_account VARCHAR(16) NOT NULL DEFAULT ''
      COMMENT 'Výnos z paušální náhrady plátce mzdy — § 270 odst. 2 o. s. ř.; prázdné = neúčtovat zvlášť'
      AFTER enforcement_deductions_credit_account;

UPDATE payroll_employer_settings settings
   SET settings.enforcement_fee_revenue_credit_account = '648'
 WHERE settings.enforcement_fee_revenue_credit_account = ''
   AND EXISTS (
     SELECT 1
       FROM chart_of_accounts account
      WHERE account.supplier_id = settings.supplier_id
        AND account.account_code = '648'
        AND account.is_active = 1
        AND account.account_type = 'revenue'
   );
