-- MyÚčto.cz — DB pojistka nad `cash_documents` (audit pokladny 2026-08, L-2)
--
-- Kladná částka dosud držela jen `CashDocumentService::validateDoc()`. Služba je
-- dnes jediná zapisovací cesta, takže se invariant neporušil — jenže pravidlo,
-- které žije jen v PHP, přežije jen do první druhé cesty (import, CLI backfill,
-- ruční SQL zásah při opravě dat). Znaménko pohybu nese `doc_type` (in/out),
-- nikdy částka; záporné `total_amount` by se v knize i v deníku sečetlo obráceně.
--
-- CHECK na kombinaci `purpose` × `doc_type` (zrcadlo `PURPOSE_MATRIX`) tu ZÁMĚRNĚ
-- NENÍ. Matice ve službě je přísnější než zbytek systému: peněžní deník počítá
-- s VRATKOU úhrady přijaté faktury, tedy s `doc_type='in'` a
-- `purpose='purchase_payment'` (viz `TaxExpenseAllocationCalculator` a
-- `CashJournalScenariosTest::testPurchaseRefundDecreasesTaxExpense`). Constraint
-- podle matice by takový řádek zakázal na úrovni DB a zavřel dveře scénáři, který
-- daňová evidence umí vyhodnotit. Buď se sjednotí matice se službou, nebo tenhle
-- CHECK nemá vzniknout — hádat mezi tím na úrovni schématu je to horší z obojího.
--
-- Idempotence: MariaDB neumí `ADD CONSTRAINT IF NOT EXISTS` u CHECK, takže se
-- constraint nejdřív zahodí (`DROP CONSTRAINT IF EXISTS`) a založí znovu.

SET NAMES utf8mb4;

-- MariaDB constraint VALIDUJE existující řádky, takže by migrace na ostrých datech
-- s legacy nulovou/zápornou částkou spadla hláškou o porušeném constraintu a nikdo
-- by z ní nevyčetl, KTERÝCH dokladů se to týká. Data se tu opravovat nesmí (znaménko
-- nese `doc_type`, ne částka — obrátit ho naslepo znamená přehodit příjem na výdej),
-- proto se běh zastaví s adresnou hláškou včetně počtu vadných řádků.
DELIMITER //

DROP PROCEDURE IF EXISTS migrate_cash_amount_guard_1505//

CREATE PROCEDURE migrate_cash_amount_guard_1505()
BEGIN
  DECLARE bad_rows BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO bad_rows FROM cash_documents WHERE total_amount <= 0;

  IF bad_rows > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrace 1505: cash_documents obsahuje doklady s nulovou nebo zápornou částkou. Opravte je (znaménko pohybu nese doc_type, ne total_amount) a migraci spusťte znovu: SELECT id, doc_number, doc_type, total_amount FROM cash_documents WHERE total_amount <= 0;';
  END IF;
END//

CALL migrate_cash_amount_guard_1505()//
DROP PROCEDURE IF EXISTS migrate_cash_amount_guard_1505//

DELIMITER ;

ALTER TABLE cash_documents DROP CONSTRAINT IF EXISTS chk_cashdoc_amount_positive;
ALTER TABLE cash_documents
  ADD CONSTRAINT chk_cashdoc_amount_positive CHECK (total_amount > 0);
