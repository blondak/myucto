ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS import_projection_vat_czk DECIMAL(12,2) NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS import_tax_sign TINYINT NULL;
