-- ==========================================================================
-- 1521 — H-25: častější dump databáze (4× denně) a jeho smluvní strop
-- ==========================================================================
-- Denní dump (`0 2 * * *`) znamená ztrátu až 24 hodin práce. Rozvrh
-- `0 */6 * * *` ji srazí na 6 hodin — u účetnictví je to rozdíl mezi
-- „dopiš si dnešek" a „dopiš si týden".
--
-- ⚠️ 4× DENNĚ JE NOVĚ SMLUVNÍ STROP. Do téhle frekvence hosting následuje
-- frekvenci svých vlastních záloh zdarma; nad ni už ne. Pátý dump denně tedy
-- není „o něco víc dat", ale položka, kterou nikdo neodsouhlasil — a přijde
-- na faktuře za měsíc, ne v logu. Proto je strop vynucený DVAKRÁT:
--
--   1) v SQL — CHECK `runs_per_day BETWEEN 1 AND 4` (níž),
--   2) v PHP — `MyInvoice\Service\Backup\BackupScheduleLimit::assertWithinContract()`,
--      který navíc ověří, že `runs_per_day` SEDÍ na uložený cron výraz.
--
-- Samotné SQL cron výraz rozebrat neumí, takže by šlo zapsat
-- `runs_per_day = 4` k výrazu `* * * * *`. Právě proto je druhá kontrola
-- v PHP a hlídá ji test `BackupScheduleLimitTest`.
--
-- ── Velikost dumpu a dopad na rezervu v kvótě ──────────────────────────────
-- Změřeno na reálné firemní databázi: SQL dump ~42 MB, komprimovaný ~20 MB
-- (poměr jen ~48 % — dump nese i binární přílohy).
--
--   dnešní default, 1×/den:   30 denních + 12 měsíčních ≈ 42 souborů ≈ 840 MB
--   4×/den, retence 7 DNŮ:    28 souborů                            ≈ 560 MB
--   4×/den, retence 7 KUSŮ:    7 souborů                            ≈ 140 MB
--
-- Čtyřnásobná frekvence sama o sobě kvótu nezvedá — zvedla by ji jen
-- v kombinaci s retencí počítanou ve DNECH. Proto H-25 chodí ruku v ruce
-- s H-05: bez přepnutí retence na KUSY (`cron.backup.retention_profile =
-- 'managed'`) by častější dump snědl přesně tu rezervu, kterou má ušetřit.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS backup_schedule_contract (
  id             TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  script         VARCHAR(80)  NOT NULL DEFAULT 'cron-backup',
  cron_expr      VARCHAR(120) NOT NULL DEFAULT '0 */6 * * *'
                 COMMENT 'pětipolový cron výraz; 0 */6 * * * = 00:00, 06:00, 12:00, 18:00',
  runs_per_day   TINYINT UNSIGNED NOT NULL DEFAULT 4
                 COMMENT 'kolikrát denně cron_expr sedne — musí odpovídat výrazu, ověřuje BackupScheduleLimit',
  contract_max   TINYINT UNSIGNED NOT NULL DEFAULT 4
                 COMMENT 'smluvní strop; zvýšení = DODATEK KE SMLOUVĚ, ne UPDATE',
  updated_at     DATETIME NULL,
  updated_by     INT UNSIGNED NULL COMMENT 'users.id, kdo rozvrh změnil',
  CONSTRAINT chk_backup_schedule_singleton CHECK (id = 1),
  -- Tvrdý strop. Nula by znamenala „nezálohuj", což není konfigurace, ale chyba.
  CONSTRAINT chk_backup_schedule_runs      CHECK (runs_per_day BETWEEN 1 AND 4),
  CONSTRAINT chk_backup_schedule_contract  CHECK (runs_per_day <= contract_max AND contract_max <= 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed = cílový rozvrh H-25. Existující řádek (admin si ho už nastavil) se
-- nepřepisuje — migrace je idempotentní a nesmí přemazat vědomé rozhodnutí.
INSERT INTO backup_schedule_contract (id, script, cron_expr, runs_per_day, contract_max)
VALUES (1, 'cron-backup', '0 */6 * * *', 4, 4)
ON DUPLICATE KEY UPDATE id = backup_schedule_contract.id;
