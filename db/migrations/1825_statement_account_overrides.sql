-- 1825: výjimky mapování účtů do výkazů pro konkrétní firmu
--
-- Mapa `statement_account_map` je globální a zařazuje účet podle čísla syntetiky. Předpis
-- ale firmě nechává volby, které z čísla účtu odvodit nejde:
--   • splatnost — půjčka od společníka na analytice 365.xxx splatná za víc než rok patří
--     do dlouhodobých závazků (C.I.), ne do krátkodobých (C.II.),
--   • spřízněné osoby — výnosy a náklady vůči ovládané či ovládající osobě jdou do
--     vlastních podřádků výsledovky,
--   • zařazení konkrétní analytiky do jiného řádku, než kam patří její syntetika.
--
-- Výjimka má stejný tvar jako řádek globální mapy, jen nese firmu. Sloučenou mapu skládá
-- jediné místo (StatementMapResolver): pro shodný prefix výjimka nahradí globální záznam,
-- jinak platí dál pravidlo nejdelšího prefixu. `account_prefix` smí být i analytika
-- (365.100); výkaz pak dostane zůstatek té analytiky zvlášť.
--
-- Unikátní klíč drží nejvýš jednu výjimku na prefix a stranu zůstatku. Saldový účet tak
-- může mít zvlášť výjimku pro debetní a pro kreditní zůstatek.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS statement_account_overrides (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id       INT UNSIGNED NOT NULL,
    version_id        SMALLINT UNSIGNED NOT NULL,
    account_prefix    VARCHAR(10) NOT NULL COMMENT 'syntetika nebo analytika (365, 365.100); prefix match jako globální mapa',
    row_code          VARCHAR(20) NOT NULL COMMENT 'řádek statement_rows téže verze',
    target            ENUM('gross','correction') NOT NULL DEFAULT 'gross' COMMENT 'correction jen u aktiv',
    balance_condition ENUM('any','debit','credit') NOT NULL DEFAULT 'any' COMMENT 'saldové účty: výjimka jen pro danou stranu zůstatku',
    sign              TINYINT NOT NULL DEFAULT 1,
    note              VARCHAR(255) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stmt_ovr_supplier_version_prefix (supplier_id, version_id, account_prefix, balance_condition),
    KEY ix_stmt_ovr_version (version_id),
    CONSTRAINT fk_stmt_ovr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_stmt_ovr_version FOREIGN KEY (version_id) REFERENCES statement_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='výjimky mapování účtů do výkazů pro konkrétní firmu (přebíjí statement_account_map)';
