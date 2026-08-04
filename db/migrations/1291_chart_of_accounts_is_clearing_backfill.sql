-- MyÚčto.cz — doznačit zúčtovací účty firmám založeným PO migraci 1112.
--
-- Migrace 1112 sloupec `is_clearing` zavedla a označila účty, které v tu chvíli
-- existovaly. `ChartOfAccountsSeeder` ho ale nikdy neplnil, takže každá firma
-- naseedovaná od té doby má `is_clearing = 0` na všech účtech.
--
-- Důsledek nebyl planý poplach, ale opak: invariant I20 (ČÚS 017 — zúčtovací účty
-- jsou k rozvahovému dni nulové) i uzávěrková kontrola `clearing_accounts_open`
-- nad takovou firmou nemají nač se ptát, takže vždy projdou. Tichá slepota kontroly
-- je horší než její absence — hlášení je zelené, protože se nekontroluje nic.
--
-- Seeder je opravený (ChartOfAccountsSeeder::markClearingAccounts), tahle migrace
-- dohání existující data. Prefixy jsou tytéž jako v 1112. Idempotentní: sahá jen
-- na účty, které příznak ještě nemají, takže vědomé odznačení uživatelem nevrací.

SET NAMES utf8mb4;

UPDATE chart_of_accounts
   SET is_clearing = 1
 WHERE is_clearing = 0
   AND (account_code LIKE '041%'
     OR account_code LIKE '042%'
     OR account_code LIKE '111%'
     OR account_code LIKE '131%'
     OR account_code LIKE '139%'
     OR account_code LIKE '261%'
     OR account_code LIKE '314%'
     OR account_code LIKE '324%'
     OR account_code LIKE '395%');
