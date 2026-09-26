ALTER TABLE invoices ADD COLUMN IF NOT EXISTS rounding_mode ENUM('auto', 'none', 'whole_czk') NOT NULL DEFAULT 'none' AFTER rounding;
