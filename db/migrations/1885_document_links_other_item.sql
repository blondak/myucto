SET NAMES utf8mb4;

ALTER TABLE document_links
  MODIFY COLUMN entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry','bank_transaction','cash_document','other_item') NOT NULL;

DELIMITER //

DROP TRIGGER IF EXISTS trg_document_links_other_item_insert//

CREATE TRIGGER trg_document_links_other_item_insert
BEFORE INSERT ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'other_item' AND NOT EXISTS (
    SELECT 1 FROM other_items oi
     WHERE oi.id = NEW.entity_id
       AND oi.supplier_id = NEW.supplier_id
       AND oi.deleted_at IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link other item does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_document_links_other_item_update//

CREATE TRIGGER trg_document_links_other_item_update
BEFORE UPDATE ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'other_item' AND NOT EXISTS (
    SELECT 1 FROM other_items oi
     WHERE oi.id = NEW.entity_id
       AND oi.supplier_id = NEW.supplier_id
       AND oi.deleted_at IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link other item does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_other_items_drop_document_links//

CREATE TRIGGER trg_other_items_drop_document_links
AFTER DELETE ON other_items
FOR EACH ROW
BEGIN
  DELETE FROM document_links
   WHERE supplier_id = OLD.supplier_id
     AND entity_type = 'other_item'
     AND entity_id = OLD.id;
END//

DROP TRIGGER IF EXISTS trg_other_items_soft_delete_document_links//

CREATE TRIGGER trg_other_items_soft_delete_document_links
AFTER UPDATE ON other_items
FOR EACH ROW
BEGIN
  IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
    DELETE FROM document_links
     WHERE supplier_id = OLD.supplier_id
       AND entity_type = 'other_item'
       AND entity_id = OLD.id;
  END IF;
END//

DELIMITER ;
