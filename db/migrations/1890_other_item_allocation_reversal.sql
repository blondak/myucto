ALTER TABLE other_item_allocations
  ADD COLUMN IF NOT EXISTS reversed_on DATE NULL AFTER payment_on,
  ADD INDEX IF NOT EXISTS idx_other_allocation_active (supplier_id, other_item_id, reversed_on);
