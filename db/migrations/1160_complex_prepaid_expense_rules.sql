-- 1160: ČÚS 019 — komplexní náklady příštích období (382 / 555)
--
-- 382 byl posledním účtem časového rozlišení, na který nemířila žádná kontace. 381, 383,
-- 384 i 385 uzávěrkový průvodce nabízí; 382 byl v osnově, ale nedalo se na něj nic
-- zaúčtovat — účetní jednotka, která komplexní náklady používá, musela mimo průvodce.
--
-- ── Čím se 382 liší od 381 ──────────────────────────────────────────────────────────
-- 381 odkládá JEDEN náklad jednoho druhu (předplatné, nájem placený dopředu). 382 odkládá
-- SOUHRN nákladů různých druhů, které spolu věcně souvisí a vztahují se k budoucímu období
-- — typicky příprava a záběh výroby, výzkum trhu před uvedením produktu, náklady na
-- dlouhodobou propagaci. Právě proto „komplexní": jednotlivé druhové náklady (materiál,
-- mzdy, služby) zůstanou na svých 5xx účtech a na 382 se převede jejich souhrn.
--
-- Protiúčtem NENÍ přímo druhový nákladový účet, ale 555 — v osnově projektu pod názvem
-- „Tvorba a zúčtování komplexních nákladů příštích období". Díky tomu zůstane druhové
-- členění nákladů ve výsledovce nedotčené a odklad se projeví jedinou souhrnnou položkou.
--
--   tvorba (odklad):    382 MD / 555 D   — souhrn nákladů se vyjme z běžného období
--   rozpuštění:         555 MD / 382 D   — v období, do kterého náklady patří
--
-- Rozpouští se podle ČÚS 019 nejpozději do čtyř let od zaúčtování; systém lhůtu nehlídá,
-- protože závisí na věcném plánu využití, který v datech není.

SET NAMES utf8mb4;

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'accrual.complex.defer', 'Komplexní náklady příštích období — odklad (382/555)', '382', '555', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'accrual.complex.defer');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'accrual.complex.release', 'Komplexní náklady příštích období — rozpuštění (555/382)', '555', '382', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'accrual.complex.release');
