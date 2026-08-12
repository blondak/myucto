-- ==========================================================================
-- 1322 — analytiky banky a pokladny na TEČKOVANÝ tvar (221.100, 211.100)
-- ==========================================================================
-- Osnova vedla dva různé zápisy téže věci: šablona zakládá analytiky s tečkou
-- (501.100, 511.100, 345.100), ale banka a pokladna si kód skládaly bez ní
-- (221100, 211500 — BankAnalyticAssigner::codeFor, CashRegisterService::
-- nextFreeCashAnalytic, backfill v migraci 1318). Účetní vede a čte tečkovaný
-- tvar, takže exporty i ruční kontrola proti její hlavní knize se rozcházely
-- na kosmetice, která ale znemožňuje strojové porovnání kódů.
--
-- Tahle migrace tvar SJEDNOCUJE na tečkovaný — v osnově i všude, kde je kód
-- uložený jako TEXT (kontace, pokladny, bankovní pravidla, karty majetku,
-- mzdové nastavení). Kód v aplikaci skládá nově výhradně
-- BankAnalyticAssigner::codeFor() / CashRegisterService (obojí s tečkou).
--
-- ⚠️ KOLIZE. `uq_coa_supplier_code` nedovolí dvě stejná čísla, a tečkovaná
--    varianta už u někoho existovat MŮŽE (typicky po ruční přestavbě osnovy:
--    221100 „Termínované vklady" vedle nového 221.100). Přejmenování se proto
--    dělá jen tam, kde cílový kód VOLNÝ je; kolizní bezteččkový kód zůstává
--    beze změny a je dál plně funkční (posting na něj běží stejně jako dřív).
--    BankAnalyticAssigner::chartState() kvůli tomu kontroluje obsazenost čísla
--    na OBOU tvarech, aby bankovní účet nedostal suffix, pod kterým už leží
--    cizí historie.
--
-- ⚠️ CO SE NEMĚNÍ. Řádky deníku (`journal_entry_lines`) se nepřepisují vůbec —
--    visí na `account_id`, ne na kódu, takže přejmenování účtu historii
--    zachová beze zbytku. `supplier_bank_accounts.analytic_suffix` drží POUZE
--    číslo ('100'), prefix ani tečka v něm nikdy nebyly.
--
-- Idempotence: každý UPDATE má v podmínce cílový tvar, takže opakovaný běh
-- nemá co měnit.

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1) Účtová osnova. REGEXP '^221[0-9]{1,6}$' cíleně nepustí ani samotnou
--    syntetiku '221' (ta zůstává), ani už tečkované kódy, ani písmenné
--    analytiky typu '311D'.
-- --------------------------------------------------------------------------
UPDATE chart_of_accounts c
   SET c.account_code = CONCAT(LEFT(c.account_code, 3), '.', SUBSTRING(c.account_code, 4))
 WHERE c.account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM (SELECT supplier_id, account_code FROM chart_of_accounts) x
          WHERE x.supplier_id = c.supplier_id
            AND x.account_code = CONCAT(LEFT(c.account_code, 3), '.', SUBSTRING(c.account_code, 4))
       );

-- --------------------------------------------------------------------------
-- 2) Kontace (`posting_rules`) — globální i per-tenant. Migrace 1091 nasadila
--    pravidla termínovaného vkladu s bezteččkovým '221100', takže bez tohohle
--    kroku by kontace ukazovala na kód, který v osnově po kroku 1 už není.
--    Přejmenovává se jen tam, kde přejmenování v osnově opravdu proběhlo —
--    u kolizních (nepřejmenovaných) kódů musí kontace zůstat na starém tvaru.
-- --------------------------------------------------------------------------
UPDATE posting_rules r
   SET r.debit_account_code = CONCAT(LEFT(r.debit_account_code, 3), '.', SUBSTRING(r.debit_account_code, 4))
 WHERE r.debit_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE (r.supplier_id IS NULL OR c.supplier_id = r.supplier_id)
            AND c.account_code = r.debit_account_code
       );

UPDATE posting_rules r
   SET r.credit_account_code = CONCAT(LEFT(r.credit_account_code, 3), '.', SUBSTRING(r.credit_account_code, 4))
 WHERE r.credit_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE (r.supplier_id IS NULL OR c.supplier_id = r.supplier_id)
            AND c.account_code = r.credit_account_code
       );

-- --------------------------------------------------------------------------
-- 3) Bankovní kontační pravidla (`bank_posting_rules`) — vždy per-tenant.
-- --------------------------------------------------------------------------
UPDATE bank_posting_rules b
   SET b.debit_account_code = CONCAT(LEFT(b.debit_account_code, 3), '.', SUBSTRING(b.debit_account_code, 4))
 WHERE b.debit_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = b.supplier_id AND c.account_code = b.debit_account_code
       );

UPDATE bank_posting_rules b
   SET b.credit_account_code = CONCAT(LEFT(b.credit_account_code, 3), '.', SUBSTRING(b.credit_account_code, 4))
 WHERE b.credit_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = b.supplier_id AND c.account_code = b.credit_account_code
       );

-- --------------------------------------------------------------------------
-- 4) Pokladny (`cash_registers.account_code`). Účet pokladny je nosičem
--    zůstatku (R6) a musí v osnově existovat — proto stejná podmínka „jen když
--    starý kód v osnově zmizel".
-- --------------------------------------------------------------------------
UPDATE cash_registers r
   SET r.account_code = CONCAT(LEFT(r.account_code, 3), '.', SUBSTRING(r.account_code, 4))
 WHERE r.account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = r.supplier_id AND c.account_code = r.account_code
       );

-- --------------------------------------------------------------------------
-- 5) Karty majetku (`assets`) — účty pořízení/zařazení/oprávek. Banka ani
--    pokladna tam běžně nejsou, ale sloupce jsou volné texty, takže je
--    normalizujeme stejně (jinak by v jedné DB zůstaly oba tvary).
-- --------------------------------------------------------------------------
UPDATE assets a
   SET a.asset_account_code = CONCAT(LEFT(a.asset_account_code, 3), '.', SUBSTRING(a.asset_account_code, 4))
 WHERE a.asset_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = a.supplier_id AND c.account_code = a.asset_account_code
       );

UPDATE assets a
   SET a.acquisition_account_code = CONCAT(LEFT(a.acquisition_account_code, 3), '.', SUBSTRING(a.acquisition_account_code, 4))
 WHERE a.acquisition_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = a.supplier_id AND c.account_code = a.acquisition_account_code
       );

UPDATE assets a
   SET a.accumulated_account_code = CONCAT(LEFT(a.accumulated_account_code, 3), '.', SUBSTRING(a.accumulated_account_code, 4))
 WHERE a.accumulated_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = a.supplier_id AND c.account_code = a.accumulated_account_code
       );

-- --------------------------------------------------------------------------
-- 6) Mzdové nastavení zaměstnavatele (`payroll_employer_settings`) — výplatní
--    a odvodové účty. Výplata z pokladny (211.xxx) tam být může.
-- --------------------------------------------------------------------------
UPDATE payroll_employer_settings p
   SET p.employment_gross_debit_account = CONCAT(LEFT(p.employment_gross_debit_account, 3), '.', SUBSTRING(p.employment_gross_debit_account, 4))
 WHERE p.employment_gross_debit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.employment_gross_debit_account);

UPDATE payroll_employer_settings p
   SET p.employment_gross_credit_account = CONCAT(LEFT(p.employment_gross_credit_account, 3), '.', SUBSTRING(p.employment_gross_credit_account, 4))
 WHERE p.employment_gross_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.employment_gross_credit_account);

UPDATE payroll_employer_settings p
   SET p.partner_gross_debit_account = CONCAT(LEFT(p.partner_gross_debit_account, 3), '.', SUBSTRING(p.partner_gross_debit_account, 4))
 WHERE p.partner_gross_debit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.partner_gross_debit_account);

UPDATE payroll_employer_settings p
   SET p.partner_gross_credit_account = CONCAT(LEFT(p.partner_gross_credit_account, 3), '.', SUBSTRING(p.partner_gross_credit_account, 4))
 WHERE p.partner_gross_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.partner_gross_credit_account);

UPDATE payroll_employer_settings p
   SET p.statutory_gross_debit_account = CONCAT(LEFT(p.statutory_gross_debit_account, 3), '.', SUBSTRING(p.statutory_gross_debit_account, 4))
 WHERE p.statutory_gross_debit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.statutory_gross_debit_account);

UPDATE payroll_employer_settings p
   SET p.statutory_gross_credit_account = CONCAT(LEFT(p.statutory_gross_credit_account, 3), '.', SUBSTRING(p.statutory_gross_credit_account, 4))
 WHERE p.statutory_gross_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.statutory_gross_credit_account);

UPDATE payroll_employer_settings p
   SET p.employer_insurance_debit_account = CONCAT(LEFT(p.employer_insurance_debit_account, 3), '.', SUBSTRING(p.employer_insurance_debit_account, 4))
 WHERE p.employer_insurance_debit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.employer_insurance_debit_account);

UPDATE payroll_employer_settings p
   SET p.social_insurance_credit_account = CONCAT(LEFT(p.social_insurance_credit_account, 3), '.', SUBSTRING(p.social_insurance_credit_account, 4))
 WHERE p.social_insurance_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.social_insurance_credit_account);

UPDATE payroll_employer_settings p
   SET p.health_insurance_credit_account = CONCAT(LEFT(p.health_insurance_credit_account, 3), '.', SUBSTRING(p.health_insurance_credit_account, 4))
 WHERE p.health_insurance_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.health_insurance_credit_account);

UPDATE payroll_employer_settings p
   SET p.income_tax_credit_account = CONCAT(LEFT(p.income_tax_credit_account, 3), '.', SUBSTRING(p.income_tax_credit_account, 4))
 WHERE p.income_tax_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.income_tax_credit_account);

UPDATE payroll_employer_settings p
   SET p.other_deductions_credit_account = CONCAT(LEFT(p.other_deductions_credit_account, 3), '.', SUBSTRING(p.other_deductions_credit_account, 4))
 WHERE p.other_deductions_credit_account REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = p.supplier_id AND c.account_code = p.other_deductions_credit_account);

-- --------------------------------------------------------------------------
-- 7) Nastavení účetnictví a pravidla klasifikace — dorovnání pro úplnost
--    (analytika banky/pokladny se tam dá zadat ručně).
-- --------------------------------------------------------------------------
UPDATE expense_classification_rules r
   SET r.target_account_code = CONCAT(LEFT(r.target_account_code, 3), '.', SUBSTRING(r.target_account_code, 4))
 WHERE r.target_account_code REGEXP '^(221|211)[0-9]{1,6}$'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c
                    WHERE c.supplier_id = r.supplier_id AND c.account_code = r.target_account_code);
