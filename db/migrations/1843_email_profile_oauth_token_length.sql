-- Odesílací profil s autentizací XOAUTH2 ukládá do `smtp_password_enc` přístupový
-- token, ne heslo. Token z Entra ID (Microsoft 365) má běžně jednotky tisíc znaků,
-- takže se do VARCHAR(512) nevešel ani v otevřené podobě, natož zašifrovaný —
-- uložení skončilo na „Pole 'smtp_password' je příliš dlouhé." (issue #70).
-- TEXT je tady zavedený typ pro tokeny, viz `fakturoid_access_token_enc`
-- v migraci 0046_fakturoid_oauth2.sql. IMAP strana tokeny neumí (nemá auth_type),
-- proto zůstává beze změny.

ALTER TABLE email_profiles MODIFY COLUMN smtp_password_enc TEXT NULL;
