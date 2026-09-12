-- Mapa výkazů pro účty směrné osnovy, které šablona MyÚčta nemá
--
-- Převod účetnictví z jiného programu zakládá syntetické účty podle tamní osnovy. Když
-- je mapa výkazů nezná, jejich zůstatek ve výkazech chybí a rozvaha nesouhlasí
-- (aktiva ≠ pasiva). Doplňují se účty směrné osnovy a účty starší osnovy (před rokem
-- 2016), které se v převáděných agendách běžně vyskytují:
--   094       opravná položka k nedokončenému DHM          → B.II.5.2. (korekce)
--   260       převody mezi finančními účty                  → jako 261 (peníze na cestě)
--   350 / 360 pohledávky / závazky ke společníkům (stará osnova)
--   374–377   pohledávky z pronájmu, z vydaných dluhopisů, nakoupené a prodané opce
--   600, 640  souhrnné výnosové skupiny starší osnovy      → I., III.3.
--   611–614   změna stavu zásob vlastní činnosti (stará osnova, výnosy) → B. se znaménkem −1
--   621–624   aktivace (stará osnova, výnosy)             → C. se znaménkem −1
--   667       výnosy z derivátových operací               → VII.
-- Řádek B./C. je nákladový: výnos 61x/62x (kreditní zůstatek = přírůstek zásob) ho snižuje,
-- proto sign −1 (mapper dává výnosu −zůstatek, viz StatementMapper).
--
-- Idempotentní: INSERT IGNORE nad uq_sam (version_id, row_code, account_prefix, balance_condition).

SET NAMES utf8mb4;

SET @bs := (SELECT id FROM statement_versions WHERE statement_type = 'balance_sheet'    AND version_code = 'vyhl500-2002/2024');
SET @is := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement' AND version_code = 'vyhl500-2002/2024');

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
(@bs, 'B.II.5.2.',   '094', 'correction', 'any',    1),
(@bs, 'C.IV.1.',     '260', 'gross',      'debit',  1),
(@bs, 'P.C.II.8.7.', '260', 'gross',      'credit', 1),
(@bs, 'C.II.2.4.1.', '350', 'gross',      'any',    1),
(@bs, 'P.C.II.8.1.', '360', 'gross',      'any',    1),
(@bs, 'C.II.2.4.6.', '374', 'gross',      'any',    1),
(@bs, 'C.II.2.4.6.', '375', 'gross',      'any',    1),
(@bs, 'C.II.2.4.6.', '376', 'gross',      'debit',  1),
(@bs, 'P.C.II.8.7.', '376', 'gross',      'credit', 1),
(@bs, 'C.II.2.4.6.', '377', 'gross',      'debit',  1),
(@bs, 'P.C.II.8.7.', '377', 'gross',      'credit', 1);

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
(@is, 'I.',     '600', 'gross', 'any',  1),
(@is, 'III.3.', '640', 'gross', 'any',  1),
(@is, 'B.',     '611', 'gross', 'any', -1),
(@is, 'B.',     '612', 'gross', 'any', -1),
(@is, 'B.',     '613', 'gross', 'any', -1),
(@is, 'B.',     '614', 'gross', 'any', -1),
(@is, 'C.',     '621', 'gross', 'any', -1),
(@is, 'C.',     '622', 'gross', 'any', -1),
(@is, 'C.',     '623', 'gross', 'any', -1),
(@is, 'C.',     '624', 'gross', 'any', -1),
(@is, 'VII.',   '667', 'gross', 'any',  1);
