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

ALTER TABLE cash_documents DROP CONSTRAINT IF EXISTS chk_cashdoc_amount_positive;
ALTER TABLE cash_documents
  ADD CONSTRAINT chk_cashdoc_amount_positive CHECK (total_amount > 0);
