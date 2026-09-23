-- XXXX: platnost výjimek mapování výkazů po letech a volba srovnávacího období
--
-- Výjimka mapování (1825) platila pro všechny roky. Firma ale zařazení mění: účet, který
-- do roku 2023 vykazovala v jednom řádku, od roku 2024 vykazuje jinde. Bez platnosti
-- výjimka přepsala i minulé roky a sloupec minulého období nesouhlasil s podanou závěrkou.
--
-- valid_from_year / valid_to_year: účetní období (fiscal_year), pro která výjimka platí;
-- NULL = bez omezení. Pro jeden účet a stranu zůstatku smí výjimky platit jen
-- v nepřekrývajících se letech (hlídá StatementOverrideService), unikátní klíč proto
-- nově obsahuje začátek platnosti.
--
-- accounting_supplier_settings.comparative_from_prior_year: sloupec minulého období
-- rozvahy a výsledovky se počítá s výjimkami platnými v minulém roce, tedy stejně jako
-- uzavřený výkaz minulého roku. Výchozí 0 = minulé období se přepočítá podle zařazení
-- běžného roku (srovnatelnost údajů).

SET NAMES utf8mb4;

ALTER TABLE statement_account_overrides
    ADD COLUMN IF NOT EXISTS valid_from_year SMALLINT UNSIGNED NULL
        COMMENT 'první účetní období (fiscal_year), pro které výjimka platí; NULL = bez omezení' AFTER sign,
    ADD COLUMN IF NOT EXISTS valid_to_year SMALLINT UNSIGNED NULL
        COMMENT 'poslední účetní období (fiscal_year), pro které výjimka platí; NULL = bez omezení' AFTER valid_from_year;

ALTER TABLE statement_account_overrides
    ADD UNIQUE KEY IF NOT EXISTS uq_stmt_ovr_supplier_version_prefix_from
        (supplier_id, version_id, account_prefix, balance_condition, valid_from_year);

ALTER TABLE statement_account_overrides
    DROP INDEX IF EXISTS uq_stmt_ovr_supplier_version_prefix;

ALTER TABLE accounting_supplier_settings
    ADD COLUMN IF NOT EXISTS comparative_from_prior_year TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'minulé období výkazů s výjimkami mapování platnými v minulém roce (jako uzavřený výkaz)';
