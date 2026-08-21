-- ==========================================================================
-- 1523 — H-10: naměřená spotřeba místa instance (podklad pro režim jen pro čtení)
-- ==========================================================================
-- Spravovaná instalace běží s diskovou kvótou. Účetní systém, kterému dojde
-- místo uprostřed ukládání dokladu, je horší než ten, který včas řekne
-- „nezapisuju" — proto se spotřeba MĚŘÍ a vyhodnocuje předem.
--
-- ⚠️ FILESYSTÉMOVOU KVÓTU POUŽÍT NEJDE. Hosting ji nastavuje jako zaplacený
-- objem PLUS rezervu na dumpy a technicky z ní dumpy vyjmout nelze. Práh 90 %
-- odvozený z volného místa na svazku by tedy vycházel vždycky nízko (rezerva
-- na zálohy by se počítala jako spotřebovaná zákazníkem). Měříme si to sami
-- a smluvní strop držíme v konfiguraci (`storage_quota.limit_mb`).
--
-- ── Definice „živých dat" se MUSÍ shodovat s hostingem ────────────────────
-- Hosting počítá: soubory instance BEZ adresáře záloh aplikace + velikost
-- databáze. Kdybychom počítali jinak, hlásíme si navzájem dvě různá čísla
-- a nikdo nepozná, které platí. Proto:
--   • `database_bytes` = information_schema (data_length + index_length),
--   • `files_bytes`    = datový kořen rekurzivně MINUS adresáře záloh,
--   • `usage_bytes`    = součet těch dvou = jediné číslo, proti kterému se
--                        poměřuje kvóta,
--   • `backup_bytes`   = kolik zabírají zálohy; VÝSLOVNĚ SE NEPOČÍTÁ do
--                        `usage_bytes`. Je tu jen proto, aby šlo doložit, že
--                        se instance nezamkla vlastními zálohami.
--
-- ── ⚠️ NULL NENÍ NULA ─────────────────────────────────────────────────────
-- Nově založený řádek má `measured_at` i `usage_bytes` NULL = „první měření
-- ještě neproběhlo". To NENÍ prázdná instance. Prázdná instance a nezměřená
-- instance vypadají v datech skoro stejně, ale znamenají opak: u prázdné víme,
-- že je místa dost, u nezměřené nevíme nic. Nezměřený stav proto nesmí spustit
-- ani upozornění, ani režim jen pro čtení — a nesmí se ani hlásit jako „0 %,
-- vše v pořádku".
--
-- Proto jsou sloupce NULLABLE a proto se řádek zakládá prázdný: kdyby měly
-- DEFAULT 0, rozdíl „nezměřeno" vs. „nula" by v datech zmizel a nešel by
-- rozpoznat ani zpětně.
--
-- Singleton (id = 1): měření je vlastnost instalace, ne firmy. V účtárně
-- s deseti firmami je disk pořád jeden.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS + seed
-- přes ON DUPLICATE KEY UPDATE, který existující řádek NEPŘEPÍŠE (jinak by
-- opakovaný běh migrace zahodil poslední měření a instance by se na okamžik
-- tvářila jako nezměřená).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS instance_storage_usage (
  id              TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,

  -- NULL = první měření ještě neproběhlo. Viz hlavička.
  measured_at     DATETIME NULL
                  COMMENT 'kdy měření doběhlo; NULL = nezměřeno (NE nula)',

  database_bytes  BIGINT UNSIGNED NULL
                  COMMENT 'information_schema: SUM(data_length + index_length) aktuálního schématu',
  files_bytes     BIGINT UNSIGNED NULL
                  COMMENT 'datový kořen rekurzivně, BEZ adresářů záloh',
  usage_bytes     BIGINT UNSIGNED NULL
                  COMMENT 'database_bytes + files_bytes = živá data dle definice hostingu',

  backup_bytes    BIGINT UNSIGNED NULL
                  COMMENT 'kolik zabírají zálohy; do usage_bytes se NEPOČÍTÁ (jen diagnostika)',

  file_count      BIGINT UNSIGNED NULL COMMENT 'kolik souborů se do měření započetlo',
  duration_ms     INT UNSIGNED NULL    COMMENT 'jak dlouho měření trvalo (hlídání ceny cronu)',

  -- Měření má strop na počet položek i na čas. Když se do něj nevejde, je
  -- výsledek DOLNÍ ODHAD — a to je bezpečný směr: podměřená spotřeba instanci
  -- nezamkne, přeměřená ano.
  truncated       TINYINT(1) NOT NULL DEFAULT 0
                  COMMENT '1 = měření naráželo na strop, usage_bytes je dolní odhad',

  breakdown       JSON NULL COMMENT 'rozpad po adresářích prvního patra, jen pro diagnostiku',

  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT chk_instance_storage_usage_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doplnění sloupců pro instalace, kde tabulka vznikla dřívější verzí migrace.
ALTER TABLE instance_storage_usage ADD COLUMN IF NOT EXISTS backup_bytes BIGINT UNSIGNED NULL AFTER usage_bytes;
ALTER TABLE instance_storage_usage ADD COLUMN IF NOT EXISTS file_count   BIGINT UNSIGNED NULL AFTER backup_bytes;
ALTER TABLE instance_storage_usage ADD COLUMN IF NOT EXISTS duration_ms  INT UNSIGNED NULL    AFTER file_count;
ALTER TABLE instance_storage_usage ADD COLUMN IF NOT EXISTS truncated    TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_ms;
ALTER TABLE instance_storage_usage ADD COLUMN IF NOT EXISTS breakdown    JSON NULL AFTER truncated;

-- Seed prázdného (= NEZMĚŘENÉHO) řádku. Existující řádek se nepřepisuje.
INSERT INTO instance_storage_usage (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = instance_storage_usage.id;
