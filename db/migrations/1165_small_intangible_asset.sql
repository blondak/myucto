-- 1165: DDNM — drobný nehmotný majetek (ČÚS 013, vyhl. 500/2002 Sb. § 6)
--
-- Evidence drobného majetku uměla jen HMOTNÝ (DDHM). Drobný NEHMOTNÝ majetek — software,
-- licence, ochranné známky pod hranicí, kterou si účetní jednotka stanoví — se do evidence
-- neměl jak dostat: v `ExpenseKind` pro něj nebyl druh výdaje a karta neuměla rozlišit,
-- o jaký majetek jde.
--
-- Matice daní z příjmů to vedla jako CHYBÍ. Praktický dopad: licence za 40 000 Kč se
-- zaúčtovala jako služba a nikde po ní nezůstala stopa, přestože ji ÚJ musí evidovat
-- a při inventarizaci doložit.
--
-- ── Proč vlastní druh výdaje, a ne „small_asset" ────────────────────────────────────
-- Kontace je jiná: drobný hmotný jde na 501 (spotřeba materiálu), drobný nehmotný na 518
-- (ostatní služby) — licence není materiál. Sloučit je do jednoho druhu by znamenalo
-- účtovat software jako spotřebu materiálu, což rozbije druhové členění ve výsledovce.
--
-- ── Proč `asset_kind` na kartě ──────────────────────────────────────────────────────
-- Inventarizace hmotného majetku je fyzická (věc buď je, nebo není), u nehmotného se
-- dokládá licenčním ujednáním. Soupis, který obojí míchá, se nedá použít ani pro jedno.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
    MODIFY expense_kind ENUM('service','material','small_asset','small_intangible','fixed_asset') NULL;

ALTER TABLE expense_classification_rules
    MODIFY expense_kind ENUM('service','material','small_asset','small_intangible','fixed_asset') NOT NULL;

ALTER TABLE small_assets
    ADD COLUMN IF NOT EXISTS asset_kind ENUM('tangible','intangible') NOT NULL DEFAULT 'tangible'
        COMMENT 'DDHM vs DDNM — inventarizace se u nich vede jinak'
        AFTER supplier_id;

-- Kontace DDNM: 518 / 321. Hmotný má `invoice.small_asset.received` na 501, nehmotný
-- musí mít vlastní, jinak by se software účtoval jako spotřeba materiálu.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'invoice.small_intangible.received', 'Přijatá faktura — drobný nehmotný majetek (518/321)', '518', '321', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'invoice.small_intangible.received');
