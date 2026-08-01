-- MyÚčto.cz — automatické naložení s čistou mzdou (zápočet na účet společníka apod.).
--
-- PROČ: mzdový zápis dnes končí čistou mzdou jako závazkem na 331 (resp. 366) a ten účet
-- se měsíc po měsíci kumuluje. Účetní ho jednou za rok uklidí ručním zápočtem proti účtu
-- společníka (v ostrých datech: 366/365 k 31. 12. 2025 za 12 351 Kč, obdobně 2024). Když
-- se odměna reálně nevyplácí, je čistší přeúčtovat ji rovnou každý měsíc — saldo 331 pak
-- vždy sedí na to, co firma opravdu dluží ze mzdy, a ne na roky nevypořádanou hromadu.
--
-- OBECNÝ ÚČET, NE PŘEPÍNAČ: sloupec drží přímo KÓD účtu z osnovy tenanta, ne enum
-- „zápočet ano/ne". Důvod je analytika — 365.100 vs 365.200 vs 479 je volba účetní, ne
-- vlastnost aplikace, a výčet hodnot by ji buď omezil, nebo by stejně skončil u kódu účtu.
-- NULL = dosavadní chování (čistá mzda zůstane viset jako závazek), takže žádná existující
-- karta se nehne a mzdové zápisy zůstávají byte-identické.
--
-- CO SE NEPOVOLUJE: peněžní účty (21x pokladna, 22x banka). Výplatu z pokladny musí zapsat
-- pokladní doklad, aby pokladní kniha (zákonná evidence) seděla s hlavní knihou, a bankovní
-- výplatu zaúčtuje párování výpisu — mzdový automat by ji zdvojil. Kontrolu vynucuje
-- PayrollEmployeeAction, ne DB: seznam prefixů je aplikační pravidlo, které se může vyvíjet,
-- a CHECK nad ním by šel měnit jen migrací.
--
-- FK na chart_of_accounts se ZÁMĚRNĚ nedává: osnova je per-tenant a klíčem je (supplier_id,
-- account_code), takže cizí klíč jen na kód by pustil účet jiné firmy. Vazbu proto ověřuje
-- aplikace se supplier scope — stejný vzor jako expense_account_code na položkách faktur.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor 1023, 1177).

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
  ADD COLUMN IF NOT EXISTS net_settlement_account_code VARCHAR(10) NULL
      COMMENT 'kód účtu, na který se měsíčně přeúčtuje čistá mzda (např. 365.100 = zápočet proti účtu společníka); NULL = ponechat jako závazek'
      AFTER child_count;
