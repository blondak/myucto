-- MyÚčto.cz — E4: pravidla bankovního účtování v2, šablony a mapa odvodů

SET NAMES utf8mb4;

ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS priority SMALLINT UNSIGNED NOT NULL DEFAULT 100
    COMMENT 'first-match-wins, ASC; < 50 přebije systémové detektory (master B8)',
  ADD COLUMN IF NOT EXISTS operation_type VARCHAR(40) NULL
    COMMENT 'katalog OperationType (§5.2); NULL = bank.rule.custom',
  ADD COLUMN IF NOT EXISTS system_template_key VARCHAR(64) NULL
    COMMENT 'bank_rule_templates.template_key, pokud vzniklo instancí šablony',
  ADD COLUMN IF NOT EXISTS auto_amount_cap DECIMAL(14,2) NULL
    COMMENT 'strop pro auto; nad něj vždy suggest (master §13)',
  ADD COLUMN IF NOT EXISTS applies_currency CHAR(3) NOT NULL DEFAULT 'CZK'
    COMMENT 'ne-CZK jen výsledkové kontace 5xx/6xx (master ú9)',
  ADD COLUMN IF NOT EXISTS counterparty_prefix VARCHAR(6) NULL
    COMMENT 'předčíslí protiúčtu (FÚ) — matriku neznáme, předčíslí determinuje daň',
  ADD COLUMN IF NOT EXISTS approved_streak TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'po sobě jdoucí approve bez override (podklad promotion, F-F)';

ALTER TABLE bank_posting_rules DROP CONSTRAINT IF EXISTS chk_bpr_criteria;
ALTER TABLE bank_posting_rules
  ADD CONSTRAINT chk_bpr_criteria CHECK (
    counterparty_account IS NOT NULL
    OR counterparty_bank IS NOT NULL
    OR counterparty_prefix IS NOT NULL
    OR variable_symbol IS NOT NULL
    OR message_contains IS NOT NULL
  );

CREATE UNIQUE INDEX IF NOT EXISTS uq_bpr_template
  ON bank_posting_rules (supplier_id, system_template_key);

CREATE TABLE IF NOT EXISTS bank_rule_templates (
  id                  SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key        VARCHAR(64) NOT NULL,
  name_cs             VARCHAR(120) NOT NULL,
  name_en             VARCHAR(120) NOT NULL,
  direction           ENUM('incoming','outgoing') NOT NULL,
  operation_type      VARCHAR(40) NOT NULL,
  counterparty_bank   VARCHAR(10) NULL,
  counterparty_prefix VARCHAR(6) NULL,
  vs_placeholder      VARCHAR(40) NULL COMMENT '{cssz_vsdp}|{health_insurance_number}|{dic_kmen}',
  message_contains    VARCHAR(120) NULL,
  rule_key            VARCHAR(64) NOT NULL COMMENT 'kontace přes posting_rules resolve',
  default_priority    SMALLINT UNSIGNED NOT NULL DEFAULT 100
    COMMENT '40 u úroků/poplatků: instance přebije own-transfer detektor (master ú14/B16)',
  sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_brt_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bank_rule_templates
       (template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
        counterparty_prefix, vs_placeholder, message_contains, rule_key,
        default_priority, sort_order, is_active)
SELECT s.template_key, s.name_cs, s.name_en, s.direction, s.operation_type,
       s.counterparty_bank, s.counterparty_prefix, s.vs_placeholder,
       s.message_contains, s.rule_key, s.default_priority, s.sort_order, 1
  FROM (
    SELECT 'remit.social.own' AS template_key,
           'Odvod sociálního pojištění OSVČ' AS name_cs,
           'Self-employed social insurance remittance' AS name_en,
           'outgoing' AS direction,
           'bank.remittance.social.own' AS operation_type,
           '0710' AS counterparty_bank,
           CAST(NULL AS CHAR(6)) AS counterparty_prefix,
           '{cssz_vsdp}' AS vs_placeholder,
           CAST(NULL AS CHAR(120)) AS message_contains,
           'insurance.social.paid' AS rule_key,
           100 AS default_priority,
           10 AS sort_order
    UNION ALL SELECT
           'remit.health.own', 'Odvod zdravotního pojištění OSVČ',
           'Self-employed health insurance remittance', 'outgoing',
           'bank.remittance.health.own', '0710', NULL,
           '{health_insurance_number}', NULL, 'insurance.health.paid', 100, 20
    UNION ALL SELECT
           'remit.vat', 'Platba DPH finančnímu úřadu',
           'VAT payment to the tax authority', 'outgoing',
           'bank.remittance.vat', '0710', '705',
           '{dic_kmen}', NULL, 'vat.payment', 100, 30
    UNION ALL SELECT
           'remit.income.advance', 'Záloha nebo doplatek daně z příjmů',
           'Income tax advance or settlement', 'outgoing',
           'bank.remittance.income', '0710', NULL,
           '{dic_kmen}', NULL, 'tax.income.advance.paid', 100, 40
    UNION ALL SELECT
           'remit.withholding', 'Odvod srážkové daně',
           'Withholding tax remittance', 'outgoing',
           'bank.remittance.withholding', '0710', NULL,
           '{dic_kmen}', NULL, 'tax.withholding.paid', 100, 50
    UNION ALL SELECT
           'remit.payroll.tax', 'Odvod zálohové daně ze mzdy',
           'Payroll income tax remittance', 'outgoing',
           'bank.remittance.payroll', '0710', '713',
           NULL, NULL, 'payroll.tax.remittance', 100, 60
    UNION ALL SELECT
           'bank.interest.received', 'Přijaté bankovní úroky',
           'Bank interest received', 'incoming',
           'bank.interest', NULL, NULL,
           NULL, 'urok', 'bank.interest.received', 40, 70
    UNION ALL SELECT
           'bank.fee', 'Bankovní poplatek',
           'Bank fee', 'outgoing',
           'bank.fee', NULL, NULL,
           NULL, 'poplatek', 'bank.fee', 40, 80
    UNION ALL SELECT
           'recurring.rent', 'Pravidelné nájemné',
           'Recurring rent', 'outgoing',
           'bank.rule.custom', NULL, NULL,
           NULL, 'najem', 'rent.expense', 100, 90
    UNION ALL SELECT
           'recurring.subscription', 'Pravidelné předplatné',
           'Recurring subscription', 'outgoing',
           'bank.rule.custom', NULL, NULL,
           NULL, 'predplatne', 'rent.expense', 100, 100
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM bank_rule_templates t WHERE t.template_key = s.template_key
 );

CREATE TABLE IF NOT EXISTS remittance_map (
  id             SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vs_type        ENUM('dic_kmen','cssz_vsdp','health_insurance_number','other') NOT NULL
    COMMENT 'čemu odpovídá VS platby; other = VS nespadá do identifikátorů firmy',
  taxpayer_type  ENUM('fo','po','any') NOT NULL DEFAULT 'any',
  account_prefix VARCHAR(6) NULL COMMENT 'předčíslí účtu FÚ; NULL = nerozhoduje (ČSSZ/ZP)',
  bank_code      CHAR(4) NOT NULL DEFAULT '0710',
  operation_type VARCHAR(40) NOT NULL,
  rule_key       VARCHAR(64) NOT NULL,
  auto_allowed   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = výsledek nikdy auto (paušál, other)',
  label_cs       VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_rm (vs_type, taxpayer_type, account_prefix, bank_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO remittance_map
       (vs_type, taxpayer_type, account_prefix, bank_code, operation_type,
        rule_key, auto_allowed, label_cs)
SELECT s.vs_type, s.taxpayer_type, s.account_prefix, '0710', s.operation_type,
       s.rule_key, s.auto_allowed, s.label_cs
  FROM (
    SELECT 'dic_kmen' AS vs_type, 'any' AS taxpayer_type, '705' AS account_prefix,
           'bank.remittance.vat' AS operation_type, 'vat.payment' AS rule_key,
           1 AS auto_allowed, 'Platba DPH' AS label_cs
    UNION ALL SELECT 'dic_kmen', 'po', '7704', 'bank.remittance.income',
           'tax.income.advance.paid', 1, 'Daň z příjmů právnických osob'
    UNION ALL SELECT 'dic_kmen', 'fo', '721', 'bank.remittance.income',
           'tax.income.advance.paid', 1, 'Daň z příjmů fyzických osob'
    UNION ALL SELECT 'dic_kmen', 'any', '713', 'bank.remittance.payroll',
           'payroll.tax.remittance', 1, 'Zálohová daň ze závislé činnosti'
    UNION ALL SELECT 'dic_kmen', 'any', '7720', 'bank.remittance.withholding',
           'tax.withholding.paid', 1, 'Srážková daň z příjmů fyzických osob'
    UNION ALL SELECT 'dic_kmen', 'po', '7712', 'bank.remittance.withholding',
           'tax.withholding.paid', 1, 'Srážková daň z příjmů právnických osob'
    UNION ALL SELECT 'dic_kmen', 'any', '7755', 'bank.remittance.property',
           'tax.property.paid', 1, 'Daň z nemovitých věcí'
    UNION ALL SELECT 'dic_kmen', 'any', '748', 'bank.remittance.road',
           'tax.road.paid', 1, 'Silniční daň'
    UNION ALL SELECT 'dic_kmen', 'fo', '2866', 'bank.remittance.flat',
           'tax.income.advance.paid', 0, 'Paušální daň OSVČ'
    UNION ALL SELECT 'cssz_vsdp', 'fo', NULL, 'bank.remittance.social.own',
           'insurance.social.paid', 1, 'Sociální pojištění OSVČ'
    UNION ALL SELECT 'health_insurance_number', 'fo', NULL, 'bank.remittance.health.own',
           'insurance.health.paid', 1, 'Zdravotní pojištění OSVČ'
    UNION ALL SELECT 'other', 'any', NULL, 'bank.remittance.other',
           'payroll.social.remittance', 0, 'Jiný odvod státní instituci'
  ) s
 WHERE NOT EXISTS (
   SELECT 1
     FROM remittance_map m
    WHERE m.vs_type = s.vs_type
      AND m.taxpayer_type = s.taxpayer_type
      AND m.bank_code = '0710'
      AND (m.account_prefix = s.account_prefix
           OR (m.account_prefix IS NULL AND s.account_prefix IS NULL))
 );

INSERT INTO posting_rules
       (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
  FROM (
          SELECT 'tax.income.advance.paid' AS rule_key,
                 'Záloha/doplatek daně z příjmů (§38a)' AS description,
                 '341' AS debit_account_code, '221' AS credit_account_code
    UNION ALL SELECT 'vat.payment', 'Platba DPH finančnímu úřadu', '343', '221'
    UNION ALL SELECT 'tax.withholding.paid', 'Odvod srážkové daně', '342', '221'
    UNION ALL SELECT 'insurance.social.paid', 'Odvod sociálního pojištění OSVČ', '336', '221'
    UNION ALL SELECT 'insurance.health.paid', 'Odvod zdravotního pojištění OSVČ', '336', '221'
    UNION ALL SELECT 'tax.property.paid', 'Platba daně z nemovitých věcí', '345', '221'
    UNION ALL SELECT 'tax.road.paid', 'Platba daně silniční', '345', '221'
    UNION ALL SELECT 'rent.expense', 'Nájemné', '518', '221'
    UNION ALL SELECT 'bank.interest.received', 'Přijaté úroky z bankovního účtu', '221', '662'
  ) s
 WHERE NOT EXISTS (
   SELECT 1
     FROM posting_rules pr
    WHERE pr.supplier_id IS NULL
      AND pr.rule_key = s.rule_key
      AND pr.priority = 0
 );
