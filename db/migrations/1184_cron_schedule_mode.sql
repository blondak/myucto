-- MyÚčto.cz — volitelný režim plánování cronu (jednotlivé úlohy vs. dispatcher).
--
-- PROČ: katalog má 20 úloh a každá je dnes samostatná položka v crontabu /
-- Task Scheduleru. To je průhledné a laditelné, ale u instalace s desítkami
-- tenantů znamená desítky procesů za hodinu, které se z velké části probudí
-- jen proto, aby zjistily, že nemají co dělat.
--
-- Režim 'dispatcher' je scvrkne na JEDINOU položku (`cron-dispatch` každou
-- minutu), která si sama spočítá, co je na řadě, a spustí jen to.
--
-- Default je ZÁMĚRNĚ 'individual' — beze změny chování. Existující instalace
-- po nasazení nepozná rozdíl; přepnutí je vědomý krok admina v UI
-- (Systém → Plánované úlohy).
--
-- Singleton tabulka: konfigurace je instance-wide (plánovač je jeden na
-- instalaci, ne na dodavatele), CHECK drží jediný řádek s id = 1.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cron_settings (
  id             TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  schedule_mode  ENUM('individual','dispatcher') NOT NULL DEFAULT 'individual'
                 COMMENT 'individual = 20 samostatných položek (default), dispatcher = 1 položka každou minutu',
  updated_at     DATETIME NULL,
  updated_by     INT UNSIGNED NULL COMMENT 'users.id admina, který režim přepnul',
  CONSTRAINT chk_cron_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_settings (id, schedule_mode) VALUES (1, 'individual')
ON DUPLICATE KEY UPDATE id = cron_settings.id;

-- Nárokování minuty dispatcherem. Bez něj by dvojí spuštění dispatcheru v téže
-- minutě (cron + ruční `php api/bin/cron-dispatch.php`) pustilo tytéž úlohy
-- dvakrát — u cron-generate-recurring-invoices nebo cron-payroll-post by to
-- znamenalo duplicitní doklady, což se pozná až v účetnictví.
--
-- INSERT IGNORE na (script, minute_bucket) je atomický: kdo vloží řádek, ten
-- úlohu spouští. Staré řádky maže sám dispatcher (drží se ~2 hodiny).
CREATE TABLE IF NOT EXISTS cron_dispatch_claims (
  script        VARCHAR(80) NOT NULL,
  minute_bucket DATETIME    NOT NULL COMMENT 'minuta zarovnaná na :00 sekund',
  claimed_at    DATETIME    NOT NULL,
  PRIMARY KEY (script, minute_bucket),
  KEY idx_cron_dispatch_claims_bucket (minute_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
