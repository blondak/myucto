-- Zpracování pohybu založeného importem výpisu (převzetí z avíza, párování na doklady,
-- zaúčtování) běží až po uložení výpisu. Pohyb nese čas založení, dokud zpracování
-- nedoběhne; selhání doplní chybu. Výpis s takovým pohybem upozorní na „Znovu spárovat".
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS processing_pending_at DATETIME NULL;
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS processing_error VARCHAR(500) NULL;
