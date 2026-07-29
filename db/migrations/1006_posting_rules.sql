-- MyÚčto.cz — Epic F1: kontační pravidla (posting rules) pro podvojné účetnictví
--
-- Standardní předkontace (Section B plánu). Ukládáme jako GLOBÁLNÍ šablonu
-- (supplier_id NULL) — stejný precedent jako vat_classifications: firma bez
-- vlastního záznamu použije globální, per-tenant override (supplier_id = X)
-- vyhrává (řeší PostingService/PostingRuleRepository).
--
-- Účty držíme jako CODE (debit_account_code / credit_account_code) — CODE se
-- mapuje na chart_of_accounts.id v rámci osnovy dané firmy. NULL strana = účet
-- určí PostingService dle druhu dokladu (šablona, split, protiúčet dle případu).
-- DPH (343) se u fakturačních pravidel doplňuje jako samostatná noha z částek
-- VatLedgerService — proto tu 343 u prodejních/nákupních řádků není.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS na (supplier_id, rule_key,
-- priority). Pozn.: UNIQUE (supplier_id, rule_key, priority) s supplier_id NULL
-- nechrání proti duplicitám (NULL != NULL v UNIQUE), proto explicitní guard.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS posting_rules (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NULL COMMENT 'NULL = globální seed šablona; jinak per-tenant override',
  rule_key            VARCHAR(64) NOT NULL,
  description         VARCHAR(255) NOT NULL,
  debit_account_code  VARCHAR(10) NULL COMMENT 'MD účet (CODE); NULL = určí PostingService',
  credit_account_code VARCHAR(10) NULL COMMENT 'D účet (CODE); NULL = určí PostingService',
  priority            INT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pr_supplier_rule_prio (supplier_id, rule_key, priority),
  KEY idx_pr_supplier (supplier_id),
  CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Globální seed standardních předkontací (supplier_id = NULL)
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
FROM (
              SELECT 'invoice.services.issued'           AS rule_key, 'Vydaná faktura — služby (základ; DPH na 343)'          AS description, '311' AS debit_account_code, '602' AS credit_account_code
    UNION ALL SELECT 'invoice.goods.issued',               'Vydaná faktura — zboží (základ; DPH na 343)',                    '311', '604'
    UNION ALL SELECT 'invoice.products.issued',            'Vydaná faktura — vlastní výrobky (základ; DPH na 343)',          '311', '601'
    UNION ALL SELECT 'invoice.material.received',          'Přijatá faktura — materiál (způsob B; DPH odpočet na 343)',      '501', '321'
    UNION ALL SELECT 'invoice.services.received',          'Přijatá faktura — služby (DPH odpočet na 343)',                  '518', '321'
    UNION ALL SELECT 'invoice.dhm.received',               'Přijatá faktura — pořízení DHM (DPH odpočet na 343)',            '042', '321'
    UNION ALL SELECT 'asset.dhm.putintouse',              'Zařazení DHM do užívání',                                        '022', '042'
    UNION ALL SELECT 'payment.receivable.bank',           'Úhrada vydané faktury bankou',                                   '221', '311'
    UNION ALL SELECT 'payment.payable.bank',              'Úhrada přijaté faktury bankou',                                  '321', '221'
    UNION ALL SELECT 'cash.revenue',                       'Tržba v hotovosti (DPH split na 343)',                           '211', '602'
    UNION ALL SELECT 'cash.purchase',                      'Nákup v hotovosti (DPH split na 343)',                           '501', '211'
    UNION ALL SELECT 'cash.withdrawal.banktocash',        'Výběr z banky do pokladny (dvoukrokově přes 261)',               '261', '221'
    UNION ALL SELECT 'cash.deposit.cashtobank',           'Vklad hotovosti na běžný účet (dvoukrokově přes 261)',           '261', '211'
    UNION ALL SELECT 'advance.paid.payment',              'Poskytnutá záloha — úhrada',                                     '314', '221'
    UNION ALL SELECT 'advance.paid.vatdocument',          'Daňový doklad k poskytnuté záloze (odpočet DPH ze zálohy)',       '343', '314'
    UNION ALL SELECT 'advance.paid.settlement',           'Zúčtování poskytnuté zálohy s fakturou',                         '321', '314'
    UNION ALL SELECT 'advance.received.collection',       'Přijatá záloha — inkaso',                                        '221', '324'
    UNION ALL SELECT 'advance.received.vatdocument',      'Daňový doklad k přijaté záloze (DPH na výstupu ze zálohy)',      '324', '343'
    UNION ALL SELECT 'advance.received.settlement',       'Zúčtování přijaté zálohy s fakturou',                            '324', '311'
    UNION ALL SELECT 'payroll.gross',                      'Hrubé mzdy',                                                     '521', '331'
    UNION ALL SELECT 'payroll.social.employer',           'SP + ZP hrazené zaměstnavatelem',                                '524', '336'
    UNION ALL SELECT 'payroll.social.employee',           'SP + ZP sražené zaměstnanci',                                    '331', '336'
    UNION ALL SELECT 'payroll.incometax.advance',         'Záloha na daň z příjmů ze mzdy',                                 '331', '342'
    UNION ALL SELECT 'payroll.payout',                     'Výplata mezd',                                                   '331', '221'
    UNION ALL SELECT 'payroll.social.remittance',         'Odvod pojistného institucím',                                    '336', '221'
    UNION ALL SELECT 'payroll.tax.remittance',            'Odvod zálohové daně FÚ',                                         '342', '221'
    UNION ALL SELECT 'vat.settlement.liability',          'Vypořádání DPH — vlastní daňová povinnost (úhrada)',             '343', '221'
    UNION ALL SELECT 'vat.settlement.excess',             'Vypořádání DPH — nadměrný odpočet (vratka od FÚ)',               '221', '343'
    UNION ALL SELECT 'fx.loss',                            'Kurzová ztráta (protiúčet 311/321/221 dle případu)',             '563', NULL
    UNION ALL SELECT 'fx.gain',                            'Kurzový zisk (protiúčet 311/321/221 dle případu)',               NULL, '663'
    UNION ALL SELECT 'allowance.receivable.legal.create', 'Tvorba zákonné opravné položky k pohledávkám',                   '558', '391'
    UNION ALL SELECT 'allowance.receivable.acct.create',  'Tvorba účetní opravné položky k pohledávkám (daňově neúčinná)',   '559', '391'
    UNION ALL SELECT 'allowance.receivable.release',      'Rozpuštění / zúčtování opravné položky (protiúčet 558/559)',      '391', '558'
    UNION ALL SELECT 'reserve.other.create',              'Tvorba ostatní rezervy',                                         '554', '459'
    UNION ALL SELECT 'reserve.other.release',             'Čerpání / zrušení ostatní rezervy',                              '459', '554'
    UNION ALL SELECT 'accrual.prepaid.expense',           'Náklady příštích období — platba předem (protiúčet 221/321)',     '381', '221'
    UNION ALL SELECT 'accrual.accrued.expense',           'Výdaje příštích období (náklad 5xx dle druhu)',                  NULL, '383'
    UNION ALL SELECT 'accrual.deferred.revenue',          'Výnosy příštích období (protiúčet 311/221)',                     '311', '384'
    UNION ALL SELECT 'accrual.accrued.revenue',           'Příjmy příštích období (výnos 6xx dle druhu)',                   '385', NULL
    UNION ALL SELECT 'estimate.asset',                     'Dohadná položka aktivní (výnos 648/6xx dle případu)',            '388', '648'
    UNION ALL SELECT 'estimate.liability',                'Dohadná položka pasivní (náklad 5xx dle druhu)',                NULL, '389'
    UNION ALL SELECT 'depreciation.booking',              'Odpisy majetku (oprávky 07x/08x dle druhu majetku)',            '551', NULL
    UNION ALL SELECT 'asset.disposal.sold.residual',      'Vyřazení prodaného DHM — zůstatková cena (oprávky 08x)',          '541', NULL
    UNION ALL SELECT 'asset.disposal.remove',             'Vyřazení DHM z evidence v pořizovací ceně (07x/08x vs 01x/02x)', NULL, NULL
    UNION ALL SELECT 'asset.sale.revenue',                'Tržba z prodeje dlouhodobého majetku (DPH split na 343)',        '311', '641'
    UNION ALL SELECT 'inventory.shortage.stock',          'Inventarizace — manko na zásobách (112/132 dle druhu)',          '549', '112'
    UNION ALL SELECT 'inventory.surplus.stock',           'Inventarizace — přebytek zásob (112/132 dle druhu)',             '112', '648'
    UNION ALL SELECT 'inventory.shortage.cash',           'Inventarizace — schodek pokladny',                              '569', '211'
    UNION ALL SELECT 'inventory.surplus.cash',            'Inventarizace — přebytek pokladny',                             '211', '668'
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
);
