-- MyÚčto.cz — volba metody ÚČETNÍCH odpisů na kartě majetku.
--
-- Dosud šly účetní odpisy vyjádřit jen rovnoměrně po měsících (acc_useful_life_months).
-- §28 ZoÚ velí, aby účetní odpisy vyjadřovaly skutečné opotřebení, ale u malých účetních
-- jednotek je běžná a přípustná zjednodušující politika „účetní odpis = daňový odpis":
-- nižší vypovídací schopnost výměnou za jednu jedinou evidenci odpisů. Bez ní se rozvaha
-- a výsledek hospodaření rozcházejí s tím, co účetní reálně vykazuje.
--
-- Daňový odpis je ROČNÍ SAZBA z VC (§31: 11 % 1. rok, 22,25 % roky 2–5 u skupiny 2) bez
-- ohledu na měsíc zařazení — lineární měsíční odpis ho napodobit NEDOKÁŽE. Proto volba,
-- ne jen jiné číslo v acc_useful_life_months.
--
-- DEFAULT 'straight_line' zachovává dosavadní chování všech existujících karet.

SET NAMES utf8mb4;

ALTER TABLE assets
  ADD COLUMN acc_method ENUM('straight_line','by_tax') NOT NULL DEFAULT 'straight_line'
      COMMENT 'metoda účetních odpisů: rovnoměrně po měsících | shodně s daňovým odpisem'
      AFTER acc_useful_life_months;
