ALTER TABLE other_items ADD INDEX IF NOT EXISTS idx_other_item_tenant_identity (id, supplier_id);

CREATE TABLE IF NOT EXISTS other_item_posting_lines (
  supplier_id INT UNSIGNED NOT NULL,
  other_item_id BIGINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  account_code VARCHAR(20) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (supplier_id, other_item_id, position),
  KEY idx_other_item_posting_item (other_item_id),
  CONSTRAINT fk_other_item_posting_item FOREIGN KEY (other_item_id, supplier_id)
    REFERENCES other_items(id, supplier_id) ON DELETE CASCADE,
  CONSTRAINT chk_other_item_posting_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
