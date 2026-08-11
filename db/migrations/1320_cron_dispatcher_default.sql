-- ==========================================================================
-- 1320 — nové instalace plánují cron jedním dispatcherem
-- ==========================================================================
-- Migrace 1184 zavedla režim 'dispatcher' jako volitelný a default nechala na
-- 'individual', aby se existujícím instalacím nezměnilo chování. To platí dál —
-- mění se jen výchozí volba pro NOVĚ zakládané instalace: jedna položka
-- v crontabu místo dvaceti procesů za hodinu, z nichž se většina probudí jen
-- proto, aby zjistila, že nemá co dělat.
--
-- Rozlišení nové a existující instalace: migrace běží v entrypointu PŘED
-- dokončením setup wizardu, takže čerstvá instalace nemá ještě žádného
-- dodavatele. Prázdná tabulka `supplier` je proto spolehlivý příznak „tady se
-- ještě nic nenaběhlo". Podmínka `updated_at IS NULL` je druhá pojistka: admin,
-- který si režim vědomě přepnul, se nepřepíše NIKDY, ani kdyby první podmínka
-- selhala.
--
-- Existující instalace tím pádem zůstávají na 'individual' a přepnutí zůstává
-- vědomým krokem v Systém → Plánované úlohy.

SET NAMES utf8mb4;

ALTER TABLE cron_settings
  MODIFY schedule_mode ENUM('individual','dispatcher') NOT NULL DEFAULT 'dispatcher'
    COMMENT 'individual = samostatné položky, dispatcher = 1 položka každou minutu (default pro nové instalace)';

UPDATE cron_settings
   SET schedule_mode = 'dispatcher'
 WHERE id = 1
   AND updated_at IS NULL
   AND NOT EXISTS (SELECT 1 FROM supplier);
