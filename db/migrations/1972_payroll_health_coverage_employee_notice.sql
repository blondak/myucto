-- MyUcto.cz - HOZ: den sdělení pojišťovny zaměstnancem a písemné potvrzení zaměstnavatele.
--
-- Pojištěnec je povinen sdělit zaměstnavateli, u které zdravotní pojišťovny je
-- pojištěn, při nástupu a při změně pojišťovny do osmi dnů (§ 12 písm. b)
-- zákona č. 48/1997 Sb.). Zaměstnavatel přijetí sdělení písemně potvrdí.
-- Aplikace eviduje historii pojišťovny osoby, ale ne den sdělení ani den
-- potvrzení, takže z ní nešlo doložit ani jedno.
--
-- Sloupce patří k větě historie pojišťovny, protože popisují právě ji: od
-- kdy je osoba u dané pojišťovny a kdy o tom zaměstnavatele informovala.
-- Zapisují se mimo editor zákonné evidence (vlastní úzká cesta), takže
-- nevstupují do snímku mzdového běhu a nemění jeho otisk. Datum potvrzení
-- nemůže předcházet datu sdělení; to hlídá aplikace, protože MariaDB neumí
-- ADD CONSTRAINT IF NOT EXISTS pro CHECK a migrace musí zůstat opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_health_coverage_history
  ADD COLUMN IF NOT EXISTS employee_notified_on DATE NULL
    COMMENT 'den, kdy pojištěnec sdělil zaměstnavateli pojišťovnu (§ 12 písm. b) zákona 48/1997)'
    AFTER health_evidence_document_sha256,
  ADD COLUMN IF NOT EXISTS employer_confirmed_on DATE NULL
    COMMENT 'den, kdy zaměstnavatel přijetí sdělení písemně potvrdil'
    AFTER employee_notified_on;
