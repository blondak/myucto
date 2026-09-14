-- MyÚčto.cz - zahození nezaktivovaných TOTP secretů.
--
-- Do této verze šlo `POST /api/auth/totp/setup` zavolat z jakékoli přihlášené
-- session bez opětovného prokázání identity, takže rozpracovaný (totp_enabled = 0)
-- secret mohl založit kdokoli s unesenou session a znát ho. Od 1841 zřízení TOTP
-- vyžaduje heslo nebo step-up proof a enable() aktivuje jen secret z takto
-- autorizovaného setup(); staré pending secrety se proto zahazují. Aktivní
-- faktory (totp_enabled = 1) se nemění. Idempotentní: opakovaný běh nemá co změnit.
UPDATE users SET totp_secret = NULL WHERE totp_enabled = 0 AND totp_secret IS NOT NULL;
