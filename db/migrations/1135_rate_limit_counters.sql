-- ==========================================================================
-- 1135 — rate_limit_counters: DB fallback pro RateLimitMiddleware
-- ==========================================================================
-- Bez Redisu byl limiter no-op (RateLimitMiddleware vracel handle() rovnou),
-- takže každá instalace na IIS i každý Docker deploy bez `--profile redis`
-- běžel bez jakéhokoli rate limitu — včetně veřejných /api/public/* endpointů.
--
-- MEMORY engine stejně jako login_attempts: levné random access bez I/O,
-- obsah se ztratí při restartu MariaDB (u čítačů s minutovým oknem neškodí).
--
-- Úklid: sp_cleanup_old_rate_limits() volaná z cron-cleanup.php.

CREATE TABLE IF NOT EXISTS rate_limit_counters (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_key  VARCHAR(120) NOT NULL,                 -- shodné s Redis klíčem (rl:*)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rlc_bucket (bucket_key, created_at)
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS sp_cleanup_old_rate_limits;

DELIMITER //

-- Nejdelší okno limiteru je 3600 s (forgot/setup per hour) — mažeme s rezervou.
CREATE PROCEDURE sp_cleanup_old_rate_limits()
BEGIN
    DELETE FROM rate_limit_counters WHERE created_at < NOW() - INTERVAL 2 HOUR;
    SELECT ROW_COUNT() AS deleted_rate_limits;
END //

DELIMITER ;
