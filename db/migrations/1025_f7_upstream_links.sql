-- JEDINÉ zásahy do upstream schématu v F7 — izolováno pro merge z upstreamu.
-- Vše IF NOT EXISTS / nullable / defaultované; upstream kód je ignoruje.
SET NAMES utf8mb4;

-- (1) DMS scope: company vs user
ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS scope         ENUM('company','user') NOT NULL DEFAULT 'company',
  ADD COLUMN IF NOT EXISTS owner_user_id BIGINT UNSIGNED NULL;
-- FK guarded (drop-then-add)
ALTER TABLE documents DROP FOREIGN KEY IF EXISTS fk_doc_owner_user;
ALTER TABLE documents
  ADD CONSTRAINT fk_doc_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL;
-- index pro scope guard (aby inner filtr nedegradoval list)
ALTER TABLE documents ADD KEY IF NOT EXISTS idx_doc_scope (supplier_id, scope, owner_user_id);

-- (2) polymorfní link ENUM: append-only 'journal_entry'
--     Re-list VŠECH stávajících členů ověřený proti live DB:
--     enum('client','invoice','purchase_invoice','project') → + 'journal_entry'
ALTER TABLE document_links
  MODIFY COLUMN entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry') NOT NULL;

-- (3) per-tenant AI provider + EU rezidence (anthropic_* NIKDY nepřerábět)
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS ai_provider              ENUM('anthropic','azure_openai','openai','gemini') NOT NULL DEFAULT 'anthropic',
  ADD COLUMN IF NOT EXISTS ai_data_region           ENUM('eu','us') NOT NULL DEFAULT 'us',
  ADD COLUMN IF NOT EXISTS ai_eu_residency_required TINYINT(1)      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS azure_openai_endpoint     VARCHAR(255)   NULL,
  ADD COLUMN IF NOT EXISTS azure_openai_deployment   VARCHAR(128)   NULL,
  ADD COLUMN IF NOT EXISTS azure_openai_api_version  VARCHAR(32)    NULL DEFAULT '2024-10-21',
  ADD COLUMN IF NOT EXISTS azure_openai_api_key_enc  VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS azure_extractions_count   INT UNSIGNED   NOT NULL DEFAULT 0,
  -- OpenAI (přímé API; EU data residency přes eu.api.openai.com / project data-residency)
  ADD COLUMN IF NOT EXISTS openai_base_url           VARCHAR(255)   NULL,
  ADD COLUMN IF NOT EXISTS openai_default_model      VARCHAR(128)   NULL,
  ADD COLUMN IF NOT EXISTS openai_api_key_enc        VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS openai_extractions_count  INT UNSIGNED   NOT NULL DEFAULT 0,
  -- Gemini (Google AI Studio API; EU přes Vertex regional endpoint volbou ai_data_region)
  ADD COLUMN IF NOT EXISTS gemini_default_model      VARCHAR(128)   NULL,
  ADD COLUMN IF NOT EXISTS gemini_api_key_enc        VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS gemini_extractions_count  INT UNSIGNED   NOT NULL DEFAULT 0;
