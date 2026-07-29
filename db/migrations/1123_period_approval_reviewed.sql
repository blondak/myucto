-- MyÚčto.cz — EP-5: oddělení VRATNÉ interní kontroly (reviewed) od NEVRATNÉHO
-- zákonného schválení účetní závěrky (approved) — §17 odst. 7 ZoÚ.
--
-- Dosud stav 'approved' šel přes /status vrátit na 'closed' a repository přitom
-- NULoval approved_at/approved_by — to koliduje s §17/7 (schválená závěrka je
-- definitivní; opravy se dělají v období zjištění, §35 ZoÚ, ne přepisem schválení).
--
-- Nově:
--   reviewed_*  = vratná interní kontrola / review (pracovní stav, lze zrušit).
--   approval_*  = nevratné zákonné schválení — orgán/osoba, odkaz na rozhodnutí
--                 a hash schváleného dokumentu se uchovávají a NIKDY se nemažou.
--
-- Bezpečná aditivní migrace: pouze nové NULLABLE sloupce + APPEND 'reviewed' na
-- KONEC ENUM (zachová interní indexy stávajících hodnot — 'approved' zůstává
-- 'approved', žádná stávající data se nepřemapují; vzor 1015_).
--
-- Idempotence: MODIFY + ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

-- 'reviewed' se přidává na KONEC výčtu (ne mezi 'closed' a 'approved') — jinak by
-- se posunul interní index 'approved' a stávající schválená období by se rozjela.
ALTER TABLE accounting_periods
  MODIFY COLUMN status ENUM('open','closing','closed','approved','reviewed') NOT NULL DEFAULT 'open';

ALTER TABLE accounting_periods
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL
    COMMENT 'okamžik interní kontroly (vratné) — nezaměňovat se zákonným schválením' AFTER approved_by,
  ADD COLUMN IF NOT EXISTS reviewed_by INT NULL
    COMMENT 'user id — kdo provedl interní kontrolu (vratné)' AFTER reviewed_at,
  ADD COLUMN IF NOT EXISTS approval_body VARCHAR(190) NULL
    COMMENT 'orgán/osoba schvalující závěrku (§17/7) — nevratné, nikdy se nemaže' AFTER reviewed_by,
  ADD COLUMN IF NOT EXISTS approval_decision_ref VARCHAR(190) NULL
    COMMENT 'odkaz na rozhodnutí o schválení závěrky — nevratné, nikdy se nemaže' AFTER approval_body,
  ADD COLUMN IF NOT EXISTS approval_document_hash CHAR(64) NULL
    COMMENT 'SHA-256 schváleného dokumentu závěrky — nevratné, nikdy se nemaže' AFTER approval_decision_ref;
