-- Hranice, od které hash auditní stopy počítá `created_at` v UTC (§ 33a).
--
-- Články zapečetěné dřív mají v hashi `created_at` vyrenderovaný v zóně session,
-- takže se ověřují historickým renderem. Bez zapsané hranice by se ale ta výjimka
-- musela uznávat KAŽDÉMU záznamu — i těm, které vzniknou zítra. A protože obě
-- podoby popisují týž okamžik, znamenalo by to trvale, že posun `created_at`
-- přesně o offset zóny projde ověřením jako neporušený. To je díra přesně v tom,
-- co má řetěz dokazovat.
--
-- Watermark ji zavírá: `id` menší než hranice = starý článek, u kterého historický
-- render uznáváme; od hranice výš platí jen kanonický UTC hash.
--
-- Hodnota = poslední existující id v okamžiku migrace. Vše, co přibude potom, už
-- pečetí opravený kód.
--
-- Idempotence: sloupec se přidává jen když chybí, hodnota se dopočítává jen když
-- je NULL — opakovaný běh migrace nesmí hranici posunout dopředu, tím by
-- zlegalizoval články zapečetěné mezitím.

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'activity_log_chain_head'
       AND COLUMN_NAME = 'utc_created_at_from_id'
);

SET @sql := IF(@col = 0,
    'ALTER TABLE activity_log_chain_head
        ADD COLUMN utc_created_at_from_id BIGINT UNSIGNED NULL
        COMMENT ''od tohoto id vstupuje created_at do hashe v UTC; níž platí historický render''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Watermark bydlí na singletonu hlavy řetězu, takže ten řádek musí existovat.
-- Na některých instalacích chybí (viz migrace 1171) a bez něj by se watermark
-- neměl kam zapsat — ověření by pak historickou podobu uznávalo napořád.
-- Navázání na existující řetěz je totožné s 1171, ať se hlava nezaloží prázdná.
INSERT INTO activity_log_chain_head (id, last_id, last_hash)
SELECT 1, a.id, a.hash
  FROM activity_log a
 WHERE a.hash IS NOT NULL
 ORDER BY a.id DESC
 LIMIT 1
ON DUPLICATE KEY UPDATE last_id = last_id;

INSERT IGNORE INTO activity_log_chain_head (id, last_id, last_hash) VALUES (1, NULL, NULL);

UPDATE activity_log_chain_head
   SET utc_created_at_from_id = COALESCE(
           (SELECT MAX(id) + 1 FROM activity_log),
           1
       )
 WHERE id = 1
   AND utc_created_at_from_id IS NULL;
