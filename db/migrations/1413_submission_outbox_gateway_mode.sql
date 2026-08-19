-- Třetí způsob, jak podání opustí aplikaci: odesílací brána ISDS.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč `gateway` není ani `channel`, ani `manual`
-- ─────────────────────────────────────────────────────────────────────────────
-- `channel`  — zprávu odešle aplikace sama, pod přihlášením zákazníka. Platí
--              pro ni obě brány: XSD kontrola i ověření schránky příjemce
--              dotazem do ISDS.
-- `manual`   — zprávu odešle člověk ve své datové schránce. Aplikace o odeslání
--              ví jen z toho, co jí uživatel poví.
-- `gateway`  — aplikace vloží KONCEPT do perimetru ISDS a odeslání schválí
--              uživatel přímo v ISDS. Zpráva odchází z jeho schránky, ale
--              obsah, přílohu i adresáta složila aplikace.
--
-- Rozdíl proti `channel` je v tom, co brána UMÍ: podle `odesilaci_brana_ISDS.pdf`
-- v. 1.11 nabízí právě tři webové služby (`GetCredential`, `SetConcept`
-- a `extWsLogout`, plus `GetPDZInfo`) — v celé specifikaci není jediná zmínka
-- o čtení schránky. `checkDataBox` ani `findDataBox2` tedy touhle cestou zavolat
-- NELZE a sloupec `recipient_box_verified_at` se nemá čím naplnit.
--
-- Není to díra: adresáta uživatel před schválením VIDÍ v ISDS a nedoručitelnost
-- ISDS hlásí zpátky v `conceptStatusCode` (kap. 3.4 bod 4) — tedy dřív, než
-- aplikace zapíše odeslání. Je to jiný důkaz než dotaz na schránku, ale je
-- to důkaz, a přichází od téže autority.
--
-- Co se u `gateway` naopak NEuvolňuje, je XSD kontrola. Ta má plné veto stejně
-- jako u `channel`: koncept vkládáme my a vadné podání se dá zastavit dřív, než
-- opustí aplikaci. Uvolnění veta u `manual` mělo důvod (zpráva už byla pryč),
-- tady žádný takový důvod není.

SET NAMES utf8mb4;

ALTER TABLE submission_outbox
  MODIFY COLUMN dispatch_mode ENUM('channel','manual','gateway') NOT NULL DEFAULT 'channel'
    COMMENT 'channel = odeslala aplikace přes kanál; manual = odeslal člověk ze své schránky; gateway = koncept přes odesílací bránu ISDS, schválil uživatel v ISDS';

-- Brána ověření schránky: nevyžaduje se u `manual` (nemá se čím naplnit)
-- ani u `gateway` (odesílací brána čtení schránky neumí — viz hlavička).
ALTER TABLE submission_outbox DROP CONSTRAINT IF EXISTS chk_submission_outbox_box_verification_gate;
ALTER TABLE submission_outbox
  ADD CONSTRAINT chk_submission_outbox_box_verification_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_mode IN ('manual','gateway')
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR recipient_box_verified_at IS NOT NULL
    );

-- XSD brána beze změny proti 1384, jen znovu vytvořená kvůli idempotenci
-- migrace. `gateway` do uvolněné větve ZÁMĚRNĚ nepatří — veto zůstává.
ALTER TABLE submission_outbox DROP CONSTRAINT IF EXISTS chk_submission_outbox_validation_gate;
ALTER TABLE submission_outbox
  ADD CONSTRAINT chk_submission_outbox_validation_gate
    CHECK (
      channel <> 'isds'
      OR dispatch_state NOT IN ('send_uncertain','sent','delivered')
      OR (dispatch_mode = 'manual' AND artifact_validation_status IS NOT NULL)
      OR artifact_validation_status IN ('passed','skipped')
    );
