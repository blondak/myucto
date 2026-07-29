-- MyÚčto.cz — C6' (audit 2026-07, vat): odlišení ručně zadaného data přijetí od otisku importu.
--
-- Období odpočtu tuzemských přijatých plnění se řadí dle pozdějšího z (DUZP, vystavení).
-- Striktně dle § 73 odst. 1 písm. a) ZDPH je ale rozhodující datum, kdy plátce doklad
-- fyzicky DRŽÍ (received_at). Sloupec received_at se ovšem u importů (AI/iDoklad/Fakturoid/
-- ISDOC/inbox) plní datem IMPORTU (den zpracování), takže naivní GREATEST(DUZP, received_at)
-- by u zpětně importovaných dokladů naházel odpočet do měsíce importu.
--
-- Tento příznak spolehlivě odliší VĚDOMÉ ruční zadání data přijetí účetní ve formuláři PF
-- ('manual') od pouhého otisku data importu ('import'). VatLedgerService uplatní GREATEST
-- s received_at jen pro 'manual'. DEFAULT 'import' je bezpečný — zachovává současné chování
-- beze změny, dokud se doklad explicitně neoznačí 'manual' (ruční create/update endpoint).
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS received_at_source ENUM('manual','import') NOT NULL DEFAULT 'import'
    COMMENT 'C6 (§73/1/a): manual = účetní vědomě zadala received_at ve formuláři (období odpočtu dle skutečného držení dokladu); import = otisk data importu (období dle DUZP/vystavení, beze změny)';
