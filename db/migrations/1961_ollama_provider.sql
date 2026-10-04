-- Lokální Ollama jako pátý AI provider (vytěžování PDF + AI návrhy kontací).
-- Adresu a model zadává admin firmy v Nastavení → Integrace → AI brána;
-- API klíč je volitelný (Ollama za reverse proxy s Bearer autentizací).

ALTER TABLE supplier
  MODIFY COLUMN ai_provider ENUM('anthropic','azure_openai','openai','gemini','ollama') NOT NULL DEFAULT 'anthropic';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS ollama_base_url          VARCHAR(255)   NULL,
  ADD COLUMN IF NOT EXISTS ollama_default_model     VARCHAR(128)   NULL,
  ADD COLUMN IF NOT EXISTS ollama_api_key_enc       VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS ollama_extractions_count INT UNSIGNED   NOT NULL DEFAULT 0;
