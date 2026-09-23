-- Výchozí firma uživatele
--
-- PROČ: bez uložené volby server při požadavku bez `X-Supplier-Id` (nový
-- prohlížeč, jiné zařízení, API klient) otevíral firmu s nejnižším id. U účetní
-- s desítkami firem to bývá zkušební nebo dávno nepoužívaná firma. Výchozí
-- firma teď patří k účtu, ne k prohlížeči:
--
--   NULL          = uživatel ještě žádnou nemá; při prvním požadavku bez výběru
--                   ji server jednorázově zvolí (přístupná firma s nejvíc doklady)
--                   a uloží, viz DefaultSupplierService.
--   vyplněné id   = výchozí firma; mění ji přepínač firem ve frontendu.
--
-- Proč sloupec na `users` a ne `user_preferences`: ta tabulka drží rozvržení
-- tabulek (prefix whitelist `table.<page_key>`) a nemá vazbu na `supplier`.
-- Tady je cizí klíč potřeba: smazání firmy musí volbu vynulovat, ne nechat
-- ukazovat do prázdna.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS default_supplier_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'výchozí firma uživatele; NULL = ještě nezvolená, server ji jednorázově předvolí';

ALTER TABLE users
    ADD CONSTRAINT fk_users_default_supplier FOREIGN KEY IF NOT EXISTS (default_supplier_id)
        REFERENCES supplier(id) ON DELETE SET NULL;
