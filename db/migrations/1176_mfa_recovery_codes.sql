-- 1176: záložní jednorázové kódy pro MFA (break-glass)
--
-- PROČ: aplikace neměla žádnou self-service cestu ven ze ztraceného faktoru.
-- Kdo měl jen passkey a přišel o zařízení, dostal na loginu tvrdé odmítnutí — heslo
-- samo nestačí bez ohledu na `require_mfa` a e-mailové OTP se takovému účtu ani
-- nenabídne. Reset hesla MFA záměrně nemaže (a nemá), superadmin reset z aplikace
-- neexistuje, takže jediným východiskem byl shell na serveru:
-- `php api/bin/reset-mfa.php <email>`. U jednouživatelské instalace to znamená,
-- že se majitel nedostane do vlastního účetnictví, dokud nemá přístup k serveru.
--
-- ── Proč hash a ne šifra ────────────────────────────────────────────────────────
-- Kód se ukládá jako SHA-256, stejně jako step-up proofy (`mfa_step_up_proofs`).
-- Ověřuje se rovností hashe, ne porovnáním plaintextu, takže z DB dumpu kódy
-- vytáhnout nejde. Rychlá hash je tu na místě: kód není heslo volené člověkem,
-- nese ~50 bitů entropie z CSPRNG a offline útok na něj nemá o co se opřít.
-- Plaintext existuje jen v odpovědi na požadavek generování — server si ho nikde
-- neponechává, a proto ho také nelze zobrazit podruhé.
--
-- ── Proč `used_at` a ne DELETE ──────────────────────────────────────────────────
-- Spotřebovaný kód zůstává v tabulce označený časem a IP. Jednorázovost drží
-- atomický `UPDATE … WHERE used_at IS NULL` (rowCount rozhodne, kdo vyhrál závod),
-- ne existence řádku — a hlavně: použití break-glass kódu je bezpečnostní událost,
-- kterou chce mít člověk dohledatelnou i zpětně. Smazání řádku by tu stopu zahodilo.
--
-- UNIQUE (user_id, code_hash) brání tomu, aby jedna dávka obsahovala tentýž kód
-- dvakrát (kolize CSPRNG je nepravděpodobná, ale tichý duplikát by uživateli ubral
-- jeden kód z deseti, aniž by to kdokoli poznal).
--
-- Regenerace maže CELOU dosavadní sadu: nechat vedle sebe dvě sady by znamenalo, že
-- se uživatel spolehne na vytištěný nový list, zatímco starý zůstane platný.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    code_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 normalizovaného kódu (velká písmena, bez oddělovačů)',
    used_at     DATETIME NULL COMMENT 'NULL = nepoužitý; jednorázovost drží atomický UPDATE',
    used_ip     VARBINARY(16) NULL COMMENT 'inet_pton IP, ze které byl kód uplatněn',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mrc_user_code (user_id, code_hash),
    KEY idx_mrc_user_unused (user_id, used_at),
    CONSTRAINT fk_mrc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
