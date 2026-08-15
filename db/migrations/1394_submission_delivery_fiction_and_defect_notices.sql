-- Právní stránka toho, co se stane po odeslání: kdy bylo doručeno a co dělá
-- výzva k odstranění vad.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tahle migrace je
-- ─────────────────────────────────────────────────────────────────────────────
-- Platforma podání dnes umí odeslat a přijmout protokol. O doručení ale ví jen
-- dvě syrová razítka z ISDS (`submission_inbox_messages.delivered_at` =
-- dmDeliveryTime, `accepted_at` = dmAcceptanceTime) a nikde z nich neodvozuje
-- ROZHODNÝ DEN DORUČENÍ. Přitom právě od něj běží všechny navazující lhůty.
--
-- Dvě věci, které kvůli tomu chybí:
--
--   1. **Fikce doručení.** § 17 odst. 4 zák. 300/2008 Sb.: „Nepřihlásí-li se do
--      datové schránky osoba podle odstavce 3 ve lhůtě 10 dnů ode dne, kdy byl
--      dokument dodán do datové schránky, považuje se tento dokument za doručený
--      posledním dnem této lhůty; to neplatí, vylučuje-li jiný právní předpis
--      náhradní doručení." Odstavec 3 je doručení přihlášením. Dokud se ten den
--      neuloží, nejde ho dohledat ani doložit — a dopočítávat ho za běhu znamená,
--      že se odpověď mění podle toho, kdy se člověk zeptá.
--
--   2. **Výzva k odstranění vad podle § 74 daňového řádu.** Aplikace ji nezná
--      vůbec. Vadné podání proto tiše zestárne: je-li vada podle § 74 odst. 1
--      písm. a) nebo b) odstraněna ve stanovené lhůtě, hledí se na podání, jako
--      by bylo učiněno řádně a včas (odst. 3); jinak se podání uplynutím lhůty
--      **stává neúčinným** (odst. 4). Bez evidence to nikdo nezjistí.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Fail-closed, ne „asi to bude v pořádku"
-- ─────────────────────────────────────────────────────────────────────────────
-- Každý sloupec, který tady přibývá, umí říct „nevíme", a „nevíme" NENÍ totéž
-- co „v pořádku":
--   - `delivery_basis = 'unknown'` znamená, že rozhodný den neumíme určit,
--     a `delivered_on` u něj MUSÍ být NULL (CHECK níž). Nejde tedy uložit
--     doručení bez toho, aby bylo jasné, čím je podložené.
--   - `delivery_basis = 'pending'` je stav „dodáno, lhůta fikce ještě běží".
--     Taky bez data — protože doručeno ještě NENÍ.
--   - `defect_ground = 'unknown'` vynucuje `consequence = 'unknown'`. Dokud
--     nevíme, které písmeno § 74 odst. 1 výzva uvádí, nesmíme tvrdit, že podání
--     neúčinností neohrožuje (písm. c/d) ani že ohrožuje (písm. a/b).
--   - `respond_by_on` se NEDOPOČÍTÁVÁ. Lhůtu podle § 74 stanoví správce daně ve
--     výzvě (§ 32 odst. 1 DŘ); zákon žádnou délku nepředepisuje, jen zakazuje
--     kratší než 8 dnů mimo zcela výjimečné případy (§ 32 odst. 2 DŘ). Vymyslet
--     si ji by znamenalo vyrobit termín, který nikde nestojí.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Co se tu ZÁMĚRNĚ nemění
-- ─────────────────────────────────────────────────────────────────────────────
-- Osa vyřízení (`submission_outbox.acceptance_state`) zůstává nedotčená. Ani
-- fikce doručení, ani výzva o přijetí podání nic neříkají — fikce je o DORUČENÍ
-- a výzva je naopak důkaz, že podání přijaté zatím NENÍ. Evidence výzvy proto
-- žije ve vlastní tabulce a na `submission_outbox` sahá jen cizím klíčem.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Rozhodný den doručení u příchozí zprávy
-- ─────────────────────────────────────────────────────────────────────────────
-- Rozšiřujeme existující `submission_inbox_messages`, ne novou tabulku: doručení
-- je vlastnost té zprávy, ne samostatná entita. Druhý mechanismus by znamenal
-- druhou pravdu.
ALTER TABLE submission_inbox_messages
  ADD COLUMN IF NOT EXISTS delivery_basis
    ENUM('login','fiction','login_or_fiction','pending','unknown') NOT NULL DEFAULT 'unknown'
    COMMENT 'Čím je doručení podložené. unknown = nevíme, pending = lhůta fikce běží. Ani jedno neznamená „doručeno".'
    AFTER accepted_at,
  ADD COLUMN IF NOT EXISTS delivered_on DATE NULL
    COMMENT 'ROZHODNÝ DEN DORUČENÍ (§ 17 odst. 3 nebo 4 zák. 300/2008 Sb.). Od něj běží navazující lhůty.'
    AFTER delivery_basis,
  ADD COLUMN IF NOT EXISTS fiction_statutory_on DATE NULL
    COMMENT 'Poslední den desetidenní lhůty podle § 17 odst. 4 — bez posunu na pracovní den.'
    AFTER delivered_on,
  ADD COLUMN IF NOT EXISTS fiction_due_on DATE NULL
    COMMENT 'Týž den posunutý podle § 33 odst. 4 DŘ na nejblíže následující pracovní den.'
    AFTER fiction_statutory_on,
  ADD COLUMN IF NOT EXISTS fiction_days SMALLINT UNSIGNED NULL
    COMMENT 'Délka lhůty použitá při výpočtu. Ukládá se, aby šlo starý výpočet přečíst i po změně pravidla.'
    AFTER fiction_due_on,
  ADD COLUMN IF NOT EXISTS fiction_days_source ENUM('ruleset','statute') NULL
    COMMENT 'Odkud délka lhůty přišla — z rulesetu, nebo ze zákonné hodnoty v kódu.'
    AFTER fiction_days,
  ADD COLUMN IF NOT EXISTS sender_is_public_authority TINYINT(1) NULL
    COMMENT 'NULL = nevíme. Fikci podle § 17 spouští jen doručování orgánu veřejné moci; poštovní datová zpráva podle § 18a ji nemá.'
    AFTER fiction_days_source,
  ADD COLUMN IF NOT EXISTS delivery_resolved_at DATETIME NULL
    COMMENT 'Kdy aplikace rozhodný den určila. Bez něj by nešlo poznat starý závěr od nikdy nespočítaného.'
    AFTER sender_is_public_authority,
  ADD COLUMN IF NOT EXISTS delivery_note VARCHAR(300) NULL
    COMMENT 'Věta, proč to takhle vyšlo — pro člověka, ne pro stroj.'
    AFTER delivery_resolved_at;

-- Rozhodný den smí existovat JEN tam, kde je čím podložený. A naopak: základ,
-- který doručení tvrdí, bez data nesmí zůstat. Tím je vyloučené jak tiché
-- „doručeno neznámo kdy", tak „máme datum, ale nevíme odkud".
ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT chk_submission_inbox_delivery_basis
    CHECK (
      (delivery_basis IN ('login','fiction','login_or_fiction') AND delivered_on IS NOT NULL)
      OR (delivery_basis IN ('pending','unknown') AND delivered_on IS NULL)
    ),
  ADD CONSTRAINT chk_submission_inbox_fiction_days
    CHECK ((fiction_days IS NULL) = (fiction_days_source IS NULL)),
  ADD CONSTRAINT chk_submission_inbox_fiction_shift
    CHECK (
      fiction_due_on IS NULL
      OR (fiction_statutory_on IS NOT NULL AND fiction_due_on >= fiction_statutory_on)
    ),
  ADD CONSTRAINT chk_submission_inbox_delivery_resolved
    CHECK (delivery_basis = 'unknown' OR delivery_resolved_at IS NOT NULL);

-- Fronta „komu dnes uplynula fikce" — jediný dotaz, který sweep potřebuje.
ALTER TABLE submission_inbox_messages
  ADD INDEX idx_submission_inbox_delivery (supplier_id, environment, delivery_basis, fiction_due_on);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Výzva k odstranění vad podání (§ 74 daňového řádu)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS submission_defect_notices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id         INT UNSIGNED NOT NULL,
  environment         ENUM('production','test') NOT NULL,

  -- Ke kterému podání výzva patří. NULL je legitimní stav: úřad naši spisovou
  -- značku opakovat nemusí, takže vazbu často určí až člověk. Nespárovaná výzva
  -- je vidět, ne ztracená.
  outbox_id           BIGINT UNSIGNED NULL,
  -- Zpráva, kterou výzva přišla. NULL, když ji uživatel eviduje ručně (přišla
  -- poštou, nebo do schránky, kterou aplikace nevybírá).
  inbox_message_id    BIGINT UNSIGNED NULL,

  notice_reference    VARCHAR(128) NULL COMMENT 'Číslo jednací výzvy.',
  authority_kind      ENUM('tax_office','cssz','health_insurer','other') NOT NULL DEFAULT 'other',

  -- Které písmeno § 74 odst. 1 výzva uvádí. Není to kosmetika: podle odst. 4 se
  -- podání stává neúčinným JEN u vad podle písm. a) nebo b). U písm. c) a d)
  -- (nesprávný způsob / formát) neúčinnost nenastává — hrozí pokuta podle
  -- § 247a DŘ. Splácat to dohromady by znamenalo strašit nebo uklidňovat
  -- uživatele bez opory.
  defect_ground       ENUM('a_not_processable','b_no_effects','c_wrong_way','d_wrong_format','unknown')
                      NOT NULL DEFAULT 'unknown',
  consequence         ENUM('ineffective','no_ineffectiveness','unknown') NOT NULL DEFAULT 'unknown'
                      COMMENT 'Odvozeno z defect_ground, uloženo kvůli dohledatelnosti. Vazbu drží CHECK.',

  -- Rozhodný den doručení výzvy — přebírá se z `submission_inbox_messages`
  -- (bod 1), nebo ho zadá člověk u ručně evidované výzvy.
  delivered_on        DATE NULL,
  -- Konec náhradní lhůty. Nedopočítává se ze zákona — zákon délku nestanoví.
  respond_by_on       DATE NULL,
  respond_by_source   ENUM('stated_in_notice','derived_from_days','unknown') NOT NULL DEFAULT 'unknown'
                      COMMENT 'stated_in_notice = datum stálo přímo ve výzvě; derived_from_days = spočítáno z počtu dnů uvedeného ve výzvě.',
  stated_period_days  SMALLINT UNSIGNED NULL COMMENT 'Počet dnů, který výzva uvádí. Jen když ho uvádí.',
  respond_by_shifted  TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Konec lhůty padl na sobotu, neděli nebo svátek a posunul se (§ 33 odst. 4 DŘ).',

  status              ENUM('unknown','open','answered_in_time','answered_late','missed','withdrawn')
                      NOT NULL DEFAULT 'unknown'
                      COMMENT 'unknown = neznáme lhůtu, takže o stavu nelze nic tvrdit. Není to „v pořádku".',
  responded_on        DATE NULL,
  response_outbox_id  BIGINT UNSIGNED NULL COMMENT 'Podání, kterým jsme vadu odstranili.',
  outcome             ENUM('unknown','cured','ineffective','penalty_risk') NOT NULL DEFAULT 'unknown',

  note                VARCHAR(1000) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_submission_defect_notices_supplier_id (supplier_id, id),
  -- Tatáž zpráva nesmí založit druhou výzvu. Víc NULL unikátní klíč pouští,
  -- takže ruční evidence tím omezená není.
  UNIQUE KEY uq_submission_defect_notices_message (inbox_message_id),
  KEY idx_submission_defect_notices_open (supplier_id, environment, status, respond_by_on),
  KEY idx_submission_defect_notices_outbox (supplier_id, outbox_id),
  KEY fk_submission_defect_notices_response (supplier_id, response_outbox_id),
  KEY fk_submission_defect_notices_creator (created_by),

  CONSTRAINT fk_submission_defect_notices_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_defect_notices_outbox
    FOREIGN KEY (supplier_id, outbox_id) REFERENCES submission_outbox (supplier_id, id),
  CONSTRAINT fk_submission_defect_notices_response
    FOREIGN KEY (supplier_id, response_outbox_id) REFERENCES submission_outbox (supplier_id, id),
  CONSTRAINT fk_submission_defect_notices_message
    FOREIGN KEY (inbox_message_id) REFERENCES submission_inbox_messages (id) ON DELETE SET NULL,
  CONSTRAINT fk_submission_defect_notices_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,

  -- Následek se nesmí rozejít s důvodem. „Nevíme, jaká vada" a zároveň
  -- „neúčinnost nehrozí" je přesně to tvrzení, kvůli kterému se tahle evidence
  -- staví.
  CONSTRAINT chk_submission_defect_notices_consequence
    CHECK (
      (defect_ground = 'unknown' AND consequence = 'unknown')
      OR (defect_ground IN ('a_not_processable','b_no_effects') AND consequence = 'ineffective')
      OR (defect_ground IN ('c_wrong_way','d_wrong_format') AND consequence = 'no_ineffectiveness')
    ),
  -- Termín bez doloženého původu neexistuje a původ bez termínu taky ne.
  CONSTRAINT chk_submission_defect_notices_respond_by
    CHECK ((respond_by_on IS NULL) = (respond_by_source = 'unknown')),
  CONSTRAINT chk_submission_defect_notices_period_days
    CHECK (
      (respond_by_source = 'derived_from_days' AND stated_period_days IS NOT NULL)
      OR (respond_by_source <> 'derived_from_days')
    ),
  -- O tom, jestli se to stihlo, nejde rozhodovat bez termínu.
  CONSTRAINT chk_submission_defect_notices_status_needs_deadline
    CHECK (
      status IN ('unknown','withdrawn')
      OR respond_by_on IS NOT NULL
    ),
  CONSTRAINT chk_submission_defect_notices_responded
    CHECK ((status IN ('answered_in_time','answered_late')) = (responded_on IS NOT NULL)),
  CONSTRAINT chk_submission_defect_notices_response_order
    CHECK (responded_on IS NULL OR delivered_on IS NULL OR responded_on >= delivered_on),
  CONSTRAINT chk_submission_defect_notices_deadline_order
    CHECK (respond_by_on IS NULL OR delivered_on IS NULL OR respond_by_on >= delivered_on),
  -- § 32 odst. 2 DŘ: lhůtu kratší než 8 dnů lze stanovit jen zcela výjimečně.
  -- Nejde o zákaz — proto jen horní a dolní mez zjevného nesmyslu, ne osmička.
  CONSTRAINT chk_submission_defect_notices_period_range
    CHECK (stated_period_days IS NULL OR (stated_period_days >= 1 AND stated_period_days <= 366)),
  CONSTRAINT chk_submission_defect_notices_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Tenantová a prostředí-ová shoda
-- ─────────────────────────────────────────────────────────────────────────────
-- Cizí klíče drží firmu, ale ne PROSTŘEDÍ: testovací výzva navázaná na
-- produkční podání by tvrdila něco o ostrém stavu. Stejný důvod, proč tenhle
-- guard má i `submission_inbox_messages` (migrace 1381).
DELIMITER //

DROP TRIGGER IF EXISTS trg_submission_defect_notice_guard//
CREATE TRIGGER trg_submission_defect_notice_guard
BEFORE INSERT ON submission_defect_notices
FOR EACH ROW
BEGIN
  IF NEW.outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice must match its submission tenant and environment';
  END IF;

  IF NEW.response_outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.response_outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice response must match its tenant and environment';
  END IF;

  IF NEW.inbox_message_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_inbox_messages m
     WHERE m.id = NEW.inbox_message_id
       AND m.supplier_id = NEW.supplier_id
       AND m.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice must match its inbox message tenant and environment';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_submission_defect_notice_update_guard//
CREATE TRIGGER trg_submission_defect_notice_update_guard
BEFORE UPDATE ON submission_defect_notices
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id) OR NOT (NEW.environment <=> OLD.environment) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice tenant and environment are immutable';
  END IF;

  IF NEW.outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice must match its submission tenant and environment';
  END IF;

  IF NEW.response_outbox_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM submission_outbox o
     WHERE o.id = NEW.response_outbox_id
       AND o.supplier_id = NEW.supplier_id
       AND o.environment = NEW.environment
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice response must match its tenant and environment';
  END IF;

  -- Odpověď na výzvu je jednorázová událost. Přepsat ji později by smazalo
  -- doklad o tom, že se to stihlo — a přesně o ten tady jde.
  IF OLD.responded_on IS NOT NULL AND NOT (NEW.responded_on <=> OLD.responded_on) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice response date is single-assignment';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'defect notice row_version must advance by one';
  END IF;
END//

DELIMITER ;
