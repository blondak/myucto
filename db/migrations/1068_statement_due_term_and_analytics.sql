-- EP-10/M32/L31: analytické členění dle splatnosti a druhu OP/cenných papírů.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

INSERT IGNORE INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, x.code, x.name, x.account_type, x.normal_side, 0, p.id, 1, x.tax_deductibility
FROM supplier s
JOIN (
    SELECT '311D' code, 'Dlouhodobé pohledávky z obchodních vztahů' name, 'asset' account_type, 'debit' normal_side, '311' parent_code, NULL tax_deductibility
    UNION ALL SELECT '461K', 'Krátkodobá část dlouhodobých úvěrů', 'liability', 'credit', '461', NULL
    UNION ALL SELECT '559M', 'Opravné položky k dlouhodobému majetku', 'expense', 'debit', '559', 'non_deductible'
    UNION ALL SELECT '559Z', 'Opravné položky k zásobám', 'expense', 'debit', '559', 'non_deductible'
    UNION ALL SELECT '559P', 'Opravné položky k pohledávkám', 'expense', 'debit', '559', 'non_deductible'
    UNION ALL SELECT '561P', 'Prodané podíly', 'expense', 'debit', '561', 'deductible'
    UNION ALL SELECT '561C', 'Prodané ostatní cenné papíry', 'expense', 'debit', '561', 'deductible'
) x
JOIN chart_of_accounts p ON p.supplier_id = s.id AND p.account_code = x.parent_code;

INSERT IGNORE INTO statement_account_map
    (version_id, row_code, account_prefix, target, balance_condition, sign)
SELECT sv.id, x.row_code, x.account_prefix, 'gross', 'any', 1
FROM statement_versions sv
JOIN (
    SELECT 'balance_sheet' statement_type, 'C.II.1.1.' row_code, '311D' account_prefix
    UNION ALL SELECT 'balance_sheet', 'P.C.II.2.', '461K'
    UNION ALL SELECT 'income_statement', 'E.1.2.', '559M'
    UNION ALL SELECT 'income_statement', 'E.2.', '559Z'
    UNION ALL SELECT 'income_statement', 'E.3.', '559P'
    UNION ALL SELECT 'income_statement', 'G.', '561P'
    UNION ALL SELECT 'income_statement', 'K.', '561C'
) x ON x.statement_type = sv.statement_type;

UPDATE posting_rules
   SET debit_account_code = '559P'
 WHERE supplier_id IS NULL
   AND rule_key = 'allowance.receivable.acct.create'
   AND debit_account_code = '559';

UPDATE posting_rules
   SET debit_account_code = '559P'
 WHERE supplier_id IS NOT NULL
   AND rule_key = 'allowance.receivable.acct.create'
   AND debit_account_code = '559'
   AND credit_account_code = '391';
