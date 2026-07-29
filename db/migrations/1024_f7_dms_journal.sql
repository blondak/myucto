SET NAMES utf8mb4;

-- Epic F7 — fork-owned nové tabulky DMS/účetní deník + idempotentní backfill.
-- Typová pravidla (§2.0): supplier_id = INT UNSIGNED FK→supplier(id);
-- document_id/entry_id/uploaded_by = BIGINT UNSIGNED (PK cílových tabulek).
-- Odchylka: document_files.size_bytes = BIGINT UNSIGNED (ne INT) — bezeztrátově
-- odpovídá documents.size_bytes (BIGINT UNSIGNED).

-- (A) N souborů na DMS dokument
CREATE TABLE IF NOT EXISTS document_files (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_id    BIGINT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED    NOT NULL,               -- denormalizace pro tenant filtr
  role           ENUM('primary','attachment') NOT NULL DEFAULT 'attachment',
  sha256         CHAR(64)        NOT NULL,
  filename       VARCHAR(255)    NOT NULL,                -- on-disk jméno (= sha256)
  original_name  VARCHAR(255)    NULL,
  mime_type      VARCHAR(100)    NULL,
  size_bytes     BIGINT UNSIGNED NULL,                    -- odchylka: BIGINT (match documents.size_bytes)
  doc_type       ENUM('pdf','docx','xlsx','xml','zfo','p7s','zip','image','other') NULL,
  sort_order     INT UNSIGNED    NOT NULL DEFAULT 0,
  uploaded_by    BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at     TIMESTAMP       NULL,
  UNIQUE KEY uq_df_doc_sha (document_id, sha256),         -- per-dokument dedup
  KEY idx_df_document (document_id, role, sort_order),
  KEY idx_df_supplier_sha (supplier_id, sha256),          -- ref-counting nad soubory
  CONSTRAINT fk_df_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_df_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)  ON DELETE CASCADE,
  CONSTRAINT fk_df_user     FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (B) přílohy účetního zápisu (§33a) — VLASTNÍ disk namespace, ne DMS
CREATE TABLE IF NOT EXISTS journal_entry_attachments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entry_id       BIGINT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED    NOT NULL,
  sha256         CHAR(64)        NOT NULL,
  filename       VARCHAR(255)    NOT NULL,                -- {sha8}-{safeName}
  original_name  VARCHAR(255)    NULL,
  mime_type      VARCHAR(100)    NULL,
  size_bytes     INT UNSIGNED    NULL,
  doc_type       ENUM('pdf','image','xml','isdoc','zfo','other') NULL,
  description    VARCHAR(255)    NULL,                    -- §33a popisek, inline editovatelný
  uploaded_by    BIGINT UNSIGNED NULL,
  uploaded_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jea_entry (entry_id, uploaded_at),
  KEY idx_jea_supplier (supplier_id),
  CONSTRAINT fk_jea_entry    FOREIGN KEY (entry_id)    REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_jea_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)        ON DELETE CASCADE,
  CONSTRAINT fk_jea_user     FOREIGN KEY (uploaded_by) REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotentní backfill: každý existující documents řádek → primary document_files řádek
INSERT INTO document_files
  (document_id, supplier_id, role, sha256, filename, original_name, mime_type, size_bytes, doc_type, uploaded_by, created_at)
SELECT d.id, d.supplier_id, 'primary', d.sha256, d.filename, d.original_name, d.mime_type,
       d.size_bytes, d.doc_type, d.uploaded_by, d.created_at
FROM documents d
WHERE d.deleted_at IS NULL
  AND d.sha256 IS NOT NULL
  -- Idempotence vůči uq_df_doc_sha (document_id, sha256): přeskoč, pokud pro dvojici
  -- (document_id, sha256) UŽ existuje JAKÝKOLIV řádek (i attachment) — jinak by re-run
  -- porušil unique key, když má dokument přílohu se sha shodnou s primary, ale bez primary řádku.
  AND NOT EXISTS (SELECT 1 FROM document_files f WHERE f.document_id = d.id AND f.sha256 = d.sha256);
