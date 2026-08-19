-- Odesílací brána ISDS — relace jednoho konceptu.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč to potřebuje vlastní tabulku a ne PHP session
-- ─────────────────────────────────────────────────────────────────────────────
-- Odeslání přes bránu není jedno volání, ale tři HTTP kola s uživatelem
-- uprostřed:
--   1. my  → ISDS   přesměrování na `/as/login?atsId=…&appToken=…`
--   2. ISDS → my    návrat s `sessionId`; my z něj `GetCredential` → timeLimitedId
--                   a `SetConcept` → dmID KONCEPTU
--   3. my  → ISDS   přesměrování na `/as/koncept/view?konceptId=…`
--   4. ISDS → my    návrat s novým `sessionId`; `GetCredential` → conceptDmId,
--                   conceptStatusCode, conceptStatusMessage
--
-- Mezi 2 a 4 může uživatel zavřít prohlížeč, přijít z jiného zařízení nebo
-- kliknout dvakrát. Stav proto musí být v databázi, kde se dá zamknout
-- podmíněným UPDATE — ne v session, kde se dá závodit.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Multi-tenant izolace
-- ─────────────────────────────────────────────────────────────────────────────
-- `app_token` přijde zpátky přesměrováním z prohlížeče, tedy z místa, které
-- neřídíme. NIKDY nesmí sám o sobě nic autorizovat. Řádek proto nese
-- `supplier_id` i `user_id` a služba je porovnává s přihlášenou relací; token
-- je jen vyhledávací klíč, ne oprávnění. FK na `submission_outbox` je
-- kompozitní přes `(supplier_id, outbox_id)`, takže databáze relaci jedné firmy
-- k podání druhé nepustí ani při chybě v aplikaci.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč `active_outbox_id` a ne prostý UNIQUE
-- ─────────────────────────────────────────────────────────────────────────────
-- Jedno podání smí mít najednou nejvýš JEDNU rozpracovanou relaci — jinak by
-- dvojí kliknutí vložilo do ISDS dva koncepty téhož podání a uživatel by je
-- mohl schválit oba. MariaDB nemá částečné indexy, takže se používá generovaný
-- sloupec, který je mimo živé stavy NULL (a NULL se v UNIQUE neporovnává).
-- ISDS má vlastní obdobné omezení (kap. 3.1 bod 6: nejvýš 3 rozpracované
-- koncepty na uživatele a bránu), ale spoléhat se na cizí stranu tady nelze:
-- do té doby už by naše podání bylo ve stavu, o kterém nevíme.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS isds_gateway_sessions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  outbox_id             BIGINT UNSIGNED NOT NULL,
  -- Kdo přesměrování zahájil. Dokončit ho smí jen tentýž člověk: schválení
  -- v ISDS je právní úkon a musí být dohledatelné, kdo ho vyvolal.
  user_id               BIGINT UNSIGNED NOT NULL,

  -- `appToken` podle kap. 2.6: „Tento parametr obsahuje maximálně 20 číslic."
  -- Ne API klíč, ne heslo — jen náš vlastní identifikátor, který ISDS vrátí
  -- zpět. Že je to jen číslice, hlídá CHECK níž.
  app_token             VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

  state                 ENUM(
    'awaiting_login',     -- uživatel odešel na /as/login, čekáme na sessionId
    'awaiting_approval',  -- koncept je v ISDS, uživatel ho schvaluje
    'approved',           -- ISDS potvrdil odeslání, známe dmID zprávy
    'rejected',           -- uživatel koncept zamítl (kód 2305) — nic neodešlo
    'failed',             -- brána odmítla prokazatelně, nic neodešlo
    'uncertain',          -- volání se přerušilo; NEVÍME, jestli koncept vznikl/odešel
    'expired'             -- vypršelo, aniž se uživatel vrátil
  ) NOT NULL DEFAULT 'awaiting_login',

  -- dmID KONCEPTU ze `SetConceptResponse` (kap. 3.4). Není to ID odeslané
  -- zprávy — tím je až `concept_dm_id` z druhého `GetCredential`.
  concept_id            VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
  -- Skutečné dmID datové zprávy po schválení uživatelem.
  concept_dm_id         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  concept_status_code   VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  concept_status_message VARCHAR(500) NULL,

  -- Otisk příloh, které jsme do konceptu vložili. Důkaz, že schválená zpráva
  -- nesla přesně ten artefakt, který uživatel v aplikaci viděl.
  payload_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Naše spisová značka (dmSenderIdent) — kopie z fronty, aby šel řádek číst
  -- samostatně při dohledávání.
  correlation_reference VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

  error_code            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  error_message         VARCHAR(500) NULL,

  expires_at            DATETIME NOT NULL,
  started_at            DATETIME NOT NULL,
  concept_pushed_at     DATETIME NULL,
  finished_at           DATETIME NULL,

  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Viz komentář v hlavičce: nejvýš jedna živá relace na podání.
  active_outbox_id      BIGINT UNSIGNED AS (
    CASE WHEN state IN ('awaiting_login','awaiting_approval') THEN outbox_id ELSE NULL END
  ) STORED,

  UNIQUE KEY uq_isds_gateway_sessions_token (app_token),
  UNIQUE KEY uq_isds_gateway_sessions_active (active_outbox_id),
  KEY idx_isds_gateway_sessions_scope (supplier_id, outbox_id, id),
  KEY idx_isds_gateway_sessions_expiry (state, expires_at),

  CONSTRAINT fk_isds_gateway_sessions_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  -- Kompozitní FK: relace nemůže ukázat na podání jiné firmy ani omylem.
  CONSTRAINT fk_isds_gateway_sessions_outbox
    FOREIGN KEY (supplier_id, outbox_id) REFERENCES submission_outbox (supplier_id, id) ON DELETE RESTRICT,
  -- RESTRICT, ne SET NULL: kdo schválení vyvolal, musí zůstat dohledatelné
  -- i po jeho odchodu z firmy.
  CONSTRAINT fk_isds_gateway_sessions_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CHECK omezení po jednom a vždy nejdřív zahodit — MariaDB neumí
-- `ADD CONSTRAINT IF NOT EXISTS`, takže jinak druhé spuštění migrace spadne.
ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_token;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_token
    CHECK (app_token REGEXP '^[0-9]{10,20}$');

ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_payload;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_payload
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$');

-- Schválená relace bez ID zprávy je tvrzení bez důkazu — a přesně to ID se
-- zapisuje do fronty jako `external_message_id`.
ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_approved;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_approved
    CHECK (state <> 'approved' OR (concept_dm_id IS NOT NULL AND finished_at IS NOT NULL));

-- Čekání na schválení předpokládá, že koncept v ISDS opravdu je.
ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_awaiting;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_awaiting
    CHECK (state <> 'awaiting_approval' OR (concept_id IS NOT NULL AND concept_pushed_at IS NOT NULL));

ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_failure;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_failure
    CHECK (state NOT IN ('failed','uncertain') OR error_code IS NOT NULL);

ALTER TABLE isds_gateway_sessions DROP CONSTRAINT IF EXISTS chk_isds_gateway_sessions_version;
ALTER TABLE isds_gateway_sessions
  ADD CONSTRAINT chk_isds_gateway_sessions_version
    CHECK (row_version > 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- Trigger: identita relace je neměnná a stav se nevrací
-- ─────────────────────────────────────────────────────────────────────────────
-- Bez tohohle by šlo přepsat `supplier_id` nebo `outbox_id` už rozjeté relace,
-- a tím poslat artefakt jedné firmy pod přesměrováním druhé.
DELIMITER //

DROP TRIGGER IF EXISTS trg_isds_gateway_sessions_update_guard//
CREATE TRIGGER trg_isds_gateway_sessions_update_guard
BEFORE UPDATE ON isds_gateway_sessions
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.outbox_id <=> OLD.outbox_id)
     OR NOT (NEW.user_id <=> OLD.user_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.app_token <=> OLD.app_token)
     OR NOT (NEW.payload_sha256 <=> OLD.payload_sha256)
     OR NOT (NEW.correlation_reference <=> OLD.correlation_reference)
     OR NOT (NEW.started_at <=> OLD.started_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'isds gateway session identity is immutable';
  END IF;

  -- Ukončená relace se znovu neotevírá. Kdyby šla, dala by se z jednoho
  -- schválení vyrobit druhá odeslaná zpráva.
  IF OLD.state IN ('approved','rejected','failed','expired') AND NOT (NEW.state <=> OLD.state) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'finished isds gateway session cannot change state';
  END IF;

  -- ID konceptu i ID odeslané zprávy jsou jednorázová přiřazení: obojí je
  -- důkaz, ne pracovní hodnota.
  IF OLD.concept_id IS NOT NULL AND NOT (NEW.concept_id <=> OLD.concept_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'isds gateway concept id is single-assignment';
  END IF;

  IF OLD.concept_dm_id IS NOT NULL AND NOT (NEW.concept_dm_id <=> OLD.concept_dm_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'isds gateway message id is single-assignment';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'isds gateway session row_version must advance by one';
  END IF;
END//

-- Relace je auditní stopa právního úkonu (schválení odeslání datové zprávy).
DROP TRIGGER IF EXISTS trg_isds_gateway_sessions_no_delete//
CREATE TRIGGER trg_isds_gateway_sessions_no_delete
BEFORE DELETE ON isds_gateway_sessions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'isds_gateway_sessions are append-only';
END//

DELIMITER ;
