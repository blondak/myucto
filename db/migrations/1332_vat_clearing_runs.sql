-- ==========================================================================
-- 1332 — evidence běhů zúčtování DPH (vazba dokladu na PODANÉ přiznání)
-- ==========================================================================
-- Interní doklad „zúčtování DPH" (migrace 1323/1324,
-- {@see MyInvoice\Service\Accounting\Vat\VatClearingService}) se do teď zakládal
-- výhradně kalendářně — cronem 1. dne v měsíci za období předchozí.
--
-- PROČ TO NESTAČILO. Kalendář není okamžik, kdy je daň za období známá. Doklad,
-- který do období přibude POZDĚJI (opožděná přijatá faktura, oprava, doklad
-- vytěžený AI o pár dní později), obrat období změní — a zúčtovací zápis už
-- odpovídá jinému číslu, než jaké se nakonec podalo. V knihách pak leží jiný
-- závazek vůči FÚ než ten přiznaný a nikdo se to nedozví.
--
-- AUTORITATIVNÍ OKAMŽIK JE PŘIZNÁNÍ, NE DATUM. Zúčtování se proto nově přepočítá
-- při PODÁNÍ přiznání k DPH (dphdp3 → `tax_submissions.status='submitted'`,
-- {@see MyInvoice\Service\Report\TaxSubmissionArchiver::markSubmitted()}) — v tu
-- chvíli je částka definitivní, protože právě ta se odesílá správci daně.
-- Cron zůstává jako záchranná síť pro období, za která se přiznání nepodalo.
--
-- CO DRŽÍ TAHLE TABULKA. Jeden řádek na (dodavatel, zdaňovací období) —
-- `source_id` je totéž deterministické ID jako v `journal_entries.source_id`
-- ({@see VatClearingService::sourceIdFor()}), takže vazba na doklad je 1:1.
-- Řádek říká, ČÍM byl doklad naposledy vyvolán a KTERÉMU PODÁNÍ odpovídá:
--   - `trigger_source`  … 'return_filed' (podání) | 'return_draft' (obnova při
--                         generování konceptu) | 'manual' (uživatel z UI) | 'cron'
--   - `submission_id`   … `tax_submissions.id` přiznání, ze kterého zúčtování vzešlo
--   - `submission_variant` … B/O/D/E — dodatečné (D) i opravné (O) přiznání doklad
--                         přepočítají znovu, poslední vyhrává
--   - `input_vat`/`output_vat`/`settlement` … co bylo v tu chvíli zaúčtováno
--
-- ZASTARALOST SE NEUKLÁDÁ, POČÍTÁ SE. Příznak „stale" tady záměrně NENÍ: byl by
-- to druhý zdroj pravdy, který se rozejde s realitou přesně v tom okamžiku, kdy
-- se rozejít nesmí (změna dokladu mimo cesty, které by ho uměly zneplatnit).
-- Aktuálnost se proto vyhodnocuje živě — přepočtem obratu období proti tomu, co
-- na dokladu SKUTEČNĚ je ({@see VatClearingService::status()}).
--
-- `entry_id` je záměrně BEZ cizího klíče: `journal_entries` je na části instalací
-- SYSTEM VERSIONED a odkaz sem je jen navigační zkratka. Platnost se ověřuje
-- dohledáním zápisu podle `source_type`+`source_id`, ne integritou databáze.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_clearing_runs (
  id                 INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  supplier_id        INT UNSIGNED     NOT NULL,
  source_id          INT UNSIGNED     NOT NULL COMMENT 'Deterministické ID období = journal_entries.source_id',
  period_year        SMALLINT UNSIGNED NOT NULL,
  period_first_month TINYINT UNSIGNED NOT NULL COMMENT 'První měsíc období (1..12)',
  period_type        ENUM('monthly','quarterly') NOT NULL,
  period_start       DATE             NOT NULL,
  period_end         DATE             NOT NULL COMMENT 'Datum účetního případu zúčtovacího dokladu',
  entry_id           BIGINT UNSIGNED  DEFAULT NULL COMMENT 'journal_entries.id (bez FK — viz hlavička migrace); NULL = nulové období, doklad neexistuje',
  input_vat          DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  output_vat         DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
  settlement         DECIMAL(14,2)    NOT NULL DEFAULT 0.00 COMMENT 'output_vat − input_vat = zůstatek na 343.900',
  status             VARCHAR(32)      NOT NULL COMMENT 'VatClearingService::STATUS_* posledního běhu',
  trigger_source     ENUM('return_filed','return_draft','manual','cron') NOT NULL,
  submission_id      INT UNSIGNED     DEFAULT NULL COMMENT 'tax_submissions.id přiznání, kterému doklad odpovídá',
  submission_form    VARCHAR(20)      DEFAULT NULL COMMENT 'form_code podání (dphdp3)',
  submission_variant CHAR(1)          DEFAULT NULL COMMENT 'B|O|D|E — druh podání',
  submitted_at       TIMESTAMP        NULL DEFAULT NULL COMMENT 'Čas podání přiznání, ze kterého doklad vzešel',
  computed_at        TIMESTAMP        NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  computed_by        INT UNSIGNED     DEFAULT NULL COMMENT 'users.id — kdo přepočet vyvolal (NULL = cron)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_vcr_supplier_source (supplier_id, source_id),
  KEY idx_vcr_period (supplier_id, period_year, period_first_month),
  KEY idx_vcr_submission (submission_id),
  CONSTRAINT fk_vcr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_vcr_submission FOREIGN KEY (submission_id) REFERENCES tax_submissions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Běhy zúčtování DPH — čím byl doklad vyvolán a kterému podání odpovídá';
