SET NAMES utf8mb4;

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS audience ENUM('api','mcp') NOT NULL DEFAULT 'api' AFTER scope;

CREATE TABLE IF NOT EXISTS mcp_server_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mcp_server_settings (id, enabled) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
  client_id VARCHAR(64) NOT NULL PRIMARY KEY,
  client_name VARCHAR(100) NOT NULL,
  redirect_uris JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
  code_hash CHAR(64) NOT NULL PRIMARY KEY,
  client_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  redirect_uri VARCHAR(2048) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  scope ENUM('read','read_write') NOT NULL,
  resource VARCHAR(2048) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mcp_codes_expiry (expires_at),
  CONSTRAINT fk_mcp_code_client FOREIGN KEY (client_id) REFERENCES mcp_oauth_clients(client_id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_code_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_code_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_oauth_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  scope ENUM('read','read_write') NOT NULL,
  resource VARCHAR(2048) NOT NULL DEFAULT '',
  refresh_hash CHAR(64) NOT NULL,
  access_token_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mcp_refresh_hash (refresh_hash),
  KEY idx_mcp_grant_user (user_id, revoked_at),
  KEY idx_mcp_grant_expiry (expires_at),
  CONSTRAINT fk_mcp_grant_client FOREIGN KEY (client_id) REFERENCES mcp_oauth_clients(client_id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_grant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_grant_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_grant_access FOREIGN KEY (access_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mcp_oauth_grants ADD COLUMN IF NOT EXISTS resource VARCHAR(2048) NOT NULL DEFAULT '' AFTER scope;
