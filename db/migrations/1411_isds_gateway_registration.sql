-- Odesílací brána ISDS (SetConcept) — registrace PROVOZOVATELE.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Čím se tahle tabulka liší od `submission_channel_credentials`
-- ─────────────────────────────────────────────────────────────────────────────
-- `submission_channel_credentials` drží systémový certifikát ZÁKAZNÍKA, tedy
-- jeden záznam na firmu. Tady je to naopak: odesílací brána je zaregistrovaná
-- k datové schránce PROVOZOVATELE aplikace a certifikát platí provozovatel.
-- Proto tu není `supplier_id` — a nesmí přibýt. Kdyby tu byl, znamenalo by to,
-- že si bránu registruje zákazník, a celý smysl téhle cesty (zákazník
-- nepotřebuje vlastní certifikát) by zmizel.
--
-- Doklad: Technická příloha 2 Provozního řádu ISDS, `odesilaci_brana_ISDS.pdf`
-- v. 1.11 (24. 6. 2026):
--   kap. 2.3 „Základním požadavkem na poskytovatele je jeho vlastní datová
--            schránka ISDS."
--   kap. 2.5 „…nutné použít komerční certifikát vydaný certifikační autoritou
--            provozovanou kvalifikovaným poskytovatelem služeb vytvářejících
--            důvěru v ČR. […] Použitý certifikát smí být zaregistrován ve
--            službě pouze jednou."
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tu certifikát vůbec je (a proč jen jako ciphertext)
-- ─────────────────────────────────────────────────────────────────────────────
-- Je to TLS klientský certifikát k `cert.datovka.gov.cz`. Kdo ho má, může
-- vkládat koncepty jménem naší brány. Do repozitáře ani do konfiguračního
-- souboru proto nepatří: ukládá se stejnou cestou jako podpisové certifikáty
-- EPO (`epo_signing_credentials`, migrace 1142) — výhradně jako výstup
-- `SecretEncryption::encryptFor()` s vlastním kontextem na pole. Sloupce se
-- jmenují `*_ciphertext` schválně; je to poslední pojistka proti tomu, aby do
-- nich někdo zapsal plaintext, a CHECK níž to i vynucuje.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- `user_login_policy` — POJMENOVANÁ NEJISTOTA, ne konfigurační vrtoch
-- ─────────────────────────────────────────────────────────────────────────────
-- Specifikace brány si v jedné a téže kapitole protiřečí v tom, jestli se do
-- `/as/login` přihlásí uživatel, který má schránku jen přes Identitu občana:
--
--   kap. 2.2 vyjmenovává „uživatelské jméno (povinný údaj), heslo (povinný
--   údaj), komerční certifikát nebo OTP nebo SMS (volitelně)" → heslo POVINNÉ,
--
--   táž kapitola ale zároveň říká „Ověření má stejné metody a úroveň ověření
--   […] jako při přihlášení do ISDS" a „Od 11.9.2016, pokud je už uživatel
--   úspěšně přihlášen do portálu ISDS, není nucen znovu zadávat přihlašovací
--   údaje a je automaticky přihlášen." → přes SSO tedy stačí jakákoliv metoda
--   portálu, včetně Identity občana.
--
--   Provozní řád (26. 6. 2026), kap. „Přihlášení Identitou občana": „Přihlášení
--   Identitou občana je možné jen v prostředí Klientského portálu ISDS.
--   Z prostředí aplikací třetích stran (přihlášení pomocí webových služeb) není
--   tato autentizační metoda podporována." — přihlašovací stránka brány běží
--   V PERIMETRU ISDS, takže se na ni ta výluka podle znění nevztahuje; výslovně
--   to ale nikde napsané není.
--
-- Rozhodnout to lze jedině pokusem proti zaregistrované bráně (bez `atsId`
-- vrací `/as/login` HTTP 404, takže se to nedá zjistit ani zvenčí). Sloupec
-- proto NENÍ „nastavení chování" — kód se podle něj chová stejně. Určuje jen,
-- co se uživateli napíše, než ho pošleme na přihlášení. Výchozí hodnota
-- 'unknown' říká pravdu: nevíme.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS isds_gateway_registrations (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  environment              ENUM('production','test') NOT NULL,

  -- Veřejné údaje registrace. `ats_id` přiděluje ISDS při registraci brány
  -- v portálu (Nastavení → Externí aplikace → Odesílací brána).
  ats_id                   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label                    VARCHAR(120) NOT NULL,
  -- Návratové URL je nastavené v ISDS, ne u nás; tady se drží proto, aby šlo
  -- poznat rozjetou konfiguraci dřív, než se uživatel ztratí v přesměrování.
  return_url               VARCHAR(500) NOT NULL,
  error_url                VARCHAR(500) NULL,
  -- Doba, po kterou ISDS drží koncept ke schválení (nastavuje se v registraci).
  -- Naše relace nesmí žít déle než tahle hodnota — jinak bychom uživatele
  -- posílali schvalovat koncept, který už neexistuje.
  concept_ttl_seconds      INT UNSIGNED NOT NULL DEFAULT 900,

  -- Hostitelé prostředí. V dokumentaci jsou jako `[url-adresa-prostředí-isds]`
  -- (kap. 1.2): produkce `datovka.gov.cz`, veřejný test `datovka-test.gov.cz`.
  -- Konfigurovatelné schválně: staré domény `mojedatovaschranka.cz` běží podle
  -- Provozního řádu souběžně minimálně do 31. 12. 2027 a WSDL v příloze je má
  -- pořád zapsané natvrdo.
  portal_host              VARCHAR(190) NOT NULL,
  service_host             VARCHAR(190) NOT NULL COMMENT 'cert.<prostředí> — SOAP endpointy brány',

  -- Viz rozsáhlý komentář v hlavičce migrace.
  user_login_policy        ENUM('unknown','password_required','portal_sso_or_password')
                             NOT NULL DEFAULT 'unknown'
                             COMMENT 'Nedoložené: umí /as/login Identitu občana? Ovlivňuje JEN text pro uživatele.',

  certificate_ciphertext   MEDIUMTEXT NOT NULL,
  certificate_passphrase_ciphertext TEXT NULL,
  certificate_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  certificate_valid_to     DATETIME NULL,

  is_active                TINYINT(1) NOT NULL DEFAULT 0
                             COMMENT 'Vypnuto po uložení schválně — zapíná se až po ověření ve veřejném testu',

  created_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Jedna brána na prostředí. Dvě aktivní registrace by znamenaly, že nikdo
  -- neví, pod kterým certifikátem koncept odešel.
  UNIQUE KEY uq_isds_gateway_registration_env (environment),

  CONSTRAINT fk_isds_gateway_registration_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Omezení se přidávají zvlášť a vždy po `DROP … IF EXISTS`: MariaDB neumí
-- `ADD CONSTRAINT IF NOT EXISTS` u CHECK, takže bez toho by druhé spuštění
-- migrace spadlo na duplicitní jméno.
ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_ats;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_ats
    CHECK (ats_id REGEXP '^[A-Za-z0-9._:-]{1,64}$');

-- Plaintext v ciphertext sloupci: `SecretEncryption` razítkuje `enc:v1:`/`enc:v2:`.
ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_cert_encrypted;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_cert_encrypted
    CHECK (certificate_ciphertext LIKE 'enc:v%');

ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_pass_encrypted;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_pass_encrypted
    CHECK (certificate_passphrase_ciphertext IS NULL OR certificate_passphrase_ciphertext LIKE 'enc:v%');

-- Přesměrování uživatele smí jít jen na HTTPS. Brána sama to podle kap. 3.1
-- bodu 1 vyžaduje, ale spolehnout se na druhou stranu tady nemá cenu: hodnotu
-- si nastavujeme sami a překlep by poslal `sessionId` po nešifrovaném spojení.
ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_return_url;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_return_url
    CHECK (return_url LIKE 'https://%');

ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_error_url;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_error_url
    CHECK (error_url IS NULL OR error_url LIKE 'https://%');

-- Životnost konceptu v ISDS je konfigurovaná v registraci; hodnoty mimo rozumný
-- rozsah znamenají překlep, ne nastavení.
ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_ttl;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_ttl
    CHECK (concept_ttl_seconds BETWEEN 60 AND 7200);

ALTER TABLE isds_gateway_registrations DROP CONSTRAINT IF EXISTS chk_isds_gateway_registration_hosts;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_isds_gateway_registration_hosts
    CHECK (
      portal_host REGEXP '^[a-z0-9.-]{4,190}$'
      AND service_host REGEXP '^[a-z0-9.-]{4,190}$'
    );
