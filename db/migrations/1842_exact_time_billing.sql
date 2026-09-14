-- Přesná minutová délka je opt-in. NULL zachovává výpočet všech historických řádků
-- z původních desetinných hodin nebo množství beze změny.

ALTER TABLE supplier
  MODIFY COLUMN default_hourly_rate DECIMAL(14,6) NOT NULL DEFAULT 1500.000000;

ALTER TABLE clients
  MODIFY COLUMN hourly_rate DECIMAL(14,6) NOT NULL DEFAULT 0.000000;

ALTER TABLE projects
  MODIFY COLUMN hourly_rate DECIMAL(14,6) NOT NULL DEFAULT 1500.000000;

ALTER TABLE work_report_items
  MODIFY COLUMN rate DECIMAL(14,6) NOT NULL,
  ADD COLUMN IF NOT EXISTS duration_minutes INT NULL AFTER hours;

ALTER TABLE invoice_items
  MODIFY COLUMN unit_price_without_vat DECIMAL(16,6) NOT NULL,
  ADD COLUMN IF NOT EXISTS duration_minutes INT NULL AFTER quantity;

ALTER TABLE purchase_invoice_items
  MODIFY COLUMN unit_price_without_vat DECIMAL(16,6) NOT NULL,
  ADD COLUMN IF NOT EXISTS duration_minutes INT NULL AFTER quantity;

ALTER TABLE recurring_invoice_template_items
  MODIFY COLUMN unit_price_without_vat DECIMAL(16,6) NOT NULL,
  ADD COLUMN IF NOT EXISTS duration_minutes INT NULL AFTER quantity;
