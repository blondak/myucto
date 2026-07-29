-- EP-9/L27: automatická uzávěrka výrobků 123/583 a její zrcadlo při otevření roku.

SET NAMES utf8mb4;

INSERT INTO posting_rules
    (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, x.rule_key, x.description, x.debit_account_code, x.credit_account_code, 0, 1
FROM (
    SELECT 'stock.closing.product' rule_key, 'Uzávěrka výrobků — konečný stav' description,
           '123' debit_account_code, '583' credit_account_code
    UNION ALL
    SELECT 'stock.opening.product', 'Otevření výrobků — zrušení počátečního stavu', '583', '123'
) x
WHERE NOT EXISTS (
    SELECT 1 FROM posting_rules pr
     WHERE pr.supplier_id IS NULL AND pr.rule_key = x.rule_key AND pr.priority = 0
);
