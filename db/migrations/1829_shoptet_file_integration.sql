-- MyÚčto.cz - napojení Shoptetu bez API: import objednávek a dokladů ze souborů
-- nebo z trvalého odkazu exportu, XML feed zásob a cen pro automatický import
-- produktů Shoptetu.
--
-- Objednávky samotné žijí v sales_orders (external_source = 'shoptet',
-- external_id = kód objednávky Shoptetu, UNIQUE drží idempotenci). Tady je jen
-- nastavení firmy, dávky importu a metadata převzatých objednávek (otisk obsahu,
-- snapshot DPH údajů Shoptetu, příznak ruční kontroly).
--
-- Tajemství: odkaz na export objednávek obsahuje hash partnera, proto se ukládá
-- šifrovaně (SecretEncryption s kontextem firmy). Token veřejného feedu se ukládá
-- jen jako SHA-256.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shoptet_settings (
  supplier_id             INT UNSIGNED NOT NULL PRIMARY KEY,
  documents_issuer        ENUM('myucto','shoptet') NOT NULL DEFAULT 'myucto',
  order_url_enc           TEXT NULL,
  order_url_hint          VARCHAR(160) NULL,
  auto_fetch              TINYINT(1) NOT NULL DEFAULT 0,
  fetch_interval_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  fetch_cursor            DATETIME NULL,
  last_fetch_at           DATETIME NULL,
  last_full_fetch_at      DATETIME NULL,
  last_fetch_status       ENUM('ok','error') NULL,
  last_fetch_message      VARCHAR(255) NULL,
  default_warehouse_id    BIGINT UNSIGNED NULL,
  confirm_orders          TINYINT(1) NOT NULL DEFAULT 0,
  feed_token_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  feed_token_created_at   DATETIME NULL,
  feed_warehouse_id       BIGINT UNSIGNED NULL,
  feed_include_price      TINYINT(1) NOT NULL DEFAULT 1,
  feed_scope              ENUM('eshop','active') NOT NULL DEFAULT 'eshop',
  feed_etag               CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  feed_changed_at         DATETIME NULL,
  feed_last_served_at     DATETIME NULL,
  updated_by              BIGINT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shoptet_feed_token (feed_token_hash),
  KEY ix_shoptet_auto_fetch (auto_fetch, last_fetch_at),
  CONSTRAINT fk_shoptet_settings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_shoptet_settings_default_wh FOREIGN KEY (default_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  CONSTRAINT fk_shoptet_settings_feed_wh FOREIGN KEY (feed_warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  CONSTRAINT fk_shoptet_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shoptet_import_batches (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  kind              ENUM('orders','documents') NOT NULL,
  source            ENUM('upload','url') NOT NULL,
  status            ENUM('preview','applied','discarded','erased') NOT NULL DEFAULT 'preview',
  file_name         VARCHAR(255) NULL,
  payload           LONGBLOB NULL,
  payload_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  update_time_from  DATETIME NULL,
  fetched_at        DATETIME NULL,
  summary_json      LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(summary_json)),
  report_json       LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(report_json)),
  invoice_batch_id  VARCHAR(32) NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at        DATETIME NULL,
  erased_at         DATETIME NULL,
  KEY ix_shoptet_batches_list (supplier_id, kind, created_at),
  KEY ix_shoptet_batches_status (status, created_at),
  CONSTRAINT fk_shoptet_batches_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_shoptet_batches_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shoptet_orders (
  order_id                BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  shoptet_code            VARCHAR(100) NOT NULL,
  created_batch_id        BIGINT UNSIGNED NULL,
  last_batch_id           BIGINT UNSIGNED NULL,
  content_hash            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  shoptet_status          VARCHAR(120) NULL,
  shoptet_total_with_vat  DECIMAL(15,2) NULL,
  vat_snapshot            LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(vat_snapshot)),
  review_required         TINYINT(1) NOT NULL DEFAULT 0,
  review_reasons          LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(review_reasons)),
  reviewed_by             BIGINT UNSIGNED NULL,
  reviewed_at             DATETIME NULL,
  pending_change          TINYINT(1) NOT NULL DEFAULT 0,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shoptet_orders_code (supplier_id, shoptet_code),
  KEY ix_shoptet_orders_review (supplier_id, review_required),
  KEY ix_shoptet_orders_batch (supplier_id, created_batch_id),
  CONSTRAINT fk_shoptet_orders_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_shoptet_orders_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_shoptet_orders_created_batch FOREIGN KEY (created_batch_id) REFERENCES shoptet_import_batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_shoptet_orders_last_batch FOREIGN KEY (last_batch_id) REFERENCES shoptet_import_batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_shoptet_orders_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
