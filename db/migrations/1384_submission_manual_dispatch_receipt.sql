-- Ruční odeslání datovkou + spárování nahrané doručenky s podáním.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tahle migrace vůbec je
-- ─────────────────────────────────────────────────────────────────────────────
-- Strojový transport do ISDS nasazený není (`UnavailableIsdsTransport`) a podle
-- rozboru rozsahu ani nemusí být: zprávu odesílá člověk ze své vlastní datové
-- schránky. Aplikace mu k tomu připraví přílohu, příjemce a spisovou značku,
-- ale kruh se dnes neuzavře — nahraná doručenka se s podáním nespáruje a
-- podání navždy zůstane v „připraveno".
--
-- Chybí k tomu tři věci, a všechny tři jsou tady:
--   1. rozlišit podání, které odešlo NAŠÍM kanálem, od toho, které člověk
--      odeslal ručně (`dispatch_mode`) — jinak nejde poznat, které brány mají
--      smysl a které se nemají čím naplnit,
--   2. zaznamenat, ČÍM se doručenka s podáním spárovala (`receipt_matched_by`),
--      aby šlo poznat automatickou shodu od lidského rozhodnutí,
--   3. udělat připojení doručenky jednorázové (trigger níž), takže tatáž
--      doručenka nahraná podruhé nemůže vyrobit druhý důkaz.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Co se tu ZÁMĚRNĚ nemění
-- ─────────────────────────────────────────────────────────────────────────────
-- `acceptance_evidence_kind` nedostává hodnotu pro doručenku. Ani teď, ani
-- později. Doručenka je důkaz o DORUČENÍ do schránky úřadu, ne o tom, že ji
-- úřad zpracoval a podání přijal. Slovo pro takový zápis ve schématu není a to
-- je jediná spolehlivá obrana proti záměně, protože ji nejde obejít ani ručním
-- UPDATE.
--
-- Stejně tak zůstává `receipt_signature_status` ve výchozím stavu 'unverified':
-- CMS podpis ani časové razítko doručenky neověřujeme (`ZfoExtractor` to nedělá).
-- Dokud to neumíme, nesmí se doručenka tvářit jako ověřený důkaz.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Nové sloupce
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE submission_outbox
  ADD COLUMN dispatch_mode ENUM('channel','manual') NOT NULL DEFAULT 'channel'
    COMMENT 'channel = odeslala aplikace přes kanál; manual = odeslal člověk ze své datové schránky'
    AFTER channel,
  -- Jak vznikla vazba doručenky na tohle podání. `correlation_reference` je
  -- naše vlastní spisová značka z `dmSenderIdent`, `external_message_id` je
  -- dmID zapsané při ručním odeslání — obojí je přesný identifikátor.
  -- `manual` znamená, že shodu potvrdil člověk; nic slabšího automat nepoužije.
  ADD COLUMN receipt_matched_by ENUM('correlation_reference','external_message_id','manual') NULL
    AFTER receipt_signature_status,
  ADD COLUMN receipt_inbox_message_id BIGINT UNSIGNED NULL
    COMMENT 'Řádek v submission_inbox_messages, ze kterého doručenka pochází'
    AFTER receipt_matched_by,
  ADD COLUMN receipt_attached_at DATETIME NULL AFTER receipt_inbox_message_id;

-- Vazba na příchozí zprávu. Jednosloupcový FK se `ON DELETE SET NULL` ze
-- stejného důvodu jako u `fk_submission_inbox_outbox`: kompozitní klíč
-- s NOT NULL sloupcem to neumí. Tenantovou shodu hlídá aplikace i trigger
-- na straně inboxu.
ALTER TABLE submission_outbox
  ADD CONSTRAINT fk_submission_outbox_receipt_message
    FOREIGN KEY (receipt_inbox_message_id) REFERENCES submission_inbox_messages (id) ON DELETE SET NULL;

-- Doručenka bez uloženého souboru a bez času připojení je tvrzení bez podkladu —
-- přesně to, čemu se v tomhle modulu bráníme u vyřízení. Platí i pro doručení.
--
-- Pozor: CHECK nesmí sáhnout na `receipt_inbox_message_id`, protože ten mění
-- FK akce `ON DELETE SET NULL` (MariaDB chyba 1901).
ALTER TABLE submission_outbox
  ADD CONSTRAINT chk_submission_outbox_receipt_evidence
    CHECK (
      receipt_matched_by IS NULL
      OR (receipt_document_id IS NOT NULL AND receipt_attached_at IS NOT NULL)
    );

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Brána ověření schránky platí jen pro strojový kanál
-- ─────────────────────────────────────────────────────────────────────────────
-- Původní CHECK vyžadoval `recipient_box_verified_at` u každého odeslaného ISDS
-- podání. Smysl má jen tam, kde odesílá aplikace: zabraňuje tomu, aby přiznání
-- odešlo do zrušené schránky a zjistilo se to až po lhůtě.
--
-- U ručního odeslání se ta brána nemá čím naplnit — dotaz do ISDS neděláme,
-- protože transport není nasazený. A hlavně: potřeba není. Adresáta vybírá
-- člověk ve své datové schránce a ta mu neexistující nebo znepřístupněnou
-- schránku odmítne na místě, ne za tři dny.
--
ALTER TABLE submission_outbox
  DROP CONSTRAINT chk_submission_outbox_box_verification_gate;

ALTER TABLE submission_outbox
  ADD CONSTRAINT chk_submission_outbox_box_verification_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_mode = 'manual'
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR recipient_box_verified_at IS NOT NULL
    );

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. XSD brána u ručního odeslání: kontrola ANO, veto NE
-- ─────────────────────────────────────────────────────────────────────────────
-- Lokální kontrola proti XSD je jediná náhrada za kontrolu, kterou nám ISDS
-- neudělá, a u ručního odeslání je potřebná stejně. Jenže její VETO tady nedává
-- smysl: když člověk zprávu už odeslal a přinesl doručenku, podání odešlo —
-- ať se nám výsledek kontroly líbí, nebo ne. Odmítnout takový zápis by
-- neznamenalo, že se to nestalo; znamenalo by to, že o tom aplikace mlčí.
--
-- Proto se u `dispatch_mode = 'manual'` vyžaduje, aby kontrola PROBĚHLA
-- (`artifact_validation_status IS NOT NULL`), ale její výsledek smí být
-- i `failed`. Uživatel se tak dozví, že odeslal vadné podání, a může ho opravit
-- dřív, než přijde výzva podle § 74 DŘ. U strojového odeslání zůstává veto
-- beze změny — tam se vadné podání dá zastavit dřív, než opustí aplikaci.
ALTER TABLE submission_outbox
  DROP CONSTRAINT chk_submission_outbox_validation_gate;

ALTER TABLE submission_outbox
  ADD CONSTRAINT chk_submission_outbox_validation_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR (dispatch_mode = 'manual' AND artifact_validation_status IS NOT NULL)
      OR artifact_validation_status IN ('passed','skipped')
    );

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Trigger — přepis celého těla (MariaDB neumí ALTER TRIGGER)
-- ─────────────────────────────────────────────────────────────────────────────
-- Proti verzi z 1381 přibyly tři pojistky, všechny kvůli doručence:
--   a) `dispatch_mode` je součást identity podání a nesmí se měnit,
--   b) `receipt_document_id` je jednorázové přiřazení — tatáž doručenka
--      nahraná dvakrát nesmí vyrobit druhý důkaz a JINÁ doručenka nesmí tu
--      první přepsat,
--   c) `receipt_matched_by` se nesmí přepsat na jiný způsob spárování.
DELIMITER //

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

  -- Jak podání odešlo, se zpětně nepřepisuje: na tom visí, které brány se
  -- u něj vyžadovaly.
  IF NOT (NEW.dispatch_mode <=> OLD.dispatch_mode)
     AND OLD.dispatch_state NOT IN ('ready')
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispatch mode cannot change after the submission left the queue';
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

  -- ═══ Doručenka je jednorázový důkaz ═══
  -- Idempotence nahrání nestojí jen na aplikaci: druhý pokus připojit k témuž
  -- podání JINOU doručenku databáze odmítne. Opakované nahrání TÉŽE doručenky
  -- projde (hodnota se nemění), ale nic nezmění.
  IF OLD.receipt_document_id IS NOT NULL
     AND NOT (NEW.receipt_document_id <=> OLD.receipt_document_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission delivery receipt is single-assignment';
  END IF;

  IF OLD.receipt_matched_by IS NOT NULL
     AND NOT (NEW.receipt_matched_by <=> OLD.receipt_matched_by)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission receipt match reason is single-assignment';
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

  -- Totéž pro připojení doručenky: zápis, který připojí doručenku a zároveň
  -- hne vyřízením, je vždycky chyba, ne provozní stav.
  IF OLD.receipt_document_id IS NULL AND NEW.receipt_document_id IS NOT NULL
     AND NOT (NEW.acceptance_state <=> OLD.acceptance_state)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'attaching a delivery receipt must not change acceptance state';
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
