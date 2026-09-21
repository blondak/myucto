-- MyÚčto.cz: ruční přepnutí pravidla účtování na automatiku (Šablony účtování, Pravidla)
--
-- Uživatel smí pravidlo přepnout z návrhu na automatiku kdykoli, i bez historie
-- pěti čistých potvrzení (RulePromotionService::promote, v historii pravidla jako
-- rule_promoted s důvodem manual_forced). Aby takové pravidlo opravdu účtovalo samo,
-- AutoPostingPolicyService::decide u něj přeskočí brzdu "aspoň 3 použití" i povinný
-- rozsah částky (amount_min/amount_max). Strop auto_amount_cap, uzavřené období,
-- anomálie, denní limit a úroveň automatiky typu operace platí dál.
--
-- `mode_set_manually_at` = kdy uživatel výslovně nastavil režim auto: ruční povýšení
-- (kandidáta i nekandidáta) nebo založení pravidla z konkrétního pohybu. NULL znamená
-- automatiku bez výslovného rozhodnutí uživatele, ta dál potřebuje historii použití.
-- Každé stažení zpět na návrh (ruční "Jen návrhy" i automatické po odúčtování)
-- sloupec vynuluje.
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS, nativní MariaDB).

ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS mode_set_manually_at DATETIME NULL DEFAULT NULL AFTER mode;
