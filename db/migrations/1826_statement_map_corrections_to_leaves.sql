-- 1826: korekce rozvahy na položky, ke kterým patří, a tržby z prodeje podílů (661) k podílům
--
-- Oprávky a opravné položky visely v globální mapě jen na souhrnných řádcích (B.I., B.II.,
-- C.I., C.II.). Rozvaha ale uvádí brutto, korekci i netto u každé položky (vyhláška
-- č. 500/2002 Sb., příloha 1) a příloha přiznání k DPPO je posílá po řádcích. Podřádky pak
-- měly korekci nulovou, jejich netto nesedělo na rodiče a rozdíl skončil v jediném řádku
-- (záporné netto staveb). Účet oprávek směrné osnovy odpovídá právě jedné položce:
--   072 → B.I.1. nehmotné výsledky vývoje     081 → B.II.1.2. stavby
--   073 → B.I.2.1. software                   082 → B.II.2. hmotné movité věci
--   074 → B.I.2.2. ostatní ocenitelná práva   085 → B.II.4.1. pěstitelské celky
--   075 → B.I.3. goodwill                     086 → B.II.4.2. dospělá zvířata
--   079 → B.I.4. ostatní DNM                  089 → B.II.4.3. jiný DHM
--   098 → B.II.3. oceňovací rozdíl k nabytému majetku (097)
--   191 → C.I.1. materiál, 192/193 → C.I.2. nedokončená výroba a polotovary,
--   194 → C.I.3.1. výrobky, 195 → C.I.4. zvířata, 196 → C.I.3.2. zboží
--   391 → C.II.2.1. krátkodobé pohledávky z obchodních vztahů (opravné položky k pohledávkám
--         se v praxi tvoří k odběratelům; jiné pohledávky je nesou výjimkou firmy)
-- Opravné položky 091, 092, 096 a účet 088 mimo šablonu k jedné položce přiřadit nejde,
-- zůstávají na souhrnném řádku a příloha je rozpočítá na podřádky poměrem brutta.
--
-- 661 (tržby z prodeje cenných papírů a podílů) patří k výnosům z dlouhodobého finančního
-- majetku - podíly (IV.), stejně jako protějšek 561 u nákladů (G.). V druhovém členění jde
-- do IV.2. „ostatní" (rozpad z migrace 1824), v účelovém do III.
--
-- Idempotentní: INSERT IGNORE nad uq_sam a úklid původních záznamů DELETE.

SET NAMES utf8mb4;

SET @bs := (SELECT id FROM statement_versions WHERE statement_type = 'balance_sheet'            AND version_code = 'vyhl500-2002/2024' LIMIT 1);
SET @is := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement'         AND version_code = 'vyhl500-2002/2024' LIMIT 1);
SET @vf := (SELECT id FROM statement_versions WHERE statement_type = 'income_statement_purpose' AND version_code = 'vyhl500-2002/2024' LIMIT 1);

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
(@bs, 'B.I.1.',    '072', 'correction', 'any', 1),
(@bs, 'B.I.2.1.',  '073', 'correction', 'any', 1),
(@bs, 'B.I.2.2.',  '074', 'correction', 'any', 1),
(@bs, 'B.I.3.',    '075', 'correction', 'any', 1),
(@bs, 'B.I.4.',    '079', 'correction', 'any', 1),
(@bs, 'B.II.1.2.', '081', 'correction', 'any', 1),
(@bs, 'B.II.2.',   '082', 'correction', 'any', 1),
(@bs, 'B.II.4.1.', '085', 'correction', 'any', 1),
(@bs, 'B.II.4.2.', '086', 'correction', 'any', 1),
(@bs, 'B.II.4.3.', '089', 'correction', 'any', 1),
(@bs, 'B.II.3.',   '098', 'correction', 'any', 1),
(@bs, 'C.I.1.',    '191', 'correction', 'any', 1),
(@bs, 'C.I.2.',    '192', 'correction', 'any', 1),
(@bs, 'C.I.2.',    '193', 'correction', 'any', 1),
(@bs, 'C.I.3.1.',  '194', 'correction', 'any', 1),
(@bs, 'C.I.4.',    '195', 'correction', 'any', 1),
(@bs, 'C.I.3.2.',  '196', 'correction', 'any', 1),
(@bs, 'C.II.2.1.', '391', 'correction', 'any', 1);

DELETE FROM statement_account_map
 WHERE version_id = @bs AND target = 'correction'
   AND ((row_code = 'B.I.'  AND account_prefix IN ('072', '073', '074', '075', '079'))
     OR (row_code = 'B.II.' AND account_prefix IN ('081', '082', '085', '086', '089', '098'))
     OR (row_code = 'C.I.'  AND account_prefix IN ('191', '192', '193', '194', '195', '196'))
     OR (row_code = 'C.II.' AND account_prefix = '391'));

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
(@is, 'IV.2.', '661', 'gross', 'any', 1),
(@vf, 'III.',  '661', 'gross', 'any', 1);

DELETE FROM statement_account_map
 WHERE account_prefix = '661'
   AND ((version_id = @is AND row_code = 'VII.')
     OR (version_id = @vf AND row_code = 'VI.'));
