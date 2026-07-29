-- MyÚčto.cz — EUR varianta pravidel „Termínovaný vklad — vytvoření / uzavření".
--
-- Pravidla pro termínovaný vklad existovala jen s applies_currency='CZK' (id 160/161), takže
-- EUR vklad propadl na 'fx_not_supported' a zůstal nezaúčtovaný.
-- Pravidla se v BankPostingService::hasMatchingRuleForCurrency vybírají PŘESNOU shodou měny
-- (žádný fallback na CZK), takže EUR potřebuje vlastní řádek — jinak cizoměnová nespárovaná
-- transakce ani nedojde k applyRules.
--
-- ÚČET: 221100 „Termínované vklady" — stejná analytika jako CZK vklady, ZÁMĚRNĚ.
--   Osnova typicky NEROZLIŠUJE měnu analytikou: EUR běžný účet účtuje na holé 221
--   úplně stejně jako CZK účet a měna se nese na ŘÁDKU (currency_code / fx_rate / amount_foreign
--   — §4/12 ZoÚ, souběžné vedení v cizí měně). Analytika tedy dělí podle PODSTATY (termínovaný
--   vklad × běžný účet), ne podle měny. Zakládat EUR-only analytiku by tuhle konvenci porušilo.
--   Cizoměnovou stopu na obě nohy doplní sám engine (BankPostingService::withFxTrace) z kurzu
--   ČNB ke dni transakce, takže zaúčtovaná Kč částka sedí na korunu přesně.
--
--   (Externí účetní může vést EUR vklad na vlastní analytice. Nekopírujeme cizí číslování —
--   kopírujeme podstatu; 221100 je náš ekvivalent a přeceňuje se k rozvahovému dni stejně.)
--
-- PŘECENĚNÍ k 31. 12.: tahle migrace ho NEŘEŠÍ — patří do uzávěrky (FxRevaluationService),
-- která bere cizoměnové bankovní zůstatky jako vstup (bank_rows: account_code, currency_code,
-- foreign_balance). Po zaúčtování má 221100 co přeceňovat.
--
-- Priorita 30 = shodná s CZK variantami (musí přebít detektor vlastních převodů, který by
-- přesun na vklad jinak spolkl jako převod mezi vlastními účty).
-- mode='auto' + operation_type='bank.rule.custom' — shodné s CZK vzorem (id 160/161).

-- Pravidla jsou tenant-specifická (termínovaný vklad firmy supplier_id=1), takže se drží
-- literálu 1 — ALE supplier_id se bere z JOINu na `supplier`, ne z konstanty. Na čerstvém
-- nasazení, kde dodavatele zakládá až setup wizard, tak SELECT nevrátí žádný řádek a migrace
-- se stane no-opem místo aby spadla na fk_bpr_supplier a zablokovala všechny další migrace.
-- Stejný vzor jako 1127_vehicle_expense_analytics.sql.
-- created_by = NULL (sloupec je nullable, fk_bpr_user ON DELETE SET NULL) — uživatel s id 1
-- na novém nasazení taky nemusí existovat a autorství seedu stejně nikoho nezajímá.

INSERT INTO bank_posting_rules
    (supplier_id, name, direction, message_contains,
     debit_account_code, credit_account_code, description,
     mode, is_active, priority, operation_type, applies_currency, created_by)
SELECT sup.id, 'Termínovaný vklad — vytvoření (EUR)', 'outgoing', 'vytvoreni terminovaneho vkladu',
       '221100', '221', 'Převod jistiny na termínovaný vklad (EUR)',
       'auto', 1, 30, 'bank.rule.custom', 'EUR', NULL
  FROM supplier sup
 WHERE sup.id = 1
   AND NOT EXISTS (
       SELECT 1 FROM bank_posting_rules
        WHERE supplier_id = sup.id AND applies_currency = 'EUR'
          AND message_contains = 'vytvoreni terminovaneho vkladu');

INSERT INTO bank_posting_rules
    (supplier_id, name, direction, message_contains,
     debit_account_code, credit_account_code, description,
     mode, is_active, priority, operation_type, applies_currency, created_by)
SELECT sup.id, 'Termínovaný vklad — uzavření (EUR)', 'incoming', 'uzavreni terminovaneho vkladu',
       '221', '221100', 'Vrácení jistiny z termínovaného vkladu (EUR)',
       'auto', 1, 30, 'bank.rule.custom', 'EUR', NULL
  FROM supplier sup
 WHERE sup.id = 1
   AND NOT EXISTS (
       SELECT 1 FROM bank_posting_rules
        WHERE supplier_id = sup.id AND applies_currency = 'EUR'
          AND message_contains = 'uzavreni terminovaneho vkladu');
