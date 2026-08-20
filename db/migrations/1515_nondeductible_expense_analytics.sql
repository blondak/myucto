-- MyÚčto.cz — nedaňové analytiky zachovávající věcný druh nákladu.
--
-- Samotný příznak `purchase_invoices.tax_deductible = 0` neříká, zda jde o
-- reprezentaci, sociální náklad, dar nebo jiný konkrétní titul. Automatika proto
-- nesmí bez dalšího důkazu volit 513/528/543. Pro běžné druhy nákladu dostane
-- každá firma analytiku .990; konkrétní pravidlo nebo ruční účet má dál přednost.
--
-- Idempotence: INSERT pouze pro chybějící kód. Existující uživatelský účet .990
-- migrace nepřejmenovává ani mu nemění daňovou klasifikaci.

SET NAMES utf8mb4;

INSERT INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic,
     parent_id, is_active, tax_deductibility)
SELECT p.supplier_id, x.code, x.name, 'expense', 'debit', 0,
       p.id, 1, 'non_deductible'
  FROM chart_of_accounts p
  JOIN supplier s ON s.id = p.supplier_id
  JOIN (
            SELECT '501' AS parent, '501.990' AS code, 'Spotřeba materiálu — daňově neuznatelné' AS name
      UNION SELECT '511',           '511.990',         'Opravy a udržování — daňově neuznatelné'
      UNION SELECT '518',           '518.990',         'Ostatní služby — daňově neuznatelné'
      UNION SELECT '548',           '548.990',         'Ostatní provozní náklady — daňově neuznatelné'
       ) x ON x.parent = p.account_code
 WHERE p.parent_id IS NULL
   AND NOT EXISTS (
         SELECT 1
           FROM chart_of_accounts c
          WHERE c.supplier_id = p.supplier_id AND c.account_code = x.code
       );
