SET NAMES utf8mb4;

ALTER TABLE other_item_allocations
  DROP FOREIGN KEY IF EXISTS fk_other_allocation_bank,
  DROP FOREIGN KEY IF EXISTS fk_other_allocation_cash;

ALTER TABLE other_item_allocations
  ADD CONSTRAINT fk_other_allocation_bank
    FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_other_allocation_cash
    FOREIGN KEY (cash_document_id) REFERENCES cash_documents(id) ON DELETE CASCADE;
