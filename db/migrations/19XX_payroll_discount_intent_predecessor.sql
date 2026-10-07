-- MyUcto.cz - OZUSPOJ: záměr převzatý z přijatého podání předchozího programu.
--
-- Po převodu mezd chyběl záměr uplatňovat slevu na pojistném (§ 7a odst. 5
-- zákona č. 589/1992 Sb.), který ČSSZ přijala od předchozího mzdového programu,
-- a sleva se proto neuplatnila. Záměr se teď převezme z datové věty OZUSPOJ23
-- standardní cestou, rovnou jako přijatý s dnem doručení z protokolu ČSSZ.
--
-- Sloupce jen označují původ: převzatý záměr nemá podání z MyÚčta, a proto
-- k němu nevzniká povinnost ani lhůta oznámení (jinak by se hlásila falešná
-- povinnost „po lhůtě"). CHECK na `accepted_on` se NEUVOLŇUJE: převzatý záměr
-- bez dne doručení neexistuje.

SET NAMES utf8mb4;

ALTER TABLE payroll_discount_intents
  ADD COLUMN IF NOT EXISTS predecessor_source VARCHAR(32) NULL
    AFTER end_submission_id,
  ADD COLUMN IF NOT EXISTS predecessor_reference VARCHAR(190) NULL
    AFTER predecessor_source;

-- Převzatý záměr nese vždy odkaz na zdroj a nikdy podání z MyÚčta.
ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_predecessor;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_predecessor
    CHECK (
      predecessor_source IS NULL
      OR (
        predecessor_source IN ('ozuspoj_xml')
        AND predecessor_reference IS NOT NULL
        AND start_submission_id IS NULL
      )
    );
