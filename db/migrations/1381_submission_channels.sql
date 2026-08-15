-- Průřezový kanál podání — jedna odchozí a jedna příchozí cesta pro VŠECHNY
-- agendy (DPH, KH, SH, DPPO, přehledy ZP, mzdová podání ČSSZ), ne jen pro mzdy.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč dvě nezávislé stavové osy (a ne jeden `status`)
-- ─────────────────────────────────────────────────────────────────────────────
-- `tax_submissions.status` slévá dopravu a vyřízení do jedné škály
-- ('submitted' → 'accepted'), takže na ní nejde vyjádřit, co datová schránka
-- reálně vrací: doručenku. Doručenka je důkaz, že zpráva DORAZILA do schránky
-- úřadu — není to důkaz, že ji úřad zpracoval a přijal. Kdo obojí spojí, vyrobí
-- podání, které se tváří jako přijaté a přitom o něm úřad nic nerozhodl.
--
-- Proto tu jsou osy dvě:
--   `dispatch_state`   — doprava: co víme o cestě zprávy ke schránce příjemce
--   `acceptance_state` — vyřízení: co o podání rozhodl ÚŘAD
--
-- Schéma tu neslouží jen k popisu, ale k vynucení: `acceptance_state` se smí
-- pohnout z 'unknown' jen společně s `acceptance_evidence_kind`, a ten NEMÁ
-- hodnotu pro doručenku. Doručenka tedy nejde zapsat jako důkaz o přijetí ani
-- omylem, ani ručním UPDATE — pro takový zápis prostě neexistuje slovo.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč `send_uncertain`
-- ─────────────────────────────────────────────────────────────────────────────
-- Timeout uprostřed odeslání není chyba ani úspěch — je to nevědomost. Kdyby
-- spadl do 'failed', uživatel odešle podruhé a úřad dostane duplicitu; kdyby
-- spadl do 'sent', ztratíme podání, které nikdy neodešlo. Vlastní stav drží
-- pravdu ("nevíme") a vede k dohledání přes `correlation_reference`, který se
-- do zprávy razítkuje PŘED odesláním (u ISDS jako dmSenderRefNumber).

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Registr příjemců — číselník datových schránek institucí
-- ─────────────────────────────────────────────────────────────────────────────
-- `source_url` je povinné u každého záznamu s vyplněným ID schránky: číselník,
-- do kterého lze zapsat neověřené ID, je horší než prázdný číselník. Podání
-- odeslané na špatnou schránku je z pohledu lhůty nepodané.
CREATE TABLE IF NOT EXISTS submission_recipients (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NULL COMMENT 'NULL = systémový záznam sdílený všemi firmami',
  code              VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name              VARCHAR(190) NOT NULL,
  kind              ENUM('tax_office','cssz','health_insurer','other') NOT NULL,
  isds_box_id       CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
  source_url        VARCHAR(500) NULL COMMENT 'Odkud je ID doložené — bez dokladu se ID neukládá',
  source_note       VARCHAR(500) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  -- Číselník není zdroj pravdy, ISDS je. Tady se drží poslední úspěšné ověření
  -- schránky dotazem (checkDataBox / findDataBox2), aby šlo poznat, že záznam
  -- nikdo neověřoval roky.
  verified_in_isds_at TIMESTAMP NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_recipients_code (supplier_id, code),
  KEY idx_submission_recipients_kind (kind, is_active),
  KEY idx_submission_recipients_box (isds_box_id),

  CONSTRAINT fk_submission_recipients_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_recipients_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,

  -- ID datové schránky je 7 znaků [a-z0-9]; delší/kratší je překlep, ne schránka.
  CONSTRAINT chk_submission_recipients_box
    CHECK (isds_box_id IS NULL OR isds_box_id REGEXP '^[a-z0-9]{7}$'),
  -- Doklad je podmínkou existence ID, ne volitelnou poznámkou.
  CONSTRAINT chk_submission_recipients_source
    CHECK (isds_box_id IS NULL OR source_url IS NOT NULL),
  CONSTRAINT chk_submission_recipients_code
    CHECK (code REGEXP '^[a-z][a-z0-9_]{1,47}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Přístup ke kanálu (datová schránka) — VÝHRADNĚ systémový certifikát
-- ─────────────────────────────────────────────────────────────────────────────
-- Stejná cesta jako `epo_signing_credentials` (1142): v tabulce žije výhradně
-- ciphertext ze `SecretEncryption::encryptFor()` s vlastním kontextem. Sloupce
-- se jmenují `*_ciphertext` schválně — jméno sloupce je poslední pojistka proti
-- tomu, aby do něj někdo napsal plaintext, a zároveň to, co uvidí každý, kdo se
-- podívá do `SHOW CREATE TABLE`.
--
-- ⚠️ PROČ TU NENÍ JMÉNO A HESLO ⚠️
-- Není to zjednodušení, je to právní překážka. Podle § 9 odst. 2 zák. 300/2008 Sb.
-- a Provozního řádu ISDS nesmí přístupové údaje ke schránce opustit zařízení pod
-- plnou kontrolou uživatele; jejich předání cloudové aplikaci třetí strany je
-- porušením podmínek a Správce ISDS je oprávněn údaje zneplatnit. Sloupce
-- `login_ciphertext` / `password_ciphertext` proto v téhle tabulce NIKDY nebyly
-- a nemají vzniknout ani později — ani „dočasně", ani „jen pro testovací
-- prostředí". Jediná průchozí cesta je systémový certifikát (PKCS#12).
CREATE TABLE IF NOT EXISTS submission_channel_credentials (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  environment              ENUM('production','test') NOT NULL,
  channel                  ENUM('isds') NOT NULL COMMENT 'EPO má vlastní trezor (epo_signing_credentials)',
  label                    VARCHAR(120) NOT NULL,
  box_id                   CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Naše schránka (odesílatel) — veřejný údaj, nešifruje se',
  -- Jednohodnotový ENUM je zpráva pro čtenáře schématu: jiný způsob přihlášení
  -- není „zatím neimplementovaný", ale nepřípustný (viz komentář výše).
  auth_mode                ENUM('certificate') NOT NULL DEFAULT 'certificate',
  certificate_ciphertext   MEDIUMTEXT NOT NULL,
  certificate_passphrase_ciphertext TEXT NULL,
  certificate_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  certificate_valid_to     DATETIME NULL,
  last_verified_at         DATETIME NULL COMMENT 'Kdy naposledy prošlo přihlášení — ne kdy se uložilo',

  -- ─── § 17 odst. 3 zák. 300/2008 Sb. ───
  -- Vyzvednutí seznamu přijatých zpráv (GetListOfReceivedMessages) je přihlášení
  -- do schránky, a tím DORUČENÍ všech dodaných zpráv. Rozjíždí zákonné lhůty.
  -- Automatické vybírání schránky proto NENÍ neutrální čtecí operace a nesmí být
  -- zapnuté ve výchozím stavu — kdo si aplikaci nainstaluje, nesmí zjistit až
  -- z propadlé lhůty, že mu ji software doručil sám.
  inbox_polling_enabled    TINYINT(1) NOT NULL DEFAULT 0,
  inbox_polling_enabled_at DATETIME NULL,
  inbox_polling_enabled_by BIGINT UNSIGNED NULL,

  created_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Bez soft-delete schválně: `deleted_at` v UNIQUE klíči MariaDB nehlídá
  -- (NULL se neporovnává), takže by šlo mít dvě aktivní přihlášení pro tutéž
  -- schránku a nikdo by nevěděl, kterým se odesílá. Smazání je smazání.
  UNIQUE KEY uq_submission_credentials_scope (supplier_id, channel, environment),
  KEY idx_submission_credentials_polling (channel, inbox_polling_enabled),

  CONSTRAINT fk_submission_credentials_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_credentials_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  -- RESTRICT, ne SET NULL: souhlas s vybíráním schránky je právně významný úkon
  -- a musí zůstat dohledatelný i po odchodu uživatele z firmy. (SET NULL by
  -- navíc rozbil CHECK níž — MariaDB nedovolí CHECK nad sloupcem, který mění
  -- FK akce, chyba 1901.)
  CONSTRAINT fk_submission_credentials_polling_user
    FOREIGN KEY (inbox_polling_enabled_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_submission_credentials_box
    CHECK (box_id REGEXP '^[a-z0-9]{7}$'),
  -- Plaintext v ciphertext sloupci: `SecretEncryption` razítkuje `enc:v2:`
  -- (kontextová varianta) nebo `enc:v1:`. Cokoliv jiného je nešifrovaný údaj.
  CONSTRAINT chk_submission_credentials_certificate_encrypted
    CHECK (certificate_ciphertext LIKE 'enc:v%'),
  CONSTRAINT chk_submission_credentials_passphrase_encrypted
    CHECK (certificate_passphrase_ciphertext IS NULL OR certificate_passphrase_ciphertext LIKE 'enc:v%'),
  -- Zapnuté vybírání schránky je právně významný úkon. Bez záznamu, KDO ho
  -- zapnul a KDY, by za doručení fikcí nešlo nikoho označit.
  CONSTRAINT chk_submission_credentials_polling_consent
    CHECK (
      inbox_polling_enabled = 0
      OR (inbox_polling_enabled_at IS NOT NULL AND inbox_polling_enabled_by IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Odchozí fronta
-- ─────────────────────────────────────────────────────────────────────────────
-- Artefakt se NEKOPÍRUJE — řádek nese odkaz (`artifact_kind` + `artifact_id`)
-- a otisk (`artifact_sha256`). Otisk je tu proto, aby šlo poznat, že se zdrojové
-- XML mezi přípravou a potvrzením změnilo; kopie by naopak umožnila odeslat něco
-- jiného, než co uživatel v aplikaci vidí.
CREATE TABLE IF NOT EXISTS submission_outbox (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  environment             ENUM('production','test') NOT NULL,
  channel                 ENUM('epo','isds') NOT NULL,
  agenda_code             VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                            COMMENT 'DPHDP3, DPHKH1, DPHSHV, DPPO, JMHZ, HOZ, PPPZ…',
  recipient_id            BIGINT UNSIGNED NULL COMMENT 'NULL u EPO — příjemce je dán bránou',
  recipient_box_id        CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL
                            COMMENT 'Snapshot v čase zařazení — číselník se smí později změnit',
  subject                 VARCHAR(255) NOT NULL,

  artifact_kind           ENUM('payroll_submission','tax_submission','document') NOT NULL,
  artifact_id             BIGINT UNSIGNED NOT NULL,
  artifact_filename       VARCHAR(255) NOT NULL,
  artifact_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,

  dispatch_state          ENUM(
    'ready','sending','send_uncertain','sent','delivered','failed','cancelled'
  ) NOT NULL DEFAULT 'ready',
  acceptance_state        ENUM('unknown','accepted','rejected') NOT NULL DEFAULT 'unknown',
  -- Slovník důkazů o vyřízení. Doručenka tu ZÁMĚRNĚ nemá hodnotu: nedokazuje
  -- zpracování, jen doručení. Bez slova pro ni nejde omylem zapsat.
  acceptance_evidence_kind ENUM('epo_protocol','agency_protocol_message','manual_confirmation') NULL,
  acceptance_note         VARCHAR(500) NULL,

  idempotency_key_hash    BINARY(32) NOT NULL,
  -- Jde do `dmSenderIdent` odchozí zprávy, a to má tvrdý limit 50 znaků.
  -- ISDS žádný idempotency token nemá, takže tohle je JEDINÁ stopa, podle které
  -- jde po timeoutu zjistit, jestli zpráva odešla. Delší hodnota by se v ISDS
  -- ořízla a dohledání by přestalo fungovat právě ve chvíli, kdy je potřeba.
  correlation_reference   VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                            COMMENT 'dmSenderIdent — razítkuje se do zprávy PŘED odesláním; max 50 znaků dle ISDS',
  external_message_id     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'dmID u ISDS',

  -- ─── Dvě brány, které musí projít PŘED odesláním ───
  -- ISDS nevaliduje obsah příloh vůbec a chyby přijdou až po dnech jako výzva
  -- k odstranění vad podle § 74 DŘ. Lokální kontrola proti XSD je jediná
  -- náhrada za chybějící kontrolu na druhé straně.
  artifact_validation_status ENUM('passed','failed','skipped') NULL,
  artifact_validated_at      DATETIME NULL,
  -- Číselník schránek stárne (seznam Finanční správy je z roku 2023). ISDS je
  -- autoritativní: ověření schránky dotazem odchytí zrušenou nebo znepřístupněnou
  -- schránku dřív, než do ní pošleme přiznání.
  recipient_box_verified_at  DATETIME NULL,

  confirmed_by            BIGINT UNSIGNED NULL COMMENT 'Odeslání vždy potvrzuje člověk — automat smí jen připravit',
  confirmed_at            DATETIME NULL,
  sent_at                 DATETIME NULL,
  delivered_at            DATETIME NULL,
  accepted_at             DATETIME NULL,
  rejected_at             DATETIME NULL,
  failed_at               DATETIME NULL,
  last_error_code         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  last_error_message      VARCHAR(500) NULL,

  -- Důkazní balíček podání = doručenka + dmID + otisk odeslaného XML.
  -- `receipt_signature_status` je záměrně 'unverified' ve výchozím stavu:
  -- ZfoExtractor podpis NEOVĚŘUJE, jen extrahuje obsah, a knihovna ISDS
  -- neověřuje podpisy ani otisky vůbec. Dokud CMS podpis a časové razítko
  -- doručenky neověříme sami, nesmí se tvářit jako ověřené.
  receipt_document_id     BIGINT UNSIGNED NULL,
  receipt_signature_status ENUM('unverified','trusted') NOT NULL DEFAULT 'unverified',

  row_version             INT UNSIGNED NOT NULL DEFAULT 1,
  created_by              BIGINT UNSIGNED NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_outbox_supplier_id (supplier_id, id),
  -- Idempotence: opakované potvrzení téhož artefaktu pro téhož příjemce
  -- nevytvoří druhý řádek fronty. Klíč už tenanta i prostředí obsahuje.
  UNIQUE KEY uq_submission_outbox_idempotency (idempotency_key_hash),
  UNIQUE KEY uq_submission_outbox_correlation (correlation_reference),
  KEY idx_submission_outbox_state (supplier_id, dispatch_state, acceptance_state),
  KEY idx_submission_outbox_artifact (supplier_id, artifact_kind, artifact_id),
  KEY idx_submission_outbox_external (channel, external_message_id),

  CONSTRAINT fk_submission_outbox_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_outbox_recipient
    FOREIGN KEY (recipient_id) REFERENCES submission_recipients (id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_outbox_confirmer
    FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_outbox_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_submission_outbox_artifact_sha
    CHECK (artifact_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_submission_outbox_correlation
    CHECK (correlation_reference REGEXP '^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$'),
  CONSTRAINT chk_submission_outbox_agenda
    CHECK (agenda_code REGEXP '^[A-Z][A-Z0-9_]{1,47}$'),

  -- ═══ Jádro celého návrhu ═══
  -- Vyřízení bez druhu důkazu je tvrzení bez podkladu. A protože ve slovníku
  -- `acceptance_evidence_kind` není doručenka, znamená tenhle CHECK doslova:
  -- „doručeno" se nikdy nestane „zpracováno".
  CONSTRAINT chk_submission_outbox_acceptance_evidence
    CHECK (
      (acceptance_state = 'unknown' AND acceptance_evidence_kind IS NULL)
      OR (acceptance_state <> 'unknown' AND acceptance_evidence_kind IS NOT NULL)
    ),
  -- Úřad nemůže rozhodnout o podání, které k němu neodešlo.
  CONSTRAINT chk_submission_outbox_acceptance_requires_send
    CHECK (
      acceptance_state = 'unknown'
      OR dispatch_state IN ('sent','delivered')
    ),
  CONSTRAINT chk_submission_outbox_accepted_at
    CHECK (
      (acceptance_state = 'accepted') = (accepted_at IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_rejected_at
    CHECK (
      (acceptance_state = 'rejected') = (rejected_at IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_delivered_at
    CHECK (
      (dispatch_state = 'delivered') = (delivered_at IS NOT NULL)
    ),
  -- Odeslání vždy potvrzuje člověk: bez `confirmed_by` se řádek nesmí hnout
  -- z fronty. Automatika smí připravit, ne odeslat.
  CONSTRAINT chk_submission_outbox_human_confirmation
    CHECK (
      dispatch_state IN ('ready','cancelled')
      OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_sent_at
    CHECK (
      (dispatch_state IN ('sent','delivered')) = (sent_at IS NOT NULL AND external_message_id IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_failed
    CHECK (
      dispatch_state <> 'failed'
      OR (failed_at IS NOT NULL AND last_error_code IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_timeline
    CHECK (
      delivered_at IS NULL OR sent_at IS NULL OR delivered_at >= sent_at
    ),
  -- ISDS bez příjemce je zpráva bez adresáta.
  CONSTRAINT chk_submission_outbox_isds_recipient
    CHECK (channel <> 'isds' OR recipient_box_id IS NOT NULL),
  CONSTRAINT chk_submission_outbox_correlation_length
    CHECK (CHAR_LENGTH(correlation_reference) <= 50),

  -- ─── Brány, které musí projít před odesláním datovkou ───
  -- Odeslané podání bez lokální XSD kontroly je vada, o které se dozvíme až
  -- z výzvy podle § 74 DŘ, tedy po dnech. ISDS obsah nevaliduje.
  -- Pozor na hranici: `sending` je okno, VE KTERÉM se brány teprve
  -- vyhodnocují, takže se do výčtu nesmí. Vyžadují se od `send_uncertain`
  -- výš — tedy od okamžiku, kdy zpráva mohla opustit aplikaci.
  CONSTRAINT chk_submission_outbox_validation_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR artifact_validation_status IN ('passed','skipped')
    ),
  CONSTRAINT chk_submission_outbox_validation_time
    CHECK ((artifact_validation_status IS NOT NULL) = (artifact_validated_at IS NOT NULL)),
  -- Číselník smí zestárnout, ISDS ne. Bez ověření schránky hrozí, že přiznání
  -- odejde do zrušené schránky a zjistíme to až po lhůtě.
  CONSTRAINT chk_submission_outbox_box_verification_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR recipient_box_verified_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Append-only ledger pokusů (vzorem 1372)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS submission_outbox_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  outbox_id             BIGINT UNSIGNED NOT NULL,
  channel               ENUM('epo','isds') NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  outcome               ENUM('in_flight','sent','uncertain','rejected','failed') NOT NULL DEFAULT 'in_flight',
  request_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  correlation_reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_message_id   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  error_code            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  error_message         VARCHAR(500) NULL,
  started_at            DATETIME NOT NULL,
  finished_at           DATETIME NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_outbox_attempts_order (supplier_id, outbox_id, attempt_no),
  KEY idx_submission_outbox_attempts_outbox (outbox_id, id),

  CONSTRAINT fk_submission_outbox_attempts_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_outbox_attempts_outbox
    FOREIGN KEY (supplier_id, outbox_id) REFERENCES submission_outbox (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_outbox_attempts_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_submission_outbox_attempts_order
    CHECK (attempt_no > 0 AND row_version > 0),
  CONSTRAINT chk_submission_outbox_attempts_request
    CHECK (request_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_submission_outbox_attempts_failure
    CHECK (
      outcome NOT IN ('failed','rejected')
      OR (error_code IS NOT NULL AND error_message IS NOT NULL)
    ),
  CONSTRAINT chk_submission_outbox_attempts_sent
    CHECK (outcome <> 'sent' OR external_message_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Příchozí zprávy
-- ─────────────────────────────────────────────────────────────────────────────
-- `classification = 'unclassified'` je plnohodnotný cíl, ne selhání. Zpráva,
-- kterou neumíme zařadit, se nikdy nehádá na podání — leží v „nezařazeno",
-- kde ji uživatel vidí a přiřadí ručně.
CREATE TABLE IF NOT EXISTS submission_inbox_messages (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  channel               ENUM('isds') NOT NULL,
  external_message_id   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sender_box_id         CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
  sender_name           VARCHAR(255) NULL,
  subject               VARCHAR(255) NULL,
  -- Náš klíč podání jde ven v `dmSenderIdent`. U ODPOVĚDI úřadu ale není
  -- zaručeno, že ho protistrana zopakuje — proto je párování přes tenhle
  -- sloupec jen šťastná shoda, ne pravidlo. Když chybí, zpráva skončí
  -- v „nezařazeno" a čeká na člověka. To je správný výsledek, ne nedodělek.
  sender_ident          VARCHAR(64) NULL COMMENT 'dmSenderIdent / dmRecipientIdent, pokud ho protistrana zachovala',
  signature_status      ENUM('unverified','trusted') NOT NULL DEFAULT 'unverified'
                          COMMENT 'ZfoExtractor podpis neověřuje — dokud ho neověříme sami, zůstává unverified',
  classification        ENUM(
    'delivery_receipt','cssz_protocol','health_insurer_response','tax_office_response','unclassified'
  ) NOT NULL DEFAULT 'unclassified',
  matched_outbox_id     BIGINT UNSIGNED NULL,
  document_id           BIGINT UNSIGNED NULL COMMENT 'Uložená zpráva v DMS (documents.id)',
  delivered_at          DATETIME NULL,
  accepted_at           DATETIME NULL COMMENT 'dmAcceptanceTime = fikce doručení, NE přijetí úřadem',
  raw_sha256            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  fetched_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at          DATETIME NULL,

  UNIQUE KEY uq_submission_inbox_message (supplier_id, channel, environment, external_message_id),
  KEY idx_submission_inbox_classification (supplier_id, classification, fetched_at),
  KEY idx_submission_inbox_match (matched_outbox_id),

  CONSTRAINT fk_submission_inbox_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  -- Jednosloupcový FK schválně: `ON DELETE SET NULL` neumí kompozitní klíč,
  -- v němž je NOT NULL sloupec. Tenantovou shodu proto hlídá trigger níž.
  CONSTRAINT fk_submission_inbox_outbox
    FOREIGN KEY (matched_outbox_id) REFERENCES submission_outbox (id) ON DELETE SET NULL,
  CONSTRAINT fk_submission_inbox_document
    FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL
  -- Invariant „nezařazená zpráva nesmí být navázaná na podání" drží trigger,
  -- ne CHECK: MariaDB zakazuje CHECK nad sloupcem, který mění FK akce
  -- `ON DELETE SET NULL` (chyba 1901).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Stav dotazování schránky
-- ─────────────────────────────────────────────────────────────────────────────
-- Bez tohohle řádku by prázdný inbox a nefunkční dotaz vypadaly stejně: v obou
-- případech „žádné nové zprávy". `last_ok_at` odděluje „schránka je prázdná"
-- od „na schránku se nedovoláme".
CREATE TABLE IF NOT EXISTS submission_inbox_polls (
  supplier_id       INT UNSIGNED NOT NULL,
  channel           ENUM('isds') NOT NULL,
  environment       ENUM('production','test') NOT NULL,
  last_attempt_at   DATETIME NULL,
  last_ok_at        DATETIME NULL,
  last_ok_count     INT UNSIGNED NULL,
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  last_error_message VARCHAR(500) NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, channel, environment),
  CONSTRAINT fk_submission_inbox_polls_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Triggery: identita pokusu je neměnná, ledger se nemaže
-- ─────────────────────────────────────────────────────────────────────────────
DELIMITER //

DROP TRIGGER IF EXISTS trg_submission_outbox_attempts_update_guard//
CREATE TRIGGER trg_submission_outbox_attempts_update_guard
BEFORE UPDATE ON submission_outbox_attempts
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.outbox_id <=> OLD.outbox_id)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.attempt_no <=> OLD.attempt_no)
     OR NOT (NEW.request_sha256 <=> OLD.request_sha256)
     OR NOT (NEW.correlation_reference <=> OLD.correlation_reference)
     OR NOT (NEW.started_at <=> OLD.started_at)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission attempt identity is immutable';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission attempt row_version must advance by one';
  END IF;

  -- Pokus, který jednou dostal ID zprávy, ho nesmí ztratit ani přepsat:
  -- je to jediný důkaz, že zpráva u příjemce je.
  IF OLD.external_message_id IS NOT NULL
     AND NOT (NEW.external_message_id <=> OLD.external_message_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission attempt external message id is single-assignment';
  END IF;

  IF OLD.outcome IN ('sent','failed','rejected') AND NEW.outcome = 'in_flight' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'terminal submission attempt cannot return in flight';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_submission_outbox_attempts_no_delete//
CREATE TRIGGER trg_submission_outbox_attempts_no_delete
BEFORE DELETE ON submission_outbox_attempts
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'submission_outbox_attempts are append-only';
END//

-- Tenantová shoda příchozí zprávy s podáním — FK ji nehlídá (viz komentář
-- u fk_submission_inbox_outbox), takže by šlo navázat zprávu jedné firmy na
-- podání druhé a rozhodnutí úřadu by se propsalo cizímu tenantovi.
DROP TRIGGER IF EXISTS trg_submission_inbox_tenant_guard//
CREATE TRIGGER trg_submission_inbox_tenant_guard
BEFORE INSERT ON submission_inbox_messages
FOR EACH ROW
BEGIN
  IF NEW.matched_outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.matched_outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'inbox message must match its submission tenant and environment';
  END IF;

  -- Nezařazená zpráva nesmí být navázaná na podání — to je právě to hádání,
  -- kterému se vyhýbáme. Kdo chce vazbu, musí zprávu nejdřív zařadit.
  IF NEW.classification = 'unclassified' AND NEW.matched_outbox_id IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'unclassified inbox message must not be linked to a submission';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_submission_inbox_tenant_update_guard//
CREATE TRIGGER trg_submission_inbox_tenant_update_guard
BEFORE UPDATE ON submission_inbox_messages
FOR EACH ROW
BEGIN
  IF NEW.matched_outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.matched_outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'inbox message must match its submission tenant and environment';
  END IF;

  -- Nezařazená zpráva nesmí být navázaná na podání — to je právě to hádání,
  -- kterému se vyhýbáme. Kdo chce vazbu, musí zprávu nejdřív zařadit.
  IF NEW.classification = 'unclassified' AND NEW.matched_outbox_id IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'unclassified inbox message must not be linked to a submission';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_submission_outbox_update_guard//
CREATE TRIGGER trg_submission_outbox_update_guard
BEFORE UPDATE ON submission_outbox
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.agenda_code <=> OLD.agenda_code)
     OR NOT (NEW.artifact_kind <=> OLD.artifact_kind)
     OR NOT (NEW.artifact_id <=> OLD.artifact_id)
     OR NOT (NEW.artifact_sha256 <=> OLD.artifact_sha256)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.correlation_reference <=> OLD.correlation_reference)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission outbox identity is immutable';
  END IF;

  -- Jednou odeslané podání zůstává odeslané: kdyby šlo vrátit na 'ready',
  -- druhé potvrzení by z něj udělalo duplicitní podání u úřadu.
  IF OLD.dispatch_state NOT IN ('ready','cancelled') AND NEW.dispatch_state = 'ready' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispatched submission cannot return to ready';
  END IF;

  IF OLD.sent_at IS NOT NULL AND NOT (NEW.sent_at <=> OLD.sent_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission sent_at is single-assignment';
  END IF;

  IF OLD.external_message_id IS NOT NULL
     AND NOT (NEW.external_message_id <=> OLD.external_message_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission external message id is single-assignment';
  END IF;

  -- Doručení je fakt o dopravě; jakmile ho známe, nemá důvod mizet.
  IF OLD.delivered_at IS NOT NULL AND NOT (NEW.delivered_at <=> OLD.delivered_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission delivered_at is single-assignment';
  END IF;

  -- ═══ Doručenka nesmí posunout osu vyřízení ═══
  -- Kdyby jeden UPDATE zapsal doručení A ZÁROVEŇ vyřízení, byla by to přesně ta
  -- záměna, které se bráníme: „doručenka dorazila → tedy je to přijaté". Změna
  -- osy vyřízení musí přijít vlastním zápisem s vlastním důkazem.
  IF OLD.delivered_at IS NULL AND NEW.delivered_at IS NOT NULL
     AND NOT (NEW.acceptance_state <=> OLD.acceptance_state)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'delivery must not change acceptance state in the same write';
  END IF;

  IF OLD.acceptance_state <> 'unknown' AND NEW.acceptance_state <> OLD.acceptance_state THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission acceptance is single-assignment';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission outbox row_version must advance by one';
  END IF;
END//

DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed registru příjemců — POUZE doložená ID
-- ─────────────────────────────────────────────────────────────────────────────
-- Zdroj: private/Mzdy/21-ZP-PODANI-RESERSE.md (rešerše oficiálních webů
-- pojišťoven). Seedují se jen ty čtyři pojišťovny, u kterých rešerše ID datové
-- schránky doslova uvádí. VoZP (201), ZPŠ (209) a RBP (213) datovou schránku
-- mají, ale ID rešerše nedokládá → NESEEDUJÍ SE.
--
-- FINANČNÍ ÚŘADY se neseedují taky, a to i když zdroj známe:
--   financnisprava.gov.cz/assets/cs/prilohy/de-datove-schranky/Identifikacni_kody_FR_a_FU.pdf
--   (141 schránek, verze k 1. 7. 2023)
-- Důvody jsou dva. Za prvé samotná ID v tomhle repozitáři nejsou — opsat je
-- z PDF od oka by bylo přesně to hádání, kterému se bráníme. Za druhé je seznam
-- z roku 2023 a nesmí být zdrojem pravdy; ID se doplňují ručně a před každým
-- odesláním se schránka ověřuje dotazem do ISDS. Pozor i na to, že podání se
-- činí u MÍSTNĚ PŘÍSLUŠNÉHO správce daně podle § 73 odst. 1 DŘ, tedy do
-- schránky KRAJSKÉHO FÚ, ne územního pracoviště — a všechny mají shodné IČ
-- 72080043, takže se rozliší jedině podle ID schránky.
--
-- ČSSZ se neseeduje: doklad nemáme a mzdová podání jdou dnes jiným kanálem (VREP).
--
-- Prázdno je tu záměr, ne nedodělek. Odhadnuté ID schránky pošle přiznání
-- neznámo kam a lhůta uteče.
INSERT INTO submission_recipients (supplier_id, code, name, kind, isds_box_id, source_url, source_note)
SELECT * FROM (
  SELECT NULL AS supplier_id, 'zp_vzp_111' AS code, 'VZP ČR (111)' AS name, 'health_insurer' AS kind,
         'i48ae3q' AS isds_box_id,
         'https://www.vzp.cz/platci/informace/povinnosti-platcu-metodika/3-5-predavani-dat-od-zamestnavatele-elektronickou-cestou' AS source_url,
         'Rešerše private/Mzdy/21-ZP-PODANI-RESERSE.md, tabulka §3' AS source_note
  UNION ALL SELECT NULL, 'zp_cpzp_205', 'ČPZP (205)', 'health_insurer', 'mk5ab8i',
         'https://www.cpzp.cz/zmeny2026', 'Rešerše private/Mzdy/21-ZP-PODANI-RESERSE.md, tabulka §3'
  UNION ALL SELECT NULL, 'zp_ozp_207', 'OZP (207)', 'health_insurer', 'q9iadw9',
         'https://www.ozp.cz/pro-platce/zamestnavatel/informace-pro-zamestnavatele', 'Rešerše private/Mzdy/21-ZP-PODANI-RESERSE.md, tabulka §3'
  UNION ALL SELECT NULL, 'zp_zpmvcr_211', 'ZP MV ČR (211)', 'health_insurer', '9swaix3',
         'https://www.zpmvcr.cz/o-nas/aktuality/informace-pro-zamestnavatele-a-osvc-prechod-na-nove-elektronicke-formaty-podani',
         'Rešerše private/Mzdy/21-ZP-PODANI-RESERSE.md, tabulka §3; XML datovou schránkou až od 1. 7. 2026'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM submission_recipients r WHERE r.supplier_id IS NULL AND r.code = seed.code
);
