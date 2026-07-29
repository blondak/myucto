-- MyÚčto.cz — Zálohy §38a: rozhodnutí FÚ (§174 DŘ) s ROZSAHEM OD-DO (effective_to)
--
-- Rozšíření #43/#46: tax_advance_overrides dosud nese jen `effective_from` (otevřený
-- konec do nekonečna). FÚ ale rozhodnutím o změně výše záloh (§174 DŘ) zpravidla stanoví
-- KONKRÉTNÍ ZÁLOHOVÉ OBDOBÍ [od, do] — typicky do lhůty podání dalšího přiznání, kdy se
-- výše záloh znovu přepočte. Bez horní meze by rozhodnutí platilo napořád a přetékalo i do
-- období, kde už má platit predikce §38a z nového přiznání.
--
-- `effective_to DATE NULL`: horní mez účinnosti (včetně). NULL = otevřený konec (dosavadní
-- chování — rozhodnutí platí od `effective_from` bez konce). Sémantika generování předpisů
-- (TaxAdvanceScheduleService): záloha se splatností UVNITŘ rozsahu [effective_from,
-- effective_to] se počítá dle rozhodnutí (výše + periodicita), MIMO rozsah se bere dle
-- predikce z přiznání. Rozhodnutí smí být měsíční, čtvrtletní i pololetní — rozsah OD-DO na
-- periodicitě nezávisí.
--
-- Idempotence: IF NOT EXISTS (sloupec). Pořadí klauzulí — COMMENT PŘED AFTER (MariaDB).

SET NAMES utf8mb4;

ALTER TABLE tax_advance_overrides
    ADD COLUMN IF NOT EXISTS effective_to DATE NULL
        COMMENT 'konec účinnosti rozhodnutí (včetně); NULL = otevřený konec. Rozsah [effective_from, effective_to]'
        AFTER effective_from;
