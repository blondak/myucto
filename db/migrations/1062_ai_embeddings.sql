-- MyÚčto.cz — E8: tenantově izolované embeddingy účetních rozhodnutí
-- Vyžaduje MariaDB 11.8+ stejně jako migrace 1026_document_embeddings.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_embeddings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  entity_type ENUM('bank_transaction','purchase_invoice','invoice') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  content_hash CHAR(64) NOT NULL,
  sanitized_text VARCHAR(2000) NOT NULL,
  embedding VECTOR(1536) NOT NULL,
  label_debit VARCHAR(10) NULL,
  label_credit VARCHAR(10) NULL,
  label_source ENUM('approved','corrected','manual') NOT NULL,
  embed_provider VARCHAR(32) NOT NULL,
  embed_model VARCHAR(64) NOT NULL,
  embed_region ENUM('us','eu') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aie_entity (supplier_id, entity_type, entity_id),
  KEY idx_aie_supplier (supplier_id, entity_type),
  VECTOR INDEX idx_aie_embedding (embedding) DISTANCE=cosine,
  CONSTRAINT fk_aie_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM document_embeddings WHERE embedding IS NULL;
ALTER TABLE document_embeddings
  MODIFY COLUMN embedding VECTOR(1536) NOT NULL;
ALTER TABLE document_embeddings
  ADD VECTOR INDEX IF NOT EXISTS idx_de_embedding (embedding) DISTANCE=cosine;
