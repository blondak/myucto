-- MyÚčto.cz — oddělení heartbeatu cronu od logu běhů.
--
-- PROČ: `cron_runs` (migrace 0024) plnil dvě různé role najednou — byl zároveň
-- historií toho, co cron udělal, a zároveň jediným důkazem, že cron vůbec žije.
-- Kvůli té druhé roli musel CronRun::start() zapsat řádek DŘÍV, než skript zjistil,
-- že nemá co dělat. U cron-epo-status (každou minutu) to je 1440 párů INSERT+UPDATE
-- denně na instalaci, z nichž 99 % říká „nic". cron-cleanup pak většinu z nich zase
-- maže — tabulka roste a hned se prořezává, jen aby v ní zůstal důkaz života.
--
-- ŘEŠENÍ: role se rozdělí.
--   cron_heartbeat — JEDEN řádek na skript, přepisovaný upsertem. Drží „kdy naposled
--                    tick, s jakým výsledkem, kdy naposled úspěšně". Odsud čte UI
--                    zdraví („cron nejede" / „selhává"). Konstantní velikost.
--   cron_runs      — beze změny schématu, ale nově jen běhy, které NĚCO UDĚLALY
--                    nebo selhaly. Tedy skutečná historie, ne šum.
--
-- Stav 'noop' = tick proběhl a korektně zjistil, že není co dělat. Pro účely zdraví
-- se počítá jako úspěch (nastavuje last_ok_at) — cron žije a dělá svou práci.
-- Rozlišený je proto, aby šlo v UI poznat „běží naprázdno" od „reálně pracuje“.
--
-- BACKFILL: bez něj by po nasazení každá instalace do prvního ticku hlásila
-- „nikdy neběželo" a u měsíčních úloh (cron-payroll-post, max_age_hours=792) by
-- ten falešný poplach držel týdny. Proto se poslední stav přenese z cron_runs.
-- Řádky se statusem 'running' se ignorují: buď zrovna běží (za chvíli se přepíšou),
-- nebo jde o mrtvolu po tvrdě zabitém procesu — ani jedno není použitelný stav.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE,
-- který existující řádek nechá být (backfill nesmí přepsat živá data při rerunu).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cron_heartbeat (
  script            VARCHAR(80)  NOT NULL PRIMARY KEY,
  last_tick_at      DATETIME     NOT NULL                COMMENT 'kdy naposledy skript doběhl (jakkoli)',
  last_status       ENUM('ok','noop','error') NOT NULL   COMMENT 'noop = korektně zjistil, že není co dělat',
  last_started_at   DATETIME     NULL,
  last_finished_at  DATETIME     NULL,
  last_duration_ms  INT UNSIGNED NULL,
  last_exit_code    TINYINT      NULL,
  last_host         VARCHAR(100) NULL,
  last_message      TEXT         NULL,
  last_report       JSON         NULL,
  last_ok_at        DATETIME     NULL                    COMMENT 'poslední úspěšný tick včetně noop — zdroj pro health v UI',
  last_work_at      DATETIME     NULL                    COMMENT 'poslední tick, který reálně něco udělal',
  noop_ticks        BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT 'monotónní čítač prázdných ticků (diagnostika)',
  KEY idx_cron_heartbeat_tick (last_tick_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_heartbeat
      (script, last_tick_at, last_status, last_started_at, last_finished_at,
       last_duration_ms, last_exit_code, last_host, last_message, last_report,
       last_ok_at, last_work_at)
SELECT r.script,
       COALESCE(r.finished_at, r.started_at),
       IF(r.status = 'error', 'error', 'ok'),
       r.started_at,
       r.finished_at,
       r.duration_ms,
       r.exit_code,
       r.host,
       r.message,
       r.report,
       ok.last_ok_at,
       -- Historické řádky nerozlišovaly „udělal něco" od „neměl co dělat", takže
       -- poslední úspěch bereme i jako poslední práci. Napravuje se sám prvním
       -- reálným během po nasazení.
       ok.last_ok_at
  FROM cron_runs r
  JOIN (SELECT script, MAX(id) AS max_id
          FROM cron_runs
         WHERE status IN ('ok','error')
         GROUP BY script) newest
    ON newest.max_id = r.id
  LEFT JOIN (SELECT script, MAX(COALESCE(finished_at, started_at)) AS last_ok_at
               FROM cron_runs
              WHERE status = 'ok'
              GROUP BY script) ok
    ON ok.script = r.script
ON DUPLICATE KEY UPDATE script = cron_heartbeat.script;
