-- MyÚčto.cz — oddělené primární domény portálu a veřejných odkazů.
--
-- Starší vývojová podoba issue #11 používala jediný příznak is_primary.
-- Větev mohla být lokálně nasazena ještě před začleněním do masteru,
-- proto migrace převede i tuto podobu a na aktuálním schématu je opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE supplier_domains
  ADD COLUMN IF NOT EXISTS is_primary_portal TINYINT(1) NOT NULL DEFAULT 0
    AFTER status,
  ADD COLUMN IF NOT EXISTS is_primary_public TINYINT(1) NOT NULL DEFAULT 0
    AFTER is_primary_portal;

-- Na starém schématu je is_primary uložený sloupec, na novém je odvozený
-- z obou příznaků. Podmíněný backfill je proto bezpečný v obou případech.
UPDATE supplier_domains
   SET is_primary_portal = CASE
         WHEN is_primary = 1 AND purpose IN ('portal','all') THEN 1
         ELSE is_primary_portal
       END,
       is_primary_public = CASE
         WHEN is_primary = 1 AND purpose IN ('public_links','all') THEN 1
         ELSE is_primary_public
       END
 WHERE is_primary = 1;

-- Odvozené supplier sloupce z původního schématu závisely na jediném
-- is_primary. Nejdřív odstraníme jejich indexy a pak je sestavíme znovu
-- nad samostatnými příznaky pro oba účely.
ALTER TABLE supplier_domains
  DROP INDEX IF EXISTS uq_supplier_domains_primary_portal,
  DROP INDEX IF EXISTS uq_supplier_domains_primary_public;

ALTER TABLE supplier_domains
  DROP COLUMN IF EXISTS primary_portal_supplier_id,
  DROP COLUMN IF EXISTS primary_public_supplier_id;

ALTER TABLE supplier_domains
  DROP CONSTRAINT IF EXISTS chk_supplier_domains_primary,
  DROP CONSTRAINT IF EXISTS chk_supplier_domains_primary_portal,
  DROP CONSTRAINT IF EXISTS chk_supplier_domains_primary_public;

ALTER TABLE supplier_domains
  MODIFY COLUMN is_primary TINYINT(1) GENERATED ALWAYS AS (
    is_primary_portal = 1 OR is_primary_public = 1
  ) PERSISTENT;

ALTER TABLE supplier_domains
  ADD COLUMN IF NOT EXISTS primary_portal_supplier_id INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN status = 'active' AND is_primary_portal = 1 THEN supplier_id
        ELSE NULL
      END
    ) PERSISTENT,
  ADD COLUMN IF NOT EXISTS primary_public_supplier_id INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN status = 'active' AND is_primary_public = 1 THEN supplier_id
        ELSE NULL
      END
    ) PERSISTENT;

ALTER TABLE supplier_domains
  ADD UNIQUE INDEX IF NOT EXISTS uq_supplier_domains_primary_portal
    (primary_portal_supplier_id),
  ADD UNIQUE INDEX IF NOT EXISTS uq_supplier_domains_primary_public
    (primary_public_supplier_id),
  ADD CONSTRAINT IF NOT EXISTS chk_supplier_domains_primary_portal CHECK (
    is_primary_portal IN (0, 1)
    AND (is_primary_portal = 0 OR purpose IN ('portal','all'))
  ),
  ADD CONSTRAINT IF NOT EXISTS chk_supplier_domains_primary_public CHECK (
    is_primary_public IN (0, 1)
    AND (is_primary_public = 0 OR purpose IN ('public_links','all'))
  );
