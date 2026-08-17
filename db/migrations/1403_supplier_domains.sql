-- MyÚčto.cz — vlastní domény klientských portálů a veřejných odkazů (#11).
--
-- Doména je bezpečnostní hranice tenanta, ne pouze branding. Aktivní hostname
-- proto musí být globálně unikátní a primární doména pro každý účel je hlídaná
-- databází. Jednorázové domain-login requesty přenášejí na vlastní host pouze
-- krátkodobý kód svázaný s PKCE; session token se do URL nikdy neukládá.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_domains (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  hostname                   VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose                    ENUM('portal','public_links','all') NOT NULL DEFAULT 'all',
  status                     ENUM('pending','verified','active','disabled','verification_failed')
                             NOT NULL DEFAULT 'pending',
  is_primary_portal          TINYINT(1) NOT NULL DEFAULT 0,
  is_primary_public          TINYINT(1) NOT NULL DEFAULT 0,
  is_primary                 TINYINT(1) GENERATED ALWAYS AS (
    is_primary_portal = 1 OR is_primary_public = 1
  ) PERSISTENT,
  verification_token         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  verified_at                TIMESTAMP(6) NULL,
  last_checked_at            TIMESTAMP(6) NULL,
  verification_error         VARCHAR(500) NULL,
  created_by                 BIGINT UNSIGNED NULL,
  updated_by                 BIGINT UNSIGNED NULL,
  created_at                 TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at                 TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                               ON UPDATE CURRENT_TIMESTAMP(6),

  -- NULL hodnoty se v UNIQUE indexu neopakují. Díky tomu lze mít libovolný
  -- počet aliasů, ale nejvýše jednu AKTIVNÍ primární doménu pro každý účel.
  primary_portal_supplier_id INT UNSIGNED GENERATED ALWAYS AS (
    CASE
      WHEN status = 'active' AND is_primary_portal = 1
        THEN supplier_id
      ELSE NULL
    END
  ) PERSISTENT,
  primary_public_supplier_id INT UNSIGNED GENERATED ALWAYS AS (
    CASE
      WHEN status = 'active' AND is_primary_public = 1
        THEN supplier_id
      ELSE NULL
    END
  ) PERSISTENT,

  UNIQUE KEY uq_supplier_domains_hostname (hostname),
  UNIQUE KEY uq_supplier_domains_supplier_id_id (supplier_id, id),
  UNIQUE KEY uq_supplier_domains_primary_portal (primary_portal_supplier_id),
  UNIQUE KEY uq_supplier_domains_primary_public (primary_public_supplier_id),
  KEY idx_supplier_domains_supplier_status (supplier_id, status, purpose),
  KEY fk_supplier_domains_created_by (created_by),
  KEY fk_supplier_domains_updated_by (updated_by),

  CONSTRAINT fk_supplier_domains_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_domains_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_supplier_domains_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_supplier_domains_primary_portal CHECK (
    is_primary_portal IN (0, 1)
    AND (is_primary_portal = 0 OR purpose IN ('portal','all'))
  ),
  CONSTRAINT chk_supplier_domains_primary_public CHECK (
    is_primary_public IN (0, 1)
    AND (is_primary_public = 0 OR purpose IN ('public_links','all'))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_domain_login_requests (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_token_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_domain_id         BIGINT UNSIGNED NOT NULL,
  supplier_id                INT UNSIGNED NOT NULL,
  target_hostname            VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state_hash                 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  pkce_challenge             CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  return_path                VARCHAR(500) NOT NULL DEFAULT '/portal',
  expires_at                 TIMESTAMP(6) NOT NULL,

  authorized_by              BIGINT UNSIGNED NULL,
  authorization_code_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  code_expires_at            TIMESTAMP(6) NULL,
  auth_method                VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  assurance_level            ENUM('legacy','basic','strong','setup') NULL,
  mfa_verified_at            TIMESTAMP(6) NULL,
  auth_credential_id         BIGINT UNSIGNED NULL,
  used_at                    TIMESTAMP(6) NULL,

  created_ip                 VARBINARY(16) NULL,
  created_user_agent         VARCHAR(255) NULL,
  created_at                 TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at                 TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                               ON UPDATE CURRENT_TIMESTAMP(6),

  UNIQUE KEY uq_supplier_domain_login_request_token (request_token_hash),
  UNIQUE KEY uq_supplier_domain_login_authorization_code (authorization_code_hash),
  KEY idx_supplier_domain_login_expiry (expires_at, code_expires_at, used_at),
  KEY idx_supplier_domain_login_domain (supplier_domain_id, created_at),
  KEY fk_supplier_domain_login_supplier (supplier_id),
  KEY fk_supplier_domain_login_user (authorized_by),
  KEY fk_supplier_domain_login_credential (auth_credential_id),

  CONSTRAINT fk_supplier_domain_login_domain
    FOREIGN KEY (supplier_id, supplier_domain_id)
    REFERENCES supplier_domains (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_domain_login_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_domain_login_user
    FOREIGN KEY (authorized_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_supplier_domain_login_credential
    FOREIGN KEY (auth_credential_id) REFERENCES webauthn_credentials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
