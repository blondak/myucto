# 78. Licence a aktivace

MyÚčto.cz je nástupcem open-source systému MyInvoice. Všechny funkce MyInvoice
zůstávají v MyÚčto navždy zdarma; rozšířená účetní nadstavba je komerční
produkt na předplatné. Tato kapitola popisuje, jak licence funguje, kde ji
spravuješ, co se děje po vypršení a jak předplatné zakoupíš, aktivuješ,
navýšíš nebo přeneseš na jinou instalaci.

## 78.1 Licenční model

MyÚčto stojí na dvou vrstvách:

- **Bezplatný základ MyInvoice.** Veškeré koncové funkce původního projektu
  [MyInvoice](https://github.com/radekhulan/myinvoice) lze používat navždy
  zdarma, včetně vytváření a úprav dat. Původní zdrojový kód zůstává pod MIT.
- **Komerční nadstavba (source-available).** Podvojné účetnictví, účetní
  nástroje a uzávěrky, sklad a napojení e-shopu, evidence majetku, EPO podání
  a archív a rozšířené opravy DPH podle § 74b, § 43, § 46 a § 79 jsou
  proprietární a vyžadují komerční licenci sjednanou **předplatným na
  myucto.cz**.

Zdrojový kód komerční části je sice viditelný, ale jeho zpřístupnění samo o sobě
nezakládá právo produkt jako celek provozovat bez licence.

> 🛈 **60 dní zdarma, bez registrace.** Novou instalaci lze prvních 60 dní od
> prvního spuštění používat v plném rozsahu bezplatně, bez registrace i platby.
> Teprve po uplynutí zkušebního období vyžaduje komerční část aktivaci
> licenčním klíčem.

Cena předplatného je **za jednoho aktivního uživatele a měsíc**; celková cena
je násobkem tarifu a počtu aktivních uživatelů (viz [§ 78.3](#783-zkusebni-obdobi-a-stavy-licence)
ke způsobu započítání). Úplné znění licenčního ujednání je v souboru
`LICENCE.txt` v rootu instalace a na `myucto.cz/licence`.

## 78.2 Kde licenci spravovat

Správa licence je v menu **Aktivace** — úplně dole v hlavním menu. Obsahuje
tři stránky:

| Stránka | Co obsahuje |
|---|---|
| **Licence** | Přehled bezplatných a komerčních funkcí, zkušebního období a tarifů. |
| **Obchodní podmínky** | Shrnutí hlavních článků podmínek předplatného (závazné je plné znění na myucto.cz). |
| **Zakoupení** | Provozní stránka — aktuální stav licence, zakoupení předplatného, aktivace klíčem, navýšení uživatelů a deaktivace. |

> 🛈 Správu licence (stránku **Zakoupení**) vidí a ovládá jen **administrátor**.
> Běžný uživatel stránku otevře, ale operace nejsou dostupné.

## 78.3 Zkušební období a stavy licence

Stav licence se počítá při každém přihlášeném požadavku a promítá se do banneru
v aplikaci i do karty stavu na stránce **Aktivace → Zakoupení**.

| Stav | Význam | Provoz |
|---|---|---|
| **Zkušební období** | Bez klíče, méně než 60 dní od prvního spuštění. Ukazuje se odpočet do konce. | Plný, bez limitů |
| **Zkušební období skončilo** | Bez klíče, po 60 dnech. | Bezplatné funkce plně; komerční moduly nedostupné |
| **Aktivní** | Platný klíč, předplatné běží. | Plný, do počtu licencovaných uživatelů a firem |
| **Překročen rozsah (overage)** | Víc aktivních uživatelů nebo firem, než licence pokrývá. | Plný provoz + výzva, ale nelze zakládat další uživatele/firmy |
| **Komerční funkce nedostupné (degraded)** | Předplatné neobnoveno (po ochranné lhůtě), případně chybí/neplatný podpis tokenu. | Bezplatné funkce plně; komerční moduly nedostupné |

**Kdo se počítá do limitu uživatelů.** Do počtu licencovaných míst se počítají
**aktivní uživatelé**, kromě účtů s rolí **jen pro čtení** (readonly) a
klientských portálových účtů. Deaktivované účty se nepočítají a nejsou
zpoplatněné. Vedle uživatelů se hlídá i **počet firem** (dodavatelů) proti
limitu tarifu.

**Překročení rozsahu (overage).** Když aktivních uživatelů nebo firem přibude
nad rámec klíče, aplikace na to upozorní a poskytne lhůtu 14 dní na rozšíření
předplatného (nebo srovnání počtů). Provoz zůstává plný, jen nejde zakládat
další uživatele ani firmy. Po marném uplynutí lhůty se obnova licence pozastaví
a komerční nadstavba se vypne.

> ⚠️ **Bezplatná část zůstává plně funkční.** Lze dál vystavovat a přijímat
> doklady, spravovat kontakty, importovat bankovní výpisy, vést základní
> daňovou evidenci a používat ostatní funkce převzaté z MyInvoice. Nedostupný
> je celý **Sklad**, **Účetnictví** a **Nástroje**, evidence majetku, EPO podání
> a archív a opravy DPH podle § 74b, § 43, § 46 a § 79. Tyto komerční stránky
> nejdou bez licence ani zobrazit, exportovat nebo volat přes API.
>
> Data komerčních modulů se nemažou ani nemění; zůstávají ve vlastní databázi
> provozovatele. Aplikace je znovu zpřístupní po obnovení licence.

## 78.4 Zakoupení předplatného

Na stránce **Aktivace → Zakoupení** klikni na **Zakoupit předplatné**. Otevře
se objednávka na myucto.cz s předvyplněnou instalací a fakturačními údaji firmy
(vše lze na webu ještě upravit). Tam zvolíš:

- **tarif** podle počtu firemních agend — **Jedna firma** (1 agenda),
  **Účetní kancelář** (až 10 agend) nebo **Neomezeně** (bez limitu firem),
- **počet uživatelů**,
- **období** — měsíční, nebo roční.

> 💡 **Roční předplatné = 10 měsíčních plateb** (dva měsíce zdarma).

Po zaplacení první platby je smlouva uzavřena a **licenční klíč přijde
e-mailem** (obvykle během minut), spolu s potvrzením.

## 78.5 Aktivace licenčním klíčem

Klíč z e-mailu vlož na stránce **Aktivace → Zakoupení** do pole v sekci
**Aktivace licenčního klíče** a klikni **Aktivovat**. Aktivací se licence
**naváže na tuto instalaci** (jedinečný identifikátor vytvořený při prvním
spuštění). Jeden klíč smí být v jednom okamžiku aktivní na jedné instalaci.

Po aktivaci aplikace platnost licence **jednou denně online ověřuje** vůči
serveru myucto.cz a získává kryptograficky podepsané potvrzení s platností
14 dní. Krátkodobý výpadek internetu proto provoz neomezí. Ověřování běží
samo, na pozadí — **nevyžaduje žádné nastavení uživatele**.

> 🛈 **Co se při ověření přenáší.** Jen technické údaje — identifikátor
> instalace, licenční klíč, verze produktu a souhrnné počty aktivních
> uživatelů a firem. **Žádná účetní ani osobní data** se na licenční server
> neposílají.

## 78.6 Navýšení počtu uživatelů

Potřebuješ-li přidat další licencované uživatele během běžícího období, není
třeba zakládat novou objednávku — navýšení se dělá **přímo v aplikaci**.
V sekci **Navýšit počet uživatelů** (na stránce Zakoupení) zadej cílový počet,
nech si **Spočítat cenu** a potvrď. Server strhne jen **poměrný doplatek do
konce aktuálního období z uložené karty** a **místa naskočí ihned**. Od dalšího
cyklu se pak účtuje plná nová cena.

Stejné tlačítko se nabídne i v případě, že jsi v překročeném rozsahu
(overage) — navýšením přečerpání odstraníš.

## 78.7 Přenos licence a přeinstalace

Licenci lze přesunout na jinou instalaci (nový server, přeinstalace) —
**nejvýše dvakrát za 30 dní**.

- **Řízený přenos.** Na staré instalaci klikni na **Deaktivovat** (uvolní vazbu)
  a na nové instalaci klíč běžně **aktivuj**.
- **Přenos po zániku instalace (takeover).** Když stará instalace už
  neexistuje a nešlo ji deaktivovat, aktivace klíče na nové instalaci nahlásí,
  že je klíč aktivní jinde. Aplikace pak nabídne tlačítko
  **Aktivovat na této instalaci (přenést)** — původní vazba se uvolní a licence
  se přiváže sem. I tento přenos se počítá do limitu 2 přenosů za 30 dní.

> 🛈 Deaktivace smaže klíč lokálně i tehdy, když je licenční server zrovna
> nedostupný — nezasekneš se. Vyčerpáš-li limit přenosů, další povolí poskytovatel
> na žádost (kontakt na myucto.cz).

## 78.8 Přehled dokladů a plateb

- **Daňový doklad** za každou platbu chodí **e-mailem**.
- **Opakované platby** (kartou přes platební bránu) běží automaticky v pevné
  výši, měsíčně nebo ročně, s tvým souhlasem. **Zrušit je můžeš kdykoli** ke
  konci zaplaceného období — správu opakovaných plateb, přehled objednávek i
  fakturačních údajů řeší web
  [myucto.cz](https://myucto.cz/), ne aplikace.

Po zrušení licence doběhne do konce zaplaceného období; poměrná část se
nevrací. Detailní pravidla jsou v **Aktivace → Obchodní podmínky**.

## 78.9 Řešení potíží

| Problém | Co s tím |
|---|---|
| **Klíč nejde aktivovat** | Zkontroluj, že jsi klíč zkopíroval celý (formát `MYU-XXXX-…`) a že je instalace online. Chyba serveru se vypíše přímo pod polem. |
| **„Tato licence je aktivní na jiné instalaci"** | Klíč běží jinde (typicky po přeinstalaci bez deaktivace). Použij **Aktivovat na této instalaci (přenést)** — viz [§ 78.7](#787-prenos-licence-a-preinstalace). |
| **Poslední kontrola selhala** | Krátký výpadek internetu nevadí — token platí 14 dní. Když výpadek trvá, ověř konektivitu na `myucto.cz`. |
| **Komerční moduly zmizely z menu** | Vypršelo předplatné (nebo skončil trial). Bezplatné funkce zůstávají plně dostupné. Komerční funkce obnovíš aktivací licence na stránce **Zakoupení**; jejich data zůstávají v databázi beze změny. |

Další diagnostika a časté chyby jsou v kapitole
[99. Řešení problémů](99_Reseni_problemu.md).
