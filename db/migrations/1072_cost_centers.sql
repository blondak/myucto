-- Firemní číselník středisek pro analytické členění řádků účetního deníku.
-- Řádky deníku dál ukládají textový kód (bez FK), aby historické zápisy zůstaly
-- čitelné i po deaktivaci střediska. Existující hodnoty se při migraci převezmou.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cost_centers (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code        VARCHAR(50) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_cost_centers_supplier_code (supplier_id, code),
  KEY idx_cost_centers_supplier_active (supplier_id, is_active, code),
  CONSTRAINT fk_cost_centers_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cost_centers (supplier_id, code, name)
SELECT DISTINCT supplier_id, TRIM(cost_center), TRIM(cost_center)
  FROM journal_entry_lines
 WHERE cost_center IS NOT NULL AND TRIM(cost_center) <> '';

INSERT IGNORE INTO cost_centers (supplier_id, code, name)
SELECT DISTINCT t.supplier_id, TRIM(l.cost_center), TRIM(l.cost_center)
  FROM journal_entry_template_lines l
  JOIN journal_entry_templates t ON t.id = l.template_id
 WHERE l.cost_center IS NOT NULL AND TRIM(l.cost_center) <> '';
