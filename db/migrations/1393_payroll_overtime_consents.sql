-- MyÚčto.cz — souhlas zaměstnance s prací přesčas nad rámec nařízeného rozsahu
-- (§ 93 odst. 3 zákoníku práce).
--
-- PROČ VLASTNÍ TABULKA
--
-- § 93 odst. 2 dovoluje zaměstnavateli přesčas NAŘÍDIT jen do 8 hodin v týdnu
-- a 150 hodin v kalendářním roce. Odstavec 3 říká, že nad tenhle rozsah může
-- zaměstnavatel práci přesčas požadovat „pouze na základě dohody se
-- zaměstnancem". Bez evidence té dohody nejde 160. hodinu přesčasu odlišit od
-- porušení zákona — obojí vypadá v docházce úplně stejně.
--
-- Modul dosud neměl kam takovou skutečnost zapsat: `payroll_time_entries` nese
-- jen kategorii `overtime` (nařízený a dohodnutý přesčas se v ní nerozlišují)
-- a `payroll_employment_terms` má sice `tax_declaration_signed` a `risky_work`,
-- ale nic o přesčasové dohodě. Proto tahle tabulka.
--
-- MODEL: SOUHLAS JE OBDOBÍ, NE PŘÍZNAK
--
-- Dohoda se v praxi uzavírá písemně na dobu určitou nebo neurčitou, ne ke
-- konkrétní směně. Tabulka proto drží INTERVAL PLATNOSTI a vyhodnocení limitů
-- se ptá „byl na tenhle den souhlas?". Přesčas v pokrytých dnech se počítá jako
-- DOHODNUTÝ (§ 93 odst. 3), přesčas mimo ně jako NAŘÍZENÝ (§ 93 odst. 2) —
-- a jen ten druhý se poměřuje s 8 h/týden a 150 h/rok.
--
-- Souhlas se neruší mazáním, ale ukončením platnosti (`valid_to`): dohoda,
-- podle které se v minulosti pracovalo, je důkaz pro kontrolu inspektorátu
-- práce a nesmí zmizet zpětně. Mazání se proto povoluje jen tam, kde ještě
-- žádný přesčas v platnosti souhlasu neexistuje — tuhle úvahu ale nechává
-- migrace na aplikaci, protože souhlas sám o sobě není důkaz pohybu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_overtime_consents (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  valid_from         DATE NOT NULL,
  valid_to           DATE NULL,
  document_reference VARCHAR(191) NULL,
  note               VARCHAR(500) NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_overtime_consent_supplier_id (supplier_id, id),
  KEY idx_payroll_overtime_consent_window
    (supplier_id, employment_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_overtime_consent_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_overtime_consent_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_overtime_consent_window
    CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
