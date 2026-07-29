-- MyÚčto.cz — systémové šablony a mapa odvodů pro ZAMĚSTNAVATELE (právnickou osobu).
--
-- Proč: migrace 1056 nasadila mapu odvodů jen pro OSVČ — řádky `cssz_vsdp` a
-- `health_insurance_number` mají `taxpayer_type='fo'`. Zaměstnavatel (s.r.o., taxpayer_type='po')
-- platí OSSZ a zdravotní pojišťovně pod stejným typem VS, ale žádný řádek nenajde → detektor
-- spadne na 'other' → `bank.remittance.other` s `auto_allowed=0` → odvod se NIKDY nezaúčtuje
-- automaticky. Ověřeno na ostrých datech: 83 plateb OSSZ/ZP/FÚ uvízlo ve frontě.
--
-- Kontace je shodná s OSVČ (336/221 = úhrada existujícího závazku), ale operace je jiná: liší se
-- předpis, který úhradu kryje (mzdová rekapitulace 524/336 + srážky 331/336, ne 526/336 OSVČ),
-- a tím i politika a fronta ke schválení. Proto vlastní operation_type, ne přetížení '.own'.
--
-- Pozor: úhrada bez předpisu na 336 nechá debetní zůstatek (vypadá jako přeplatek) — guard
-- `liability_prescription_missing` v BankPostingService je zde záměrný a tato migrace ho neruší.
-- Nejdřív musí být zaúčtovaná mzdová rekapitulace, teprve pak platba dosedne na závazek.

SET NAMES utf8mb4;

-- 1) Kontace úhrady zdravotního pojištění za zaměstnance. `payroll.social.remittance` už
--    existuje (nasazena dřív jako fallback pro 'other'), zdravotní protějšek chyběl.
INSERT INTO posting_rules
       (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
  FROM (
          SELECT 'payroll.social.remittance' AS rule_key,
                 'Odvod sociálního pojištění za zaměstnance' AS description,
                 '336' AS debit_account_code, '221' AS credit_account_code
    UNION ALL SELECT 'payroll.health.remittance',
                 'Odvod zdravotního pojištění za zaměstnance', '336', '221'
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM posting_rules pr
    WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
 );

-- 2) Mapa odvodů pro PO. UNIQUE je (vs_type, taxpayer_type, account_prefix, bank_code), takže
--    tyto řádky koexistují s 'fo' variantami a nekolidují s nimi.
INSERT INTO remittance_map
       (vs_type, taxpayer_type, account_prefix, bank_code, operation_type,
        rule_key, auto_allowed, label_cs)
SELECT s.vs_type, s.taxpayer_type, s.account_prefix, '0710', s.operation_type,
       s.rule_key, s.auto_allowed, s.label_cs
  FROM (
          SELECT 'cssz_vsdp' AS vs_type, 'po' AS taxpayer_type,
                 CAST(NULL AS CHAR(6)) AS account_prefix,
                 'bank.remittance.social.employer' AS operation_type,
                 'payroll.social.remittance' AS rule_key,
                 1 AS auto_allowed,
                 'Sociální pojištění za zaměstnance' AS label_cs
    UNION ALL SELECT 'health_insurance_number', 'po', NULL,
                 'bank.remittance.health.employer', 'payroll.health.remittance', 1,
                 'Zdravotní pojištění za zaměstnance'
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM remittance_map m
    WHERE m.vs_type = s.vs_type
      AND m.taxpayer_type = s.taxpayer_type
      AND m.bank_code = '0710'
      AND (m.account_prefix = s.account_prefix
           OR (m.account_prefix IS NULL AND s.account_prefix IS NULL))
 );

-- 3) Šablony pravidel pro zaměstnavatele + doplnění rozlišovacích předčíslí u daňových šablon.
INSERT INTO bank_rule_templates
       (template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
        counterparty_prefix, vs_placeholder, message_contains, rule_key,
        default_priority, sort_order, is_active)
SELECT s.template_key, s.name_cs, s.name_en, s.direction, s.operation_type,
       s.counterparty_bank, s.counterparty_prefix, s.vs_placeholder,
       s.message_contains, s.rule_key, s.default_priority, s.sort_order, 1
  FROM (
          SELECT 'remit.social.employer' AS template_key,
                 'Odvod sociálního pojištění za zaměstnance' AS name_cs,
                 'Employer social insurance remittance' AS name_en,
                 'outgoing' AS direction,
                 'bank.remittance.social.employer' AS operation_type,
                 '0710' AS counterparty_bank,
                 CAST(NULL AS CHAR(6)) AS counterparty_prefix,
                 '{cssz_vsdp}' AS vs_placeholder,
                 CAST(NULL AS CHAR(120)) AS message_contains,
                 'payroll.social.remittance' AS rule_key,
                 100 AS default_priority,
                 15 AS sort_order
    UNION ALL SELECT
                 'remit.health.employer', 'Odvod zdravotního pojištění za zaměstnance',
                 'Employer health insurance remittance', 'outgoing',
                 'bank.remittance.health.employer', '0710', NULL,
                 '{health_insurance_number}', NULL, 'payroll.health.remittance', 100, 25
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM bank_rule_templates t WHERE t.template_key = s.template_key
 );

-- 4) Předčíslí u šablon, které ho neměly. Bez něj šablona matchuje JAKÝKOLIV platbu k 0710
--    a kolidovala by s ostatními daňovými šablonami (předčíslí determinuje druh daně).
UPDATE bank_rule_templates SET counterparty_prefix = '7720'
 WHERE template_key = 'remit.withholding' AND counterparty_prefix IS NULL;
UPDATE bank_rule_templates SET counterparty_prefix = '7704'
 WHERE template_key = 'remit.income.advance' AND counterparty_prefix IS NULL;
