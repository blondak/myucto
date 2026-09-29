-- MyÚčto — účty partnerů naučené z GPC výpisu v kanonickém tvaru.
--
-- Párování platby z GPC ukládalo protiúčet tak, jak ho výpis nese: 16 cifer
-- doplněných nulami (`0000002000145305`), migrace 1077 pak předčíslí slepené
-- s číslem (`192000145399`). Český účet se zapisuje `předčíslí-číslo` bez
-- vodicích nul (`2000145305`, `19-2000145399`); totéž dělá od teď
-- ClientBankAccountRepository při uložení (AccountNumberNormalizer::czechNational).
--
-- Mění se jen zobrazovaný `account_number` českých účtů (kód banky = 4 číslice)
-- zapsaných samými číslicemi. `account_key` zůstává, obě podoby mají stejný klíč.
-- Převedený řádek už podmínce neodpovídá, opakované spuštění nic nezmění.

SET NAMES utf8mb4;

UPDATE client_bank_accounts
   SET account_number = CONCAT_WS('-',
         NULLIF(TRIM(LEADING '0' FROM LEFT(LPAD(account_number, 16, '0'), 6)), ''),
         TRIM(LEADING '0' FROM RIGHT(LPAD(account_number, 16, '0'), 10)))
 WHERE account_number REGEXP '^[0-9]{1,16}$'
   AND bank_code REGEXP '^[0-9]{4}$'
   AND (account_number LIKE '0%' OR CHAR_LENGTH(account_number) > 10)
   AND TRIM(LEADING '0' FROM RIGHT(LPAD(account_number, 16, '0'), 10)) <> '';
