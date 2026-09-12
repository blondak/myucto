-- 1824: rozpad finančních položek VZZ na „ovládaná nebo ovládající osoba" a „ostatní"
--
-- Plný výkaz zisku a ztráty (vyhláška č. 500/2002 Sb., příloha 2, druhové členění) člení
-- výnosy z podílů (IV.), výnosy z ostatního dlouhodobého finančního majetku (V.), výnosové
-- úroky (VI.) a nákladové úroky (J.) na část vůči ovládané nebo ovládající osobě (.1)
-- a na ostatní (.2). Příloha přiznání k DPPO má pro podřádky vlastní čísla řádků (číselník
-- MF ČR, tabulka 25810: ř. 32/33, 36/37, 40/41, 44/45). Výkaz je dosud neměl, takže příloha
-- u VI. kopírovala celek do ř. 41 a u J. nechávala ř. 45 prázdný.
--
-- Rodiče se stávají mezisoučtem a výchozí mapa účtů přechází celá do podřádku „ostatní":
-- číslo syntetického účtu vztah ke spřízněné osobě nenese, zařazení do „.1" je rozhodnutí
-- firmy (výjimky mapování). Na mezisoučtu účty viset nesmí, částka by se započítala dvakrát.
--
-- Týká se jen druhového členění. Účelové členění (migrace 1161) má vlastní verzi i mapu.
--
-- Idempotentní: posuny pozic jen při prvním běhu (vzor 1664), řádky INSERT IGNORE nad
-- uq_sr_version_code, mapa INSERT IGNORE nad uq_sam a úklid rodičů DELETE.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

SET @is := (
    SELECT id FROM statement_versions
     WHERE statement_type = 'income_statement'
       AND version_code = 'vyhl500-2002/2024'
     LIMIT 1
);

SET @fresh := (
    SELECT COUNT(*) = 0 FROM statement_rows
     WHERE version_id = @is AND row_code = 'IV.1.'
);

-- ── IV. Výnosy z dlouhodobého finančního majetku - podíly ─────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @is AND row_code = 'IV.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @is AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@is, 'IV.1.', 'IV.', 'profit_loss', 'Výnosy z podílů - ovládaná nebo ovládající osoba', 2, @p + 1, 'detail', NULL),
(@is, 'IV.2.', 'IV.', 'profit_loss', 'Ostatní výnosy z podílů',                          2, @p + 2, 'detail', NULL);

-- ── V. Výnosy z ostatního dlouhodobého finančního majetku ─────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @is AND row_code = 'V.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @is AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@is, 'V.1.', 'V.', 'profit_loss', 'Výnosy z ostatního dlouhodobého finančního majetku - ovládaná nebo ovládající osoba', 2, @p + 1, 'detail', NULL),
(@is, 'V.2.', 'V.', 'profit_loss', 'Ostatní výnosy z ostatního dlouhodobého finančního majetku',                        2, @p + 2, 'detail', NULL);

-- ── VI. Výnosové úroky a podobné výnosy ──────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @is AND row_code = 'VI.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @is AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@is, 'VI.1.', 'VI.', 'profit_loss', 'Výnosové úroky a podobné výnosy - ovládaná nebo ovládající osoba', 2, @p + 1, 'detail', NULL),
(@is, 'VI.2.', 'VI.', 'profit_loss', 'Ostatní výnosové úroky a podobné výnosy',                          2, @p + 2, 'detail', NULL);

-- ── J. Nákladové úroky a podobné náklady ─────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @is AND row_code = 'J.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @is AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@is, 'J.1.', 'J.', 'profit_loss', 'Nákladové úroky a podobné náklady - ovládaná nebo ovládající osoba', 2, @p + 1, 'detail', NULL),
(@is, 'J.2.', 'J.', 'profit_loss', 'Ostatní nákladové úroky a podobné náklady',                          2, @p + 2, 'detail', NULL);

UPDATE statement_rows SET row_type = 'subtotal'
 WHERE version_id = @is AND row_code IN ('IV.', 'V.', 'VI.', 'J.');

-- ── Výchozí mapa účtů: celá do podřádku „ostatní" ────────────────────────────
INSERT IGNORE INTO statement_account_map
    (version_id, row_code, account_prefix, target, balance_condition, sign)
SELECT version_id, CONCAT(row_code, '2.'), account_prefix, target, balance_condition, sign
  FROM statement_account_map
 WHERE version_id = @is AND row_code IN ('IV.', 'V.', 'VI.', 'J.');

DELETE FROM statement_account_map
 WHERE version_id = @is AND row_code IN ('IV.', 'V.', 'VI.', 'J.');
