-- Původní identity zaniklých pohybů nejsou živé reference používané pravidlem.
ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS archived_rejected_transactions JSON NULL;
