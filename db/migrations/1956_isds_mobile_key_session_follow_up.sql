-- MyÚčto.cz — dotažení doručenek v téže relaci Mobilního klíče.
--
-- Po odeslání mezd Mobilním klíčem zůstane potvrzená relace krátce (5 minut)
-- k dispozici, aby aplikace sama dotáhla doručenky odeslaných zpráv bez
-- dalšího potvrzení v mobilu. Relace se ukládá do téže tabulky jako
-- přihlašovací toky: klient drží jen náhodný token, v DB je jeho hash
-- a zašifrovaná session cookie; po vypršení se tajemství maže.
--
-- Idempotence: MODIFY COLUMN se stejnou definicí lze spustit opakovaně.

SET NAMES utf8mb4;

ALTER TABLE submission_isds_auth_flows
  MODIFY COLUMN flow_type ENUM('mobile_key','sms','mobile_key_session') NOT NULL;
