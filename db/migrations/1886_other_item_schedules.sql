CREATE TABLE IF NOT EXISTS other_item_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  source_item_id BIGINT UNSIGNED NULL,
  frequency ENUM('monthly','quarterly','yearly') NOT NULL,
  anchor_on DATE NOT NULL,
  due_days SMALLINT UNSIGNED NOT NULL,
  ends_on DATE NULL,
  next_index INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  template_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_other_item_schedule_due (supplier_id, status, anchor_on),
  CONSTRAINT fk_other_schedule_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_schedule_source FOREIGN KEY (source_item_id) REFERENCES other_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_schedule_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS other_item_schedule_occurrences (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  schedule_id BIGINT UNSIGNED NOT NULL,
  occurrence_index INT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_other_occurrence (schedule_id, occurrence_index),
  UNIQUE KEY uq_other_occurrence_item (item_id),
  KEY idx_other_occurrence_supplier (supplier_id, schedule_id),
  CONSTRAINT fk_other_occurrence_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_occurrence_schedule FOREIGN KEY (schedule_id) REFERENCES other_item_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_occurrence_item FOREIGN KEY (item_id) REFERENCES other_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS other_item_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  other_item_id BIGINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  due_on DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  UNIQUE KEY uq_other_installment_position (other_item_id, position),
  KEY idx_other_installment_due (supplier_id, due_on),
  CONSTRAINT fk_other_installment_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_installment_item FOREIGN KEY (other_item_id) REFERENCES other_items(id) ON DELETE CASCADE,
  CONSTRAINT chk_other_installment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
