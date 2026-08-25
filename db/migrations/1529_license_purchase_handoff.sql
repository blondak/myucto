-- MyÚčto.cz — krátkodobý PKCE kontext automatické aktivace po nákupu.
--
-- Prohlížeč dostane po zaplacení jen jednorázový token objednávky a state.
-- Licenční klíč ani PKCE verifier se do URL nikdy nedostanou; verifier zůstává
-- na instalaci a po úspěšném claimu se spolu se state a expirací smaže.

SET NAMES utf8mb4;

ALTER TABLE license
  ADD COLUMN IF NOT EXISTS purchase_handoff_state_hash CHAR(64) NULL
    COMMENT 'SHA-256 callback state; NULL = žádný rozpracovaný nákup'
    AFTER last_check_ok,
  ADD COLUMN IF NOT EXISTS purchase_handoff_verifier CHAR(43) NULL
    COMMENT 'krátkodobý PKCE verifier; nikdy neopouští server kromě claimu'
    AFTER purchase_handoff_state_hash,
  ADD COLUMN IF NOT EXISTS purchase_handoff_expires_at DATETIME NULL
    COMMENT 'lokální expirace rozpracovaného purchase handoffu'
    AFTER purchase_handoff_verifier;
