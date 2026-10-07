-- MyUcto.cz - NEMPRI a HZUPN: slovenský případ (příznak zahranicni).
--
-- Element `zahranicni` znamená v obou datových větách něco jiného:
--   * NEMPRI25: „true" pro případ zahraniční mimo Česka, tedy i slovenský,
--   * HZUPN20: „N" je výchozí hodnota pro ČR a SR, „A" je zahraničí mimo
--     Česka a Slovenska.
-- Jediný sloupec `foreign_case` (zahraničí mimo ČR a SR) proto u slovenské
-- neschopenky vždy jedno z podání pokazil. Slovenský případ má vlastní příznak;
-- NEMPRI posílá zahranicni=true pro `foreign_case` i `slovak_case`, HZUPN jen
-- pro `foreign_case`.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS slovak_case TINYINT(1) NOT NULL DEFAULT 0
    AFTER foreign_case;
