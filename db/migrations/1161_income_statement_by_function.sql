-- 1161: vyhláška 500/2002 Sb., příloha č. 2 část II — VZZ v ÚČELOVÉM členění
--
-- Systém uměl jen druhové členění (příloha 2 část I). Účetní jednotka, která si podle
-- § 39b zvolí účelové, tedy výkaz sestavit nemohla vůbec — matice účetnictví to vedla
-- jako CHYBÍ.
--
-- ── Čím se účelové liší ─────────────────────────────────────────────────────────────
-- Druhové člení náklady podle DRUHU (materiál, mzdy, odpisy, služby) — to jde odvodit
-- z čísla účtu, proto je mapa globální. Účelové je člení podle FUNKCE, ke které náklad
-- slouží: náklady prodeje / odbytové náklady / správní režie. Tuhle informaci číslo
-- syntetického účtu NENESE — tytéž Služby (518) mohou být u jedné firmy náklad prodeje
-- a u druhé správní režie, a zpravidla se dělí mezi víc funkcí zároveň.
--
-- Proto je mapa rozdělená na dvě části:
--   • řádky, které se od druhového neliší (tržby, ostatní provozní, celá finanční část,
--     daň, převod podílu) → GLOBÁLNÍ mapa, odvozená překódováním z druhové verze; ta je
--     už ověřená testy, takže se prefixy nepřepisují ručně a nemůžou se rozejít,
--   • řádky A. / B. / C. → PER-FIRMA mapa `statement_function_map`, protože přiřazení
--     nákladu k funkci je rozhodnutí účetní jednotky, ne vlastnost účtu.
--
-- Bez úplného přiřazení se výkaz NESESTAVÍ (viz FinancialStatementService) — nesestavený
-- výkaz je poctivější než výkaz, kde nepřiřazený náklad tiše vypadne a hrubý zisk vyjde
-- moc vysoký.
--
-- Účty, které přiřazení vyžadují, jsou právě ty, které v druhovém členění spadají do
-- A. Výkonová spotřeba, B. Změna stavu zásob, C. Aktivace, D. Osobní náklady
-- a E. Úpravy hodnot v provozní oblasti.

SET NAMES utf8mb4;

ALTER TABLE statement_versions
    MODIFY statement_type ENUM('balance_sheet','income_statement','income_statement_purpose') NOT NULL;

INSERT INTO statement_versions (statement_type, version_code, valid_from, valid_to)
SELECT 'income_statement_purpose', 'vyhl500-2002/2024', '2000-01-01', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM statement_versions WHERE statement_type = 'income_statement_purpose'
);

SET @vf := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement_purpose' LIMIT 1);
SET @vd := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement' LIMIT 1);

-- ── řádky (příloha 2 část II) ───────────────────────────────────────────────────────
-- `I.f` = „Ostatní finanční náklady". Písmeno I. se v účelovém členění tluče s římskou
-- I. (Tržby) úplně stejně, jako se v druhovém tlouklo `I.n` — stejný trik, stejný důvod:
-- kód musí být jedinečný, zobrazuje se ale jako „I.".
INSERT INTO statement_rows (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
SELECT * FROM (
              SELECT @vf AS v, 'I.' AS rc, NULL AS prc, 'profit_loss' AS sec,
                     'Tržby z prodeje výrobků, zboží a služeb' AS lbl, 1 AS lvl, 1 AS pos, 'detail' AS rt, NULL AS ck
    UNION ALL SELECT @vf, 'A.',    NULL, 'profit_loss', 'Náklady prodeje (včetně úprav hodnot)',                      1,  2, 'detail',   NULL
    UNION ALL SELECT @vf, 'HZ',    NULL, 'profit_loss', 'Hrubý zisk nebo ztráta (±)',                                 1,  3, 'computed', 'gross_profit'
    UNION ALL SELECT @vf, 'B.',    NULL, 'profit_loss', 'Odbytové náklady (včetně úprav hodnot)',                     1,  4, 'detail',   NULL
    UNION ALL SELECT @vf, 'C.',    NULL, 'profit_loss', 'Správní režie (včetně úprav hodnot)',                        1,  5, 'detail',   NULL
    UNION ALL SELECT @vf, 'II.',   NULL, 'profit_loss', 'Ostatní provozní výnosy',                                    1,  6, 'detail',   NULL
    UNION ALL SELECT @vf, 'D.',    NULL, 'profit_loss', 'Ostatní provozní náklady',                                   1,  7, 'detail',   NULL
    UNION ALL SELECT @vf, 'PVH',   NULL, 'profit_loss', 'Provozní výsledek hospodaření (±)',                          1,  8, 'computed', 'operating_profit'
    UNION ALL SELECT @vf, 'III.',  NULL, 'profit_loss', 'Výnosy z dlouhodobého finančního majetku — podíly',          1,  9, 'detail',   NULL
    UNION ALL SELECT @vf, 'E.',    NULL, 'profit_loss', 'Náklady vynaložené na prodané podíly',                       1, 10, 'detail',   NULL
    UNION ALL SELECT @vf, 'IV.',   NULL, 'profit_loss', 'Výnosy z ostatního dlouhodobého finančního majetku',         1, 11, 'detail',   NULL
    UNION ALL SELECT @vf, 'F.',    NULL, 'profit_loss', 'Náklady související s ostatním dlouhodobým finančním majetkem', 1, 12, 'detail', NULL
    UNION ALL SELECT @vf, 'V.',    NULL, 'profit_loss', 'Výnosové úroky a podobné výnosy',                            1, 13, 'detail',   NULL
    UNION ALL SELECT @vf, 'G.',    NULL, 'profit_loss', 'Úpravy hodnot a rezervy ve finanční oblasti',                1, 14, 'detail',   NULL
    UNION ALL SELECT @vf, 'H.',    NULL, 'profit_loss', 'Nákladové úroky a podobné náklady',                          1, 15, 'detail',   NULL
    UNION ALL SELECT @vf, 'VI.',   NULL, 'profit_loss', 'Ostatní finanční výnosy',                                    1, 16, 'detail',   NULL
    UNION ALL SELECT @vf, 'I.f',   NULL, 'profit_loss', 'Ostatní finanční náklady',                                   1, 17, 'detail',   NULL
    UNION ALL SELECT @vf, 'FVH',   NULL, 'profit_loss', 'Finanční výsledek hospodaření (±)',                          1, 18, 'computed', 'financial_profit'
    UNION ALL SELECT @vf, 'VHPZ',  NULL, 'profit_loss', 'Výsledek hospodaření před zdaněním (±)',                     1, 19, 'computed', 'profit_before_tax'
    UNION ALL SELECT @vf, 'J.',    NULL, 'profit_loss', 'Daň z příjmů',                                               1, 20, 'subtotal', NULL
    UNION ALL SELECT @vf, 'J.1.',  'J.', 'profit_loss', 'Daň z příjmů splatná',                                       2, 21, 'detail',   NULL
    UNION ALL SELECT @vf, 'J.2.',  'J.', 'profit_loss', 'Daň z příjmů odložená (±)',                                  2, 22, 'detail',   NULL
    UNION ALL SELECT @vf, 'VHPO',  NULL, 'profit_loss', 'Výsledek hospodaření po zdanění (±)',                        1, 23, 'computed', 'profit_after_tax'
    UNION ALL SELECT @vf, 'K.',    NULL, 'profit_loss', 'Převod podílu na výsledku hospodaření společníkům (±)',      1, 24, 'detail',   NULL
    UNION ALL SELECT @vf, 'VH',    NULL, 'profit_loss', 'Výsledek hospodaření za účetní období (±)',                  1, 25, 'computed', 'profit_current'
    UNION ALL SELECT @vf, 'OBRAT', NULL, 'profit_loss', 'Čistý obrat za účetní období',                               1, 26, 'computed', 'net_turnover'
) AS r
WHERE NOT EXISTS (SELECT 1 FROM statement_rows WHERE version_id = @vf);

-- ── globální mapa: řádky shodné s druhovým členěním ─────────────────────────────────
-- Překódování starý → nový. Tržby z výrobků/služeb (I.) i za zboží (II.) splývají do
-- jediného řádku I.; členěné ostatní provozní výnosy (III.*) a náklady (F.*) do II. a D.
-- Zbytek (finanční část, daň, převod podílu) je 1:1.
INSERT INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign)
SELECT @vf, t.new_code, m.account_prefix, m.target, m.balance_condition, m.sign
  FROM statement_account_map m
  JOIN (
              SELECT 'I.'     AS old_code, 'I.'   AS new_code
    UNION ALL SELECT 'II.',    'I.'
    UNION ALL SELECT 'III.1.', 'II.'
    UNION ALL SELECT 'III.2.', 'II.'
    UNION ALL SELECT 'III.3.', 'II.'
    UNION ALL SELECT 'F.1.',   'D.'
    UNION ALL SELECT 'F.2.',   'D.'
    UNION ALL SELECT 'F.3.',   'D.'
    UNION ALL SELECT 'F.4.',   'D.'
    UNION ALL SELECT 'F.5.',   'D.'
    UNION ALL SELECT 'IV.',    'III.'
    UNION ALL SELECT 'G.',     'E.'
    UNION ALL SELECT 'V.',     'IV.'
    UNION ALL SELECT 'H.',     'F.'
    UNION ALL SELECT 'VI.',    'V.'
    UNION ALL SELECT 'I.n',    'G.'
    UNION ALL SELECT 'J.',     'H.'
    UNION ALL SELECT 'VII.',   'VI.'
    UNION ALL SELECT 'K.',     'I.f'
    UNION ALL SELECT 'L.1.',   'J.1.'
    UNION ALL SELECT 'L.2.',   'J.2.'
    UNION ALL SELECT 'M.',     'K.'
  ) AS t ON t.old_code = m.row_code
 WHERE m.version_id = @vd
   AND NOT EXISTS (SELECT 1 FROM statement_account_map WHERE version_id = @vf);

-- ── per-firma mapa funkcí (řádky A. / B. / C.) ──────────────────────────────────────
-- `account_prefix` zrcadlí globální mapu, aby platila stejná pravidla nejdelšího prefixu
-- a šlo přiřazovat i na úrovni analytik (518.100 odbyt, 518.200 správa) — což je v praxi
-- jediný způsob, jak jeden druhový účet rozdělit mezi víc funkcí.
CREATE TABLE IF NOT EXISTS statement_function_map (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id   INT UNSIGNED NOT NULL,
    account_prefix VARCHAR(10) NOT NULL,
    function_code ENUM('cost_of_sales','distribution','administration') NOT NULL
        COMMENT 'A. náklady prodeje / B. odbytové náklady / C. správní režie',
    note          VARCHAR(255) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sfm_supplier_prefix (supplier_id, account_prefix),
    KEY ix_sfm_supplier (supplier_id),
    CONSTRAINT fk_sfm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='vyhl. 500/2002 př. 2 část II — přiřazení nákladových účtů funkci (účelové členění VZZ)';
