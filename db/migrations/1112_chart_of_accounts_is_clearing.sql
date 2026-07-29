-- MyÚčto.cz — příznak zúčtovacího (průběžného / suspenzního) účtu na rozvrhu (audit K1).
--
-- PROČ: jednotlivé zúčtovací účty se dnes v uzávěrce kontrolují AD-HOC, každý natvrdo
-- svým kódem (261 „peníze na cestě", 041/042 pořízení majetku, 111/131 pořízení
-- materiálu/zboží, 395 vnitřní zúčtování, 314/324 zálohy). Nová firma s vlastní osnovou
-- nebo účet přidaný ručně se do kontrol nedostane. Příznak `is_clearing` z toho dělá
-- vlastnost ROZVRHU, ne konstantu v kódu: generický uzávěrkový check `clearing_accounts_open`
-- pak hlídá zůstatek VŠECH takto označených účtů k rozvahovému dni (rozšíří dnešní ad-hoc
-- i na 314/324 a cokoli dalšího, co je průběžné).
--
-- Zůstatek se k rozvahovému dni MÁ blížit nule, nebo být doložený (nedočerpaná záloha,
-- pořízení na cestě). Proto jen WARNING (ne error) — účetní ověří, že jde o legitimní
-- otevřenou položku, ne o zapomenutý průběžný zůstatek. Příznak NEMĚNÍ žádné zůstatky
-- ani zaúčtování — je to čistě evidenční metadata pro kontrolu (surfacing).
--
-- DEFAULT 0 zachovává chování všech ostatních účtů beze změny.

SET NAMES utf8mb4;

ALTER TABLE chart_of_accounts
  ADD COLUMN is_clearing TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'zúčtovací/průběžný účet — k rozvahovému dni má být vyrovnaný nebo doložený (261/04x/111/131/139/395/314/324)'
      AFTER tax_deductibility;

-- Přednastav typické zúčtovací syntetiky i jejich analytiky (prefix match). Účelově NE
-- 191 (opravná položka k materiálu — reálné ocenění, ne průběžný účet) ani 379 (jiné
-- závazky — skutečný závazek). Jde jen o účty, které mají k D „projít" na nulu / být
-- doložené.
UPDATE chart_of_accounts
   SET is_clearing = 1
 WHERE account_code LIKE '261%'   -- peníze na cestě
    OR account_code LIKE '041%'   -- pořízení dlouhodobého nehmotného majetku
    OR account_code LIKE '042%'   -- pořízení dlouhodobého hmotného majetku
    OR account_code LIKE '111%'   -- pořízení materiálu
    OR account_code LIKE '131%'   -- pořízení zboží
    OR account_code LIKE '139%'   -- zboží na cestě
    OR account_code LIKE '395%'   -- vnitřní zúčtování
    OR account_code LIKE '314%'   -- poskytnuté zálohy
    OR account_code LIKE '324%';  -- přijaté provozní zálohy
