-- MyÚčto.cz — kontace pro pokladní doklady, které reálná praxe potřebuje, ale katalog je neměl.
--
-- Nabídka „Co to je" u pokladního dokladu se odvozuje z `posting_rules` (aktivní kontace s nohou
-- na 211). Reálné doklady účetní za 2025 ale účtovaly přes `purpose='other'` s ručně zadaným
-- protiúčtem, protože pro ně kontace neexistovala:
--   výplata čisté mzdy  366 / 211   ← nejčastější, navazuje na mzdovou rekapitulaci
--   platba nájmu        325 / 211
--   jistota (kauce)     315 / 211
--
-- Výplata míří na 366 „Závazky ke společníkům ze závislé činnosti", ne na 331 „Zaměstnanci":
-- poplatník je jednatel-společník (Typ PP = SJK) a mzdová rekapitulace účtuje 522/366. Firma
-- se zaměstnanci v pracovním poměru si přidá vlastní kontaci 331/211 — katalog se odvozuje
-- z posting_rules, takže se nabídka rozšíří sama.
--
-- Pozor: kontace MUSÍ mít nohu na 211, jinak by z ní CashDocumentService odvodil nesmyslný
-- protiúčet (proto se do nabídky nedostane např. cash.withdrawal.banktocash = 261/221).

SET NAMES utf8mb4;

INSERT INTO posting_rules
       (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
  FROM (
          SELECT 'payroll.payout.cash' AS rule_key,
                 'Výplata čisté mzdy z pokladny' AS description,
                 '366' AS debit_account_code, '211' AS credit_account_code
    UNION ALL SELECT 'rent.payment.cash', 'Platba nájemného z pokladny', '325', '211'
    UNION ALL SELECT 'deposit.paid.cash', 'Složená jistota / kauce z pokladny', '315', '211'
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM posting_rules pr
    WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
 );
