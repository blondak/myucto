-- MyÚčto.cz — Epic F5: ukládané filtry a per-user preference tabulek
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS saved_filters (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED    NOT NULL COMMENT 'payload nese supplier-specifická ID (klient, projekt, účet)',
  page_key    VARCHAR(50)     NOT NULL COMMENT 'whitelist na BE: invoices, purchase_invoices, journal, ...',
  name        VARCHAR(100)    NOT NULL,
  payload     JSON            NOT NULL COMMENT 'plochý Record<string,string> z buildQuery(); BE neinterpretuje, jen validuje',
  is_default  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'max 1 per (user,supplier,page_key) — vynucuje Action transakčně',
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sf_user_supplier_page_name (user_id, supplier_id, page_key, name),
  KEY idx_sf_lookup (user_id, supplier_id, page_key, sort_order),
  CONSTRAINT fk_sf_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_sf_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_preferences (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  pref_key   VARCHAR(80)     NOT NULL COMMENT 'table.<page_key> — prefix whitelist na BE',
  payload    JSON            NOT NULL COMMENT 'opaque: {hidden:[...], density:"compact", sort:{key,dir}}',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_up_user_key (user_id, pref_key),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
