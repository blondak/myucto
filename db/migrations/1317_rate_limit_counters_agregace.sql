-- ==========================================================================
-- 1317 — rate_limit_counters: agregovaný čítač místo řádku na každý request
-- ==========================================================================
-- Migrace 1135 zavedla DB fallback jako „jeden řádek = jeden request" a úklid
-- nechala výhradně na cron-cleanup.php. Jenže cron běží JEDNOU DENNĚ, kdežto
-- retence je 2 hodiny — mezi dvěma běhy se tedy v tabulce nasčítal CELÝ den
-- provozu. Tabulka je ENGINE=MEMORY s tvrdým stropem max_heap_table_size
-- (výchozích 16 MB), takže po zaplnění začal každý INSERT padat na
-- `1114 The table 'rate_limit_counters' is full` — a protože limiter běží nad
-- KAŽDÝM requestem, shodilo to celé API do HTTP 500 (session status, bankovní
-- import, číselníky). Na produkci se to sešlo při 31 665 řádcích / 15,7 MB.
--
-- Sám sliding-window model byl ta chyba: `read_per_min_per_user` je 300, takže
-- jeden aktivní uživatel uměl vyrobit 300 řádků za minutu. Zvětšit heap by jen
-- posunulo datum pádu.
--
-- Nově drží tabulka JEDEN řádek na (bucket, okno) s čítačem `hits`. Počet řádků
-- tím přestává záviset na objemu provozu a odpovídá počtu ŽIVÝCH bucketů
-- (aktivní uživatelé × pravidla), takže se do MEMORY stropu vejde s obrovskou
-- rezervou. `expires_at` (unix čas) je absolutní konec okna — podle něj mete
-- jak middleware (při založení nového bucketu), tak cron jako záchranná brzda.
--
-- Okno je pevné a zarovnané (floor(now/window)*window) — stejná sémantika jako
-- Redis větev (INCR + EXPIRE), ne slidující jako dřív.
--
-- DROP je bezpečný: jde o dočasná počítadla, žádná účetní data. Ztráta obsahu
-- znamená nejvýš to, že běžící okna začnou od nuly — totéž, co restart MariaDB
-- u MEMORY tabulky dělá tak jako tak.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS rate_limit_counters;

CREATE TABLE rate_limit_counters (
  bucket_key   VARCHAR(120)     NOT NULL,               -- shodné s Redis klíčem (rl:*)
  window_start INT UNSIGNED     NOT NULL,               -- unix čas začátku okna
  hits         INT UNSIGNED     NOT NULL DEFAULT 0,
  expires_at   INT UNSIGNED     NOT NULL,               -- unix čas konce okna
  -- USING BTREE: MEMORY indexuje ve výchozím stavu HASH, který neumí rozsahový
  -- predikát `expires_at < ?` ani prefix bucket_key — sweep by jel full scanem.
  PRIMARY KEY (bucket_key, window_start) USING BTREE,
  KEY idx_rlc_expires (expires_at) USING BTREE
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS sp_cleanup_old_rate_limits;

DELIMITER //

-- Záchranná brzda pro buckety, na které se přestalo sahat (běžný úklid dělá
-- middleware sám). Mažeme podle absolutní expirace, takže na délce okna nezáleží.
CREATE PROCEDURE sp_cleanup_old_rate_limits()
BEGIN
    DELETE FROM rate_limit_counters WHERE expires_at < UNIX_TIMESTAMP();
    SELECT ROW_COUNT() AS deleted_rate_limits;
END //

DELIMITER ;
