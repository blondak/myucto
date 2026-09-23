-- XXXX: opravná položka ve výkazu následuje pohledávku, ke které patří
--
-- Globální mapa dává celou syntetiku 391 do korekce obchodních pohledávek. Opravná položka
-- k pohledávce, kterou firma výjimkou vykazuje jinde (dlouhodobá pohledávka, pohledávka za
-- ovládanou osobou), pak snížila netto špatného řádku, někdy až do záporu.
--
-- follows_prefix: výjimka s target = 'correction' může odkázat na účet pohledávky
-- (analytiku). Řádek korekce se pak bere z toho, kam výkaz zařadí tuto pohledávku, a při
-- dalším přeřazení pohledávky korekce jde s ní. row_code zůstává jako záložní řádek pro
-- případ, že účet pohledávky v mapě není.

SET NAMES utf8mb4;

ALTER TABLE statement_account_overrides
    ADD COLUMN IF NOT EXISTS follows_prefix VARCHAR(10) NULL
        COMMENT 'korekce: účet pohledávky, jejíž řádek výkazu korekce přebírá' AFTER target;
