-- 1173: IP omezení API tokenů + per-request log volání veřejného API (MCP server)
--
-- ── api_token_ips ───────────────────────────────────────────────────────────────
-- Volitelný allowlist zdrojových adres pro konkrétní token. PRÁZDNÝ SEZNAM = BEZ
-- OMEZENÍ (token funguje odkudkoliv) — jinak by migrace tiše zamkla všechny už
-- vydané tokeny. Pravidlo se ukládá jako TEXT v CIDR notaci, protože musí pokrýt
-- obě rodiny i rozsahy: "1.2.3.4", "192.168.1.0/24", "2001:db8::1", "2001:db8::/32".
-- Matchování dělá existující `IpMatcher` (normalizuje i IPv4-mapped IPv6), takže
-- tady se drží jen zdrojový zápis pravidla — ne rozbalený rozsah.
--
-- ── api_request_log ─────────────────────────────────────────────────────────────
-- Doteď byla jediná stopa po použití tokenu `api_tokens.last_used_at`, navíc
-- throttlovaná na 5 minut. Nešlo tedy zjistit, CO se přes token volalo — což je
-- u MCP serveru (agent volá API sám za uživatele) ta podstatná informace.
--
-- Logujeme JEN bearer requesty; browserová session má vlastní `activity_log` a
-- zdvojení by tabulku zbytečně nafouklo.
--
-- `client` / `client_version` / `tool` plní MCP server hlavičkami X-MyUcto-Client,
-- X-MyUcto-Client-Version a X-MyUcto-Tool. Díky `tool` je ve výpisu vidět nástroj,
-- který volání vyvolal (`list_unpaid_invoices`), ne jen holá cesta.
--
-- Retence: log čistí `cron-cleanup.php` (viz api_request_log v jeho výčtu), proto
-- je `ts` indexované samostatně.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_token_ips (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_id    BIGINT UNSIGNED NOT NULL,
  cidr        VARCHAR(64) NOT NULL COMMENT 'IPv4/IPv6 adresa nebo CIDR rozsah',
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_apitokip_token (token_id),
  UNIQUE KEY uq_apitokip_token_cidr (token_id, cidr),
  CONSTRAINT fk_apitokip_token FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Volitelný IP allowlist API tokenu; prázdno = bez omezení';

CREATE TABLE IF NOT EXISTS api_request_log (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_id       BIGINT UNSIGNED NULL COMMENT 'NULL = token mezitím smazán',
  user_id        BIGINT UNSIGNED NULL,
  supplier_id    TINYINT UNSIGNED NULL,
  ts             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ip             VARBINARY(16) NULL,
  method         VARCHAR(10) NOT NULL,
  route          VARCHAR(255) NOT NULL,
  query          VARCHAR(512) NOT NULL DEFAULT '',
  status         SMALLINT UNSIGNED NOT NULL,
  duration_ms    INT UNSIGNED NOT NULL DEFAULT 0,
  scope_used     VARCHAR(16) NOT NULL DEFAULT '',
  client         VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'X-MyUcto-Client, např. "mcp"',
  client_version VARCHAR(32) NOT NULL DEFAULT '',
  tool           VARCHAR(96) NOT NULL DEFAULT '' COMMENT 'X-MyUcto-Tool — název MCP nástroje',
  error_code     VARCHAR(64) NOT NULL DEFAULT '',
  KEY idx_apireqlog_ts (ts),
  KEY idx_apireqlog_token_ts (token_id, ts),
  KEY idx_apireqlog_user_ts (user_id, ts),
  CONSTRAINT fk_apireqlog_token FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-request log volání veřejného API bearer tokenem (vč. MCP serveru)';
