-- MyÚčto.cz — systémová šablona: výběr hotovosti z banky do pokladny.
--
-- Účtuje se DVOUKROKOVĚ přes 261 „Peníze na cestě":
--   1) bankovní výpis   261 MD / 221 D   ← tato šablona (rule_key cash.withdrawal.banktocash)
--   2) příjmový pokladní doklad  211 MD / 261 D   ← modul pokladny (cash.transfer.frombank)
--
-- Proč ne rovnou 211/221: bankovní výpis prokazuje jen to, že peníze z účtu odešly. Že dorazily
-- do pokladny, prokazuje až pokladní doklad. Účtování napřímo z banky by pokladnu obešlo — její
-- stav by se rozešel s deníkem, protože pokladna zná jen své doklady. Dokud pokladní doklad
-- nevznikne, drží peníze účet 261, což je současně kontrolní bod: 261 má být na konci období nula.
--
-- Výběr NENÍ náklad — je to přesun mezi dvěma aktivy a do základu daně nevstupuje. Daňový režim
-- se řeší až u toho, za co se hotovost utratí (pokladní výdajový doklad).
--
-- Rozdíl CZK/EUR analytiky (211 vs 211003) je na POKLADNÍ straně, ne na bankovní — z banky je to
-- v obou případech 261/221, takže stačí jedna šablona. Analytiku volí až pokladní doklad podle
-- toho, do které pokladny hotovost přišla.
--
-- Priorita 45 (< 50) — musí přebít detektor vlastních převodů, který by výběr jinak spolkl jako
-- převod mezi vlastními účty.

SET NAMES utf8mb4;

INSERT INTO bank_rule_templates
       (template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
        counterparty_prefix, vs_placeholder, message_contains, rule_key,
        default_priority, sort_order, is_active)
SELECT s.template_key, s.name_cs, s.name_en, s.direction, s.operation_type,
       s.counterparty_bank, s.counterparty_prefix, s.vs_placeholder,
       s.message_contains, s.rule_key, s.default_priority, s.sort_order, 1
  FROM (
    SELECT 'cash.withdrawal' AS template_key,
           'Výběr hotovosti do pokladny' AS name_cs,
           'Cash withdrawal to the cash register' AS name_en,
           'outgoing' AS direction,
           'bank.rule.custom' AS operation_type,
           CAST(NULL AS CHAR(10)) AS counterparty_bank,
           CAST(NULL AS CHAR(6)) AS counterparty_prefix,
           CAST(NULL AS CHAR(40)) AS vs_placeholder,
           'vyber' AS message_contains,
           'cash.withdrawal.banktocash' AS rule_key,
           45 AS default_priority,
           95 AS sort_order
  ) s
 WHERE NOT EXISTS (
   SELECT 1 FROM bank_rule_templates t WHERE t.template_key = s.template_key
 );
