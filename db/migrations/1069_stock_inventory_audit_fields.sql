-- MyÚčto.cz — EP-9: průkazný inventurní soupis a ocenění přebytků.

SET NAMES utf8mb4;

ALTER TABLE stock_takes
  ADD COLUMN IF NOT EXISTS counting_method VARCHAR(100) NULL AFTER note,
  ADD COLUMN IF NOT EXISTS responsible_count_name VARCHAR(255) NULL AFTER counting_method,
  ADD COLUMN IF NOT EXISTS responsible_inventory_name VARCHAR(255) NULL AFTER responsible_count_name,
  ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER responsible_inventory_name;

ALTER TABLE stock_take_lines
  ADD COLUMN IF NOT EXISTS surplus_unit_cost DECIMAL(15,6) NULL
    COMMENT 'Reprodukční pořizovací cena inventurního přebytku dle §25/1/l ZoÚ'
    AFTER counted_qty;
