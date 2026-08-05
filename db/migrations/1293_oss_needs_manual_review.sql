-- MyÚčto.cz — „místo plnění k ručnímu posouzení" musí přežít zavření reportu importu.
--
-- Derivace OSS umí skončit stavem, kdy systém místo plnění NEURČIL: sazba platí podle
-- číselníku členských států zároveň ve státě spotřeby i v zemi dodavatele (21 % v NL, BE,
-- ES, LT i LV, 12 % ve Švédsku), nebo se číselníku k datu plnění nedalo zeptat. Takový
-- řádek se zařadí do OSS — chybně označený OSS řádek je vidět v krátkém náhledu podání,
-- kdežto chybně označený tuzemský řádek zmizí mezi stovkami řádků přiznání k DPH — a
-- označí se jako případ pro člověka.
--
-- Dosud ta kategorie žila JEN v odpovědi importu. U migrace 1 670 dokladů je to k ničemu:
-- po zavření stránky ji nikdo nedohledá a hromadnou opravu (vlna 2) nemá nad čím pustit.
-- Příznak proto patří k položce, ne do reportu.
--
-- ── Proč sloupec, a ne odvození z dat ───────────────────────────────────────────────
-- Z uloženého řádku už rozhodnutí zpětně nespočítáte: číselník se mezitím může doplnit
-- (migrace 1292 to udělala) a sazba, která byla nejednoznačná v okamžiku importu, jím
-- příště být nemusí. Příznak je záznam o TEHDEJŠÍ nejistotě, ne dnešní dotaz.
--
-- ── Proč se needs_review NERUŠÍ automaticky ─────────────────────────────────────────
-- Zhasnout ho smí jedině člověk (hromadná editace vlny 2), protože potvrzení místa plnění
-- je rozhodnutí, ne výpočet. Automatické „už to umíme spočítat, tak to odškrtneme" by
-- tichou nejistotu jen přejmenovalo na tiché rozhodnutí.
--
-- Zpětně kompatibilní: kód sloupec zapisuje jen pod `Connection::hasColumn()`, takže
-- instance bez téhle migrace importuje dál, jen bez příznaku.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS oss_needs_manual_review TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = systém neurčil místo plnění, řádek čeká na ruční posouzení'
      AFTER oss_original_period;

-- Index vede příznakem, ne `oss_applicable`: hledá se vždy „ukaž řádky k posouzení",
-- nikdy „ukaž OSS řádky a z nich ty k posouzení", a jedniček je proti celé tabulce
-- hrstka. `invoice_id` je ve složce třetí schválně — přehled „které doklady čekají na
-- posouzení" se pak obslouží z indexu bez sáhnutí na řádky.
CREATE INDEX IF NOT EXISTS idx_invoice_items_oss_manual_review
  ON invoice_items (oss_needs_manual_review, oss_applicable, invoice_id);
