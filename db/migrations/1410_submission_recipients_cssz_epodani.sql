-- ─────────────────────────────────────────────────────────────────────────────
-- Datové schránky e-Podání ČSSZ do číselníku příjemců
-- ─────────────────────────────────────────────────────────────────────────────
--
-- PROČ TAHLE MIGRACE VŮBEC JE
-- ---------------------------
-- Migrace 1381 zavedla číselník `submission_recipients` a u ČSSZ tehdy nechala
-- prázdno s odůvodněním, které stálo na dvou tvrzeních:
--
--   „ČSSZ se neseeduje: doklad nemáme a mzdová podání jdou dnes jiným kanálem
--    (VREP)."
--
-- Obě tvrzení dnes neplatí:
--
--   1. Doklad JE. Stránka ČSSZ „Komunikační kanály e-Podání"
--      <https://www.cssz.gov.cz/komunikacni-kanaly-e-podani> (staženo 17. 8. 2026,
--      uloženo v `private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/`)
--      uvádí ID schránek doslova. Potvrzuje je i Podávací a dotazovací protokol
--      ČSSZ v1.47 z 11. 2. 2025, kapitola „Prostředí" (strany 47 a 48).
--   2. ISDS je pro JMHZ rovnocenný kanál vedle VREP, ne náhradní cesta — ČSSZ
--      pro JMHZ dokonce zřídila SAMOSTATNOU datovou schránku.
--
-- Bez záznamu v číselníku nelze JMHZ zařadit do fronty podání datovou schránkou:
-- `SubmissionOutboxService::enqueue()` u kanálu `isds` bez příjemce skončí
-- `recipient_required`. Ruční cesta (uživatel si stáhne přílohu a odešle ji ze
-- své schránky) tím byla pro mzdy nedostupná.
--
-- CO SE TU ZÁMĚRNĚ NEMĚNÍ
-- -----------------------
-- * Schránky místně příslušných OSSZ/PSSZ/MSSZ se NESEEDUJÍ. Oba zdroje je
--   připouštějí, ale konkrétní ID závisí na firmě a nemáme ho odkud vzít.
--   Opsat je od oka je přesně to hádání, kterému se číselník brání.
-- * Nic se nepředvyplňuje jako výchozí volba. Číselník je nabídka, ne rozhodnutí.
-- * `verified_in_isds_at` zůstává NULL: ověřit schránku umí jedině dotaz do ISDS
--   (checkDataBox / findDataBox2), a ten zatím nemáme napojený. Vyplnit ho tady
--   by tvrdilo ověření, které neproběhlo.
--
-- Migrace je idempotentní: seed běží přes NOT EXISTS na (supplier_id IS NULL, code),
-- takže druhé spuštění nic nezdvojí a nic nepřepíše.

SET NAMES utf8mb4;

INSERT INTO submission_recipients (supplier_id, code, name, kind, isds_box_id, source_url, source_note)
SELECT * FROM (
  -- Schránka zřízená ČSSZ VÝSLOVNĚ pro JMHZ. Pro měsíční hlášení má přednost
  -- před obecnou schránkou e-Podání: protokol v1.47 je z února 2025, tedy z doby
  -- před zavedením JMHZ (agendu JMHZ vůbec nezná), kdežto stránka komunikačních
  -- kanálů je novější a zřizuje pro JMHZ schránku vlastní.
  SELECT NULL AS supplier_id, 'cssz_epodani_jmhz' AS code,
         'ČSSZ — e-Podání JMHZ (měsíční hlášení zaměstnavatele)' AS name,
         'cssz' AS kind,
         'iie254d' AS isds_box_id,
         'https://www.cssz.gov.cz/komunikacni-kanaly-e-podani' AS source_url,
         'Doslova: „Pro podání JMHZ je určena nová datová schránka: ID schránky: iie254d". Podklad private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/' AS source_note

  -- Obecná specializovaná schránka e-Podání ČSSZ. Protokol ji označuje za
  -- preferovanou pro e-Podání obecně; pro JMHZ je to doložená ZÁLOHA, ne výchozí
  -- cesta. Používá se pro ostatní agendy ČSSZ (ELDP, ONZ, PVPOJ…).
  UNION ALL SELECT NULL, 'cssz_epodani_obecna',
         'ČSSZ — e-Podání (obecná schránka)', 'cssz', '5ffu6xk',
         'https://www.cssz.gov.cz/komunikacni-kanaly-e-podani',
         'Potvrzeno i Podávacím a dotazovacím protokolem ČSSZ v1.47 z 11. 2. 2025, kapitola Prostředí → Produkční prostředí → ISDS, strana 47'

  -- Testovací schránka e-Podání ČSSZ. Testovací prostředí ČSSZ je napojené na
  -- testovací ISDS (czebox.cz) a vyžaduje samostatnou registraci. Vlastní
  -- testovací schránku JMHZ nemá — cvičné podání jde sem.
  UNION ALL SELECT NULL, 'cssz_epodani_test',
         'ČSSZ — e-Podání TEST (testovací prostředí)', 'cssz', '9tsaf6s',
         'https://www.cssz.gov.cz/web/cz/ke-stazeni',
         'Podávací a dotazovací protokol ČSSZ v1.47 z 11. 2. 2025, kapitola Prostředí → Testovací prostředí → ISDS, strana 48'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM submission_recipients r
   WHERE r.supplier_id IS NULL AND r.code = seed.code
);
