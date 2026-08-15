-- MyÚčto.cz — zaměstnavatelské pravidlo, podle kterého se ještě nic nespočítalo,
-- musí jít smazat.
--
-- Migrace 1054 dala tabulce `payroll_employer_policies` trigger, který mazání
-- zakazoval BEZVÝHRADNĚ („policies are retained for audit"). Praktický důsledek:
-- verze pravidla s budoucí platností, kterou někdo založil s překlepem, zůstala
-- v přehledu navždy a jediné, co s ní šlo udělat, bylo ukončit jí platnost —
-- takže v seznamu svítily dvě verze, z nichž jedna nikdy nic neřídila.
--
-- Vodicí princip mzdového mazání: blokovat smí VÝHRADNĚ důkaz pohybu — vnější
-- úkon vůči úřadu, schválený výpočet, nebo peníze. U pravidla je tím důkazem
-- MZDOVÝ BĚH v jeho platnosti: pravidlo určuje výplatní termín, zaokrouhlení
-- salda a čtyři oči, takže jakmile v jeho intervalu existuje běh, počítalo se
-- podle něj. Bez běhu se podle pravidla nespočítalo nic a není co chránit.
--
-- Řeší se to dvěma změnami:
--
--   1. Trigger se přepíše z bezvýhradného zákazu na PODMÍNĚNOU obranu, stejného
--      tvaru jako už schválený `trg_payroll_dimension_delete_guard`. Databáze
--      tak drží pravidlo i pro cesty mimo aplikaci (ruční SQL, import).
--
--   2. Cizí klíč z `payroll_employer_policy_audit` se mění z RESTRICT na
--      ON DELETE CASCADE. Auditní řádky jsou snapshoty „založeno / upraveno"
--      TOHO pravidla — nejsou důkaz pohybu, jsou jeho vlastní historie, a bez
--      pravidla nemají na co ukazovat. Append-only trigger nad auditem ZŮSTÁVÁ:
--      přímé `DELETE FROM payroll_employer_policy_audit` je dál zakázané a FK
--      kaskáda triggery nespouští, takže se obě pravidla nebijí.
--
-- Fakt, že pravidlo existovalo, kdo ho smazal a s jakým obsahem, zůstává
-- v `activity_log` (`payroll.employer_policy.deleted`) — auditní stopa se tedy
-- neztrácí, jen se přesouvá tam, kde patří.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Auditní řádky pravidla mizí spolu s pravidlem
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE payroll_employer_policy_audit
  DROP FOREIGN KEY fk_payroll_employer_policy_audit_policy;

ALTER TABLE payroll_employer_policy_audit
  ADD CONSTRAINT fk_payroll_employer_policy_audit_policy
  FOREIGN KEY (supplier_id, policy_id)
  REFERENCES payroll_employer_policies (supplier_id, id)
  ON DELETE CASCADE;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Trigger — přepis celého těla (MariaDB neumí ALTER TRIGGER)
-- ─────────────────────────────────────────────────────────────────────────────
DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_delete//
CREATE TRIGGER trg_payroll_employer_policy_delete
BEFORE DELETE ON payroll_employer_policies
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_runs run
     WHERE run.supplier_id = OLD.supplier_id
       AND run.period_start >= OLD.valid_from
       AND run.period_start <= COALESCE(OLD.valid_to, '9999-12-31')
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy used by a payroll run cannot be deleted';
  END IF;
END//

DELIMITER ;
