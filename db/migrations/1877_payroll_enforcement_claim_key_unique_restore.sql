-- MyÚčto.cz — MZ-14: obnova unikátnosti claim_key exekučních pohledávek.
--
-- 1242 měla v jednom ALTER TABLE `DROP INDEX IF EXISTS` i `ADD UNIQUE KEY
-- IF NOT EXISTS` nad týmž indexem. MariaDB vyhodnotí IF NOT EXISTS proti stavu
-- před příkazem, takže index zahodí a znovu ho nezaloží. Na každé instalaci
-- postavené z migrací proto chybí unikátnost (supplier_id, claim_key), přestože
-- ji 1240 zakládá a 1242 ji chtěla zachovat. Repozitář podle ní dohledává
-- pohledávku k alokaci (storeAllocation), bez indexu jde o sken tabulky.
--
-- claim_key je 'claim_' + 128 bitů z random_bytes a nikde se nekopíruje,
-- duplicity v datech proto nevznikají a přidání unikátního klíče neselže.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_claims
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_enforcement_claim_key
    (supplier_id, claim_key);
