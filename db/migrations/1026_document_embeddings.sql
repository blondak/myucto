-- Forward-ready DMS embeddings — POSLEDNÍ a oddělitelná migrace.
-- Vyžaduje MariaDB 11.8+ (nativní VECTOR). Server ověřen: 11.8.8-MariaDB.
-- Sémantické vyhledávání NENÍ v F7 implementováno; tabulka + design jen forward-ready.
-- VECTOR INDEX je ZÁMĚRNĚ zakomentovaný (deferred, až se implementuje search).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS document_embeddings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED    NOT NULL,
  document_id       BIGINT UNSIGNED NOT NULL,
  document_file_id  BIGINT UNSIGNED NULL,
  chunk_no          INT UNSIGNED    NOT NULL DEFAULT 0,
  content_chunk     MEDIUMTEXT      NULL,
  embedding         VECTOR(1536)    NULL,                 -- MariaDB 11.8 native
  embed_provider    VARCHAR(32)     NULL,
  embed_model       VARCHAR(64)     NULL,
  embed_region      ENUM('us','eu') NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_de_doc (supplier_id, document_id, chunk_no),
  -- FK názvy fk_demb_* (ne fk_de_*): fk_de_supplier je schema-globálně obsazen
  -- migrací 1014 na depreciation_entries; InnoDB constraint names musí být unikátní.
  CONSTRAINT fk_demb_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_demb_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)  ON DELETE CASCADE
  -- , VECTOR INDEX idx_de_embedding (embedding) DISTANCE=cosine   -- DEFERRED: až se implementuje search
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
