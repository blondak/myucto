-- MyÚčto.cz — zápočet čisté mzdy na účet společníka (331/366 MD / 365 D).
--
-- PROČ: jednatel-společník si čistou odměnu nevyplácí, ale započítává ji proti svému
-- účtu ke společníkům. Starý engine „Mzdová rekapitulace" to už umí přes
-- payroll_employees.net_settlement_account_code (migrace 1178) a účtuje 331/366 MD
-- proti zvolenému účtu D. Plný mzdový modul tenhle způsob neměl vůbec — uměl jen
-- hotovost, banku a kombinaci. Tahle migrace mu ho dodává se STEJNOU sémantikou.
--
-- PROČ TO NENÍ VÝPLATA: zápočet je čistě účetní přeúčtování závazku. Nevzniká
-- platba, platební příkaz ani pokladní doklad. Proto se započtená částka NESMÍ
-- objevit jako závazek čisté mzdy (payroll_payment_liabilities) ani jako řádek
-- platební dávky — jinak by firma vyplatila peníze, které už jsou vypořádané.
-- Vynucuje to aplikace: PayrollNetWageLiabilityMaterializer započtenou alokaci
-- přeskočí, PayrollPostingLineBuilder ji naopak zaúčtuje.
--
-- KDE SE ÚČET BERE: destination_reference výplatního pravidla drží přímo KÓD účtu
-- z osnovy tenanta (např. 365.100), stejný princip jako u migrace 1178 — analytika
-- 365.100 vs 365.200 vs 479 je volba účetní, ne vlastnost aplikace. Sloupec
-- partner_settlement_credit_account v nastavení zaměstnavatele je jen firemní
-- default (jedenáctá předkontace vedle 521/331/336/342/379…), aby se dal
-- konfigurovat per firma jako ostatní.
--
-- FK na chart_of_accounts se ZÁMĚRNĚ nedává: osnova je per-tenant a klíčem je
-- (supplier_id, account_code), takže cizí klíč jen na kód by pustil účet jiné
-- firmy. Vazbu ověřuje aplikace se supplier scope — stejný vzor jako u 1178.
--
-- KOMU TO JDE ZAPNOUT: jen vztahům partner_dependent („Příjem společníka") a
-- statutory_body („Odměna za výkon funkce"). Běžný zaměstnanec si mzdu proti 365
-- započítat nemůže. Kontrolu vynucuje aplikace (PayrollPartnerSettlement), ne DB —
-- vazba mezi payroll_employee_profiles a payroll_employments je 1:N a CHECK ji
-- vyjádřit neumí.
--
-- Idempotence: MODIFY je ze své podstaty opakovatelné, ADD COLUMN IF NOT EXISTS
-- podle vzoru 1023, 1177, 1178, 1191.

SET NAMES utf8mb4;

-- 1) Výplatní pravidla a jejich zmrazené alokace musí nový cíl vůbec unést.
--    Obě tabulky se plní ze stejné hodnoty (PayrollNetRepository zapisuje
--    destination_kind alokace přímo z pravidla), takže se widenují spolu —
--    jinak by zápis alokace spadl na truncated ENUM.
ALTER TABLE payroll_payout_rules
  MODIFY destination_kind ENUM('bank','cash','partner_settlement') NOT NULL;

ALTER TABLE payroll_payout_allocations
  MODIFY destination_kind ENUM('bank','cash','partner_settlement') NOT NULL;

-- 2) Osobní karta: čtvrtý způsob výplaty + účet, na který se započítává.
--    NULL = karta zápočet nepoužívá; hodnota je povinná právě u partner_settlement
--    (vynucuje PayrollPersonProfileValidator).
ALTER TABLE payroll_employee_profiles
  MODIFY payout_method
    ENUM('cash','bank','mixed','partner_settlement') NOT NULL DEFAULT 'cash';

ALTER TABLE payroll_employee_profiles
  ADD COLUMN IF NOT EXISTS partner_settlement_account_code VARCHAR(10) NULL
      COMMENT 'kód účtu, proti kterému se započítává čistá mzda společníka (např. 365.100); NULL = zápočet se nepoužívá'
      AFTER payout_method;

-- 3) Firemní předkontace: default protiúčtu zápočtu. Doplňuje jedenáctou položku
--    do PayrollAccountingDefaults::ACCOUNTS; typ liability jako 331/336/342/379.
ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS partner_settlement_credit_account VARCHAR(10) NOT NULL DEFAULT '365'
      COMMENT 'Ostatní dluhy ke společníkům obchodní korporace — protiúčet zápočtu čisté mzdy'
      AFTER other_deductions_credit_account;

-- 4) Účet 365 do osnovy firmám, které ho nemají.
--    PayrollEmployerSettingsValidator vyžaduje, aby KAŽDÁ předkontace existovala
--    a byla aktivní v osnově tenanta. Bez tohohle doplnění by firma bez 365
--    najednou nemohla uložit nastavení mezd — a to jen proto, že přibyla nová
--    předkontace. Účet je v šabloně (ChartOfAccountsTemplate: '365', liability,
--    credit), takže jen dorovnáváme starší tenanty. Firmy, které účet mají,
--    zůstávají netknuté (LEFT JOIN ... IS NULL), a analytiku 365.x si účetní
--    může založit a nastavit sama.
INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '365', 'Ostatní závazky ke společníkům obchodní korporace', 'liability', 'credit', 1, NULL, 1, 'deductible'
FROM (
  SELECT DISTINCT p.supplier_id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '365'
   WHERE c.id IS NULL
) AS s;
