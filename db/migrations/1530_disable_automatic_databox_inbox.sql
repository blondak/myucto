-- Datová schránka se vybírá pouze na výslovnou akci přihlášeného uživatele.
-- Historický trvalý souhlas se vypíná, aby starý externí plánovač nemohl po
-- aktualizaci pokračovat v automatickém vyzvedávání.
UPDATE submission_channel_credentials
SET inbox_polling_enabled = 0,
    inbox_polling_enabled_at = NULL,
    inbox_polling_enabled_by = NULL
WHERE inbox_polling_enabled <> 0
   OR inbox_polling_enabled_at IS NOT NULL
   OR inbox_polling_enabled_by IS NOT NULL;
