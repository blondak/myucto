-- MyÚčto.cz — bezpečné defaulty bankovních e-mailových avíz (security report R3).
--
-- Tři nezávislé věci:
--   1) vypnout seedovaného globálního providera ČS, který věří komukoliv,
--   2) tabulka per-supplier override, aby šel globální provider vypnout z produktu,
--   3) `require_email_auth` (DKIM/DMARC) na fail-closed default pro NOVÉ účty.
--
-- Kontext: `0098_seed_ceska_sporitelna_provider.sql` naseedoval GLOBÁLNÍ
-- (`supplier_id IS NULL`), ZAPNUTÝ regex provider s prázdným `sender_whitelist`.
-- Parser bral prázdný whitelist jako „povolit vše", takže jediným filtrem zůstal
-- veřejně opsatelný `body_pattern`. Kdokoliv mohl poslat e-mail do sledované
-- schránky a označit cizí fakturu za zaplacenou. Parser je nově fail-closed
-- (`AbstractBankEmailNoticeParser::senderAllowed`), tahle migrace uklízí data.

SET NAMES utf8mb4;

-- 1) Seedovaný globální provider ČS: vypnout, dokud nemá whitelist odesílatele.
--
-- Reálnou notifikační adresu ČS nehádáme — operátor si doplní whitelist u vlastní
-- kopie provideru (tlačítko Duplikovat) nebo si providera zapne přes per-supplier
-- override níže. Idempotentní a NEPŘEPÍŠE řádek, který si operátor mezitím sám
-- opravil: cílíme výhradně na řádek pořád v původním nebezpečném stavu
-- (globální, zapnutý, bez whitelistu i bez patternu předmětu).
UPDATE bank_email_notice_providers
   SET enabled = 0
 WHERE supplier_id IS NULL
   AND code = 'ceska-sporitelna'
   AND enabled = 1
   AND (sender_whitelist IS NULL OR TRIM(sender_whitelist) = '')
   AND (subject_pattern IS NULL OR TRIM(subject_pattern) = '');

-- 2) Per-supplier override globálního provideru.
--
-- Globální providera nevlastní žádný dodavatel, takže ho `saveProvider()` odmítalo
-- editovat a nešel z produktu zakázat ani superadminem. Override řádek přebíjí
-- `enabled` jen pro jednoho dodavatele; globální definice zůstává společná.
CREATE TABLE IF NOT EXISTS bank_email_notice_provider_overrides (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  provider_id   BIGINT UNSIGNED NOT NULL,
  enabled       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_benpo_supplier_provider (supplier_id, provider_id),
  KEY idx_benpo_provider (provider_id),
  CONSTRAINT fk_benpo_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_benpo_provider FOREIGN KEY (provider_id) REFERENCES bank_email_notice_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Ověření autenticity e-mailu (DKIM/DMARC) se zapíná pro NOVÉ IMAP účty.
--
-- EXISTUJÍCÍ účty se ZÁMĚRNĚ NEPŘEKLÁPÍ. Překlopení by komukoliv, komu přijímací
-- server hlavičku `Authentication-Results` nepřidává, ze dne na den zastavilo
-- zpracování avíz (skončila by ve stavu `security_rejected`) — na to je tohle
-- příliš velká regrese. Na existujících instalacích zůstává kontrola volbou
-- operátora v nastavení IMAP účtu; chrání je fail-closed `senderAllowed()` z bodu
-- 1 a vypnutý seedovaný provider, což byl skutečný vektor nálezu.
ALTER TABLE bank_email_imap_settings
  MODIFY COLUMN require_email_auth TINYINT(1) NOT NULL DEFAULT 1;
