-- 1110: Tax submission lifecycle (audit §2.4 — "Generované XML není podané XML")
--
-- Dosud se poslední ARCHIVOVANÝ (vygenerovaný/stažený) XML používal jako referenční
-- "podaný" stav a pouhé stažení KH/DPH mohlo posunout daňový zámek. To je nesprávné:
-- vygenerování ani stažení XML není podání na EPO.
--
-- Zavádíme explicitní životní cyklus:
--   draft -> generated -> downloaded -> submitted -> accepted / rejected
--
-- Pouze `submitted` (doložené časem podání + identifikátorem/potvrzením podatelny) smí:
--   (a) být základ pro opravné/následné tvrzení (findLatestForPeriod),
--   (b) posunout daňový zámek účtování,
--   (c) být v UI označeno jako podané,
--   (d) vstoupit do rekonciliace "s podaným přiznáním".
-- Vygenerované snapshoty zůstávají oddělenou technickou historií (vč. xml_sha256).

ALTER TABLE `tax_submissions`
    ADD COLUMN `status` ENUM('draft','generated','downloaded','submitted','accepted','rejected')
        NOT NULL DEFAULT 'generated'
        COMMENT 'Životní cyklus podání; jen submitted+ je prokazatelné podání' AFTER `validation_status`,
    ADD COLUMN `submitted_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Čas, kdy uživatel doložil podání na EPO' AFTER `status`,
    ADD COLUMN `submission_ref` VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Identifikátor/č.j. potvrzení podatelny EPO (opis podání)' AFTER `submitted_at`,
    ADD COLUMN `submitted_by` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'users.id — kdo označil jako podané' AFTER `submission_ref`,
    ADD KEY `idx_status_period` (`supplier_id`, `form_code`, `period_year`, `status`);

-- Backfill existujících řádků: tyto snapshoty odpovídají výkazům, které v minulosti
-- SKUTEČNĚ PODALA ÚČETNÍ (rozhodnutí uživatele) -> berou se jako prokazatelně podané
-- ('submitted'), aby zůstal zachován daňový zámek a rekonciliace "s podaným přiznáním".
-- submitted_at doplněn z generated_at (přesný čas podání zpětně není k dispozici). Nové
-- výkazy už tento automatický přechod NEMAJÍ — projdou draft/generated/downloaded a
-- 'submitted' je vědomá akce oprávněného uživatele (markSubmitted()).
UPDATE `tax_submissions`
   SET `status` = 'submitted',
       `submitted_at` = COALESCE(`submitted_at`, `generated_at`)
 WHERE `status` = 'generated';
