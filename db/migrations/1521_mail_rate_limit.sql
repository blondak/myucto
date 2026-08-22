-- ==========================================================================
-- 1520 — H-16: brzda odchozí pošty (klouzavá okna, fronta, dělení dávek)
-- ==========================================================================
-- Hosting spravované instalace účtuje odchozí poštu a nad limit odpovídá
-- SMTP 451 (dočasné odmítnutí) — zpráva se nezahodí, zůstane v jeho frontě
-- a odejde později. To je bezpečné, ale netransparentní: zákazník s dávkou
-- upomínek se o problému dozví až z nedoručených faktur. Brzdíme proto sami,
-- pod jejich mezí.
--
-- ⚠️ Písemné upřesnění hostingu, na kterém tenhle model stojí:
--
--   1) 1 000/den a 200/hod počítá ZPRÁVY (SMTP transakce), NE příjemce.
--      Jedna upomínka rozeslaná padesáti odběratelům v jedné zprávě je
--      jedna zpráva. Proto `mail_send_log` = jeden řádek na jedno odeslání
--      a počet příjemců je jen doprovodný údaj, ne jednotka počítadla.
--
--   2) Nejvýš 100 příjemců na jednu zprávu — nad to je odmítnutí TRVALÉ.
--      Jediné tvrdé pravidlo v celém odstavci: fronta ho nezachrání, dávku
--      musí rozdělit aplikace. Proto CHECK na `mail_outbox.recipients` —
--      do fronty se nesmí dostat zpráva, kterou by hosting zahodil natrvalo.
--
--   3) Obě okna jsou KLOUZAVÁ, ne kalendářní. Půlnoc počítadlo nenuluje.
--      Proto se tady NEUKLÁDAJÍ denní/hodinové agregáty (ty by svedly ke
--      `WHERE DATE(sent_at) = CURDATE()`), ale syrové časy odeslání
--      s indexem — okno se počítá jako `sent_at > :now - INTERVAL`.
--      Přesnost na milisekundy (DATETIME(3)) je tu proto, aby dávka
--      odeslaná v jedné sekundě nespadla do jednoho okamžiku a nešlo
--      spočítat, kdy přesně se okno uvolní.
--
--   4) Nad limit: SMTP 451, zpráva zůstává ve frontě. Nic se nezahazuje,
--      nic se neodbounceuje → `over_limit_action = 'deferred'`.

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- Počítadlo odeslaných ZPRÁV pro klouzavá okna.
-- Jeden řádek = jedna SMTP transakce = jedna položka limitu hostingu.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mail_send_log (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sent_at       DATETIME(3)      NOT NULL
                COMMENT 'okamžik SMTP transakce; klouzavé okno = sent_at > NOW(3) - INTERVAL',
  template      VARCHAR(64)      NULL COMMENT 'kód šablony — kvůli rozboru, kdo kvótu spotřeboval',
  email_profile VARCHAR(64)      NULL COMMENT 'kód e-mailového profilu (odesílací identita)',
  recipients    SMALLINT UNSIGNED NOT NULL DEFAULT 1
                COMMENT 'počet příjemců v TÉHLE zprávě (to+cc+bcc). NENÍ jednotka limitu — jen diagnostika.',
  KEY idx_mail_send_log_sent_at (sent_at),
  CONSTRAINT chk_mail_send_log_recipients CHECK (recipients <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Fronta odložených zpráv. Když brzda sepne, zpráva se NEZAHODÍ — uloží se
-- sem s časem, kdy se okno prokazatelně uvolní, a odejde při dalším průchodu.
--
-- Payload je logický požadavek (šablona + příjemci + proměnné), ne hotové
-- MIME: šablona se přerenderuje při odeslání, takže se do fronty neukládají
-- vložená loga ani podpisy a řádek zůstane malý.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mail_outbox (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at   DATETIME(3)      NOT NULL,
  not_before   DATETIME(3)      NOT NULL
               COMMENT 'nejdřív tehdy se okno uvolní (spočítáno z nejstaršího odeslání v okně)',
  -- `requeued` = zpráva při vyprazdňování fronty narazila na brzdu znovu
  -- a pokračuje jako NOVÝ řádek. Vlastní stav proto, že označit ji za
  -- odeslanou by lhalo a označit za chybu taky — nic se nestalo špatně.
  status       ENUM('pending','sent','failed','requeued') NOT NULL DEFAULT 'pending',
  attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  template     VARCHAR(64)      NOT NULL,
  locale       VARCHAR(8)       NOT NULL DEFAULT 'cs',
  recipients   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  defer_reason VARCHAR(32)      NULL COMMENT 'hour | day — které okno brzdilo',
  payload      LONGTEXT         NOT NULL COMMENT 'JSON: to, cc, bcc, vars, subject, attachments, user_id, email_profile',
  last_error   TEXT             NULL,
  sent_at      DATETIME(3)      NULL,
  KEY idx_mail_outbox_due (status, not_before),
  -- ⚠️ Tvrdé pravidlo hostingu: >100 příjemců = TRVALÉ odmítnutí. Do fronty
  -- se taková zpráva nesmí dostat ani omylem — dělení je věc aplikace
  -- (MailRecipientBatcher), ne fronty.
  CONSTRAINT chk_mail_outbox_recipients CHECK (recipients <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Události brzdy. Tvar sloupců je ZÁMĚRNĚ shodný s webhooky hostingu
-- (`instance.mail_limit_warning` / `instance.mail_limit_reached`), které
-- chodí na náš prodejní web a do instance se nedostanou. Díky stejnému tvaru
-- jde obojí postavit vedle sebe a hned vidět, jestli se naše brzda s jejich
-- počítadlem nerozešla.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mail_rate_limit_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  occurred_at       DATETIME(3)     NOT NULL,
  event             VARCHAR(48)     NOT NULL
                    COMMENT 'instance.mail_limit_warning | instance.mail_limit_reached',
  window_name       ENUM('hour','day') NOT NULL COMMENT 'které okno rozhodlo',
  sent_last_hour    INT UNSIGNED    NOT NULL,
  sent_last_day     INT UNSIGNED    NOT NULL,
  limit_hour        INT UNSIGNED    NOT NULL,
  limit_day         INT UNSIGNED    NOT NULL,
  percent           DECIMAL(5,1)    NOT NULL COMMENT 'naplnění rozhodujícího okna v %',
  over_limit_action VARCHAR(16)     NOT NULL DEFAULT 'deferred',
  notified_at       DATETIME(3)     NULL COMMENT 'kdy odešlo upozornění správci instance',
  KEY idx_mail_rate_limit_events_occurred (occurred_at, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
