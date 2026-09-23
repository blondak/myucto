-- MyÚčto.cz - cenová hladina zvolená na dokladu.
--
-- Hladina odběratele (1833) určuje ceny skladových karet podle toho, KDO kupuje. Část firem
-- ale ceník volí podle obchodního případu: expresní vs. standardní objednávka, akce pro
-- konkrétní zakázku, velkoobchodní ceník pro jednorázový nákup běžného zákazníka. Doklad
-- proto může hladinu odběratele přepsat. NULL = hladina odběratele jako dosud.
--
-- Hladina slouží jen k nacenění řádků v editoru; ceny zůstávají uložené na řádcích, takže
-- změna nebo smazání hladiny vystavený doklad nemění. Bez cizího klíče stejně jako
-- `clients.price_level_id`: hladina musí patřit stejné firmě jako doklad a to hlídá aplikace.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = 1;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS price_level_id BIGINT UNSIGNED NULL;
