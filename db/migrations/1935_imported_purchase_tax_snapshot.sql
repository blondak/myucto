ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS import_tax_base_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_tax_vat_czk DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS import_tax_excluded TINYINT(1) NOT NULL DEFAULT 0;
