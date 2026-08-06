# 35. Fakturujeme — daňový průvodce

> ⚠️ **Správnost faktury je vždy na uživateli.** MyÚčto.cz je účetní
> nástroj — generuje doklady, eviduje je a umí je exportovat účetní. Není to
> daňový poradce. Sazba DPH, místo plnění, OSS / IOSS, přenesená daňová
> povinnost, registrace k DPH v cizí zemi — to vše je odpovědnost vystavitele
> faktury, nikoli aplikace. **Vždy konzultuj nestandardní situace s účetní
> nebo daňovým poradcem.** Cena za 30 minut konzultace je řádově nižší než
> sankce za špatně vystavenou fakturu.

Tato kapitola popisuje, jak MyÚčto vystavuje doklady — co dělá automaticky,
kde tě nechá rozhodnout sebe a kde aplikace končí (a tvůj účetní začíná).

## 35.1 Plátce vs. neplátce DPH

Příznak **plátce DPH** je u dodavatele (`Nastavení → Dodavatel`) — určuje
chování celé aplikace. Změna se projeví okamžitě u nově vystavovaných faktur;
už vystavené doklady se nemění.

| Co se mění | Plátce DPH | Neplátce DPH |
|---|---|---|
| Záhlaví dokladu | „Faktura — daňový doklad" | „Faktura" |
| Sloupec „DPH %" v tabulce položek | ano | **skrytý** |
| Sloupec „S DPH" | ano | **skrytý** (jen „Celkem") |
| Volba sazby DPH u položky | ano | **skrytá**, interně se ukládá 0 % |
| Reverse charge checkbox | ano (pro EU klienty s VAT ID) | **skrytý** |
| Sumace DPH (rozpis sazeb, „DPH celkem") | ano | **skrytá** |
| Banner „Není plátce DPH" | — | ano |

Neplátce dle ZDPH nemá nárok DPH účtovat ani vykazovat. Aplikace tomu odpovídá:
faktura je čistě jednosloupcová (Cena/j → Celkem) a obsahuje povinnou poznámku.
Pokud se z neplátce staneš plátcem (překročíš obrat 2 mil. Kč za 12 měsíců,
nebo se zaregistruješ dobrovolně), v dodavateli příznak přepneš a další faktury
už budou s DPH.

> 💡 Mezní situace: faktury vystavené jako neplátce v období, kdy už jsi měl
> být plátcem (zpětná registrace), je třeba opravit dodatečným daňovým
> dokladem. To MyÚčto neumí automaticky — řeš s účetní.

### 35.1.1 Identifikovaná osoba (§ 6g–6l ZDPH)

Třetí daňový status mezi plátcem a neplátcem — typicky freelancer, který
fakturuje služby do EU (a/nebo nakupuje zahraniční služby typu reklamy či
SaaS), ale v tuzemsku plátcem není. V `Nastavení → Dodavatel` nech **Plátce
DPH vypnutý** a zaškrtni **Identifikovaná osoba**.

Co se tím změní (vše ostatní zůstává jako u neplátce):

| Oblast | Chování IO |
|---|---|
| Tuzemské faktury | beze změny — bez DPH, banner „Není plátce DPH" |
| Faktura **EU** klientovi s DIČ | po výběru klienta se automaticky zapne **reverse charge** a předvyplní klasifikace **22** (EU služby → souhrnné hlášení); PDF je daňový doklad s DIČ a klauzulí „daň odvede zákazník (čl. 196 směrnice 2006/112/ES)". Sazba DPH se na dokladu **neuvádí** (samovyměří odběratel sazbou své země) — částky jsou základ daně, sloupec se proto jmenuje „Celkem bez DPH". Totéž platí v šabloně pravidelné fakturace. |
| Faktura klientovi mimo EU | bez RC — plnění je mimo předmět české DPH, žádná klauzule, žádné SHV |
| Souhrnné hlášení (SHV) | podává se za měsíce s EU službami (kód 3) — `Daně → Souhrnné hlášení` |
| Přijaté zahraniční doklady (klasifikace 23/24/25) | samovyměření DPH **bez nároku na odpočet** — daň se reálně platí |
| DPH přiznání | typ **identifikovaná osoba** (`typ_platce='I'`), jen řádky samovyměření (ř. 3–6, 12–13), **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla |
| Kontrolní hlášení | **nepodává se nikdy** — stránka KH zobrazí upozornění |

> ⚠️ Samovyměřená daň bez nároku na odpočet je u IO skutečný výdaj. Lhůta
> podání přiznání i SHV je do 25. dne následujícího měsíce; faktura za EU
> služby se vystavuje nejpozději do 15 dnů od konce měsíce plnění (§ 28).

## 35.2 Sazby DPH (číselník `CZ`)

Standardní seed obsahuje čtyři sazby pro Česko:

| Kód | Sazba | Popis | Kdy použít |
|---|---|---|---|
| `CZ-21` | 21 % | Základní | Default — většina zboží i služeb |
| `CZ-12` | 12 % | Snížená | Potraviny, knihy, ubytování, vodné/stočné, léčivé přípravky… (úplný seznam je v příloze ZDPH) |
| `CZ-0` | 0 % | Osvobozeno | Plnění osvobozená dle §51 ZDPH (např. finanční služby, vzdělávání). Také fallback pro neplátce. |
| `CZ-RC` | 0 % | Reverse charge | Přenesená daňová povinnost — sazba 0 %, daň odvádí příjemce |

Sazby spravuješ v `Nastavení → Číselníky → DPH sazby`. Můžeš přidávat další
(např. `SK-23` pro slovenský OSS — viz [§ 35.4](#354-zahranicni-fakturace-limitace-a-oss)), upravovat label nebo
zneplatnit zastaralé pomocí `valid_to`. Default sazba (`is_default`) se
předvyplňuje u nově přidané položky faktury.

> ⚠️ Sazby se přiřazují **per položku**, ne per celá faktura. Smíšené sazby
> v jedné faktuře aplikace zvládá — sumace je rozepsaná po sazbách.

## 35.3 Reverse charge (přenesená daňová povinnost)

Reverse charge (RC) přesouvá povinnost odvést DPH na **příjemce** faktury.
Vystavitel účtuje 0 % a doplní zákonnou poznámku. V MyÚčtu se RC řeší
checkboxem **Reverse charge** v editoru faktury.

### Kdy RC vystavit

- **Tuzemský RC (§ 92a–§ 92g ZDPH):** stavební a montážní práce mezi plátci v ČR,
  zlato, šrot, mobilní telefony, integrované obvody, plyn/elektřina pro
  obchodníka… (přesný výčet § 92a-g). Oba subjekty musí být plátci DPH v ČR.
- **EU B2B s reverse charge:** dodavatel je plátce DPH v ČR, klient je plátce
  DPH v jiném členském státě (má **platné VAT ID** ověřitelné přes VIES) a
  jde o B2B plnění s místem plnění v zemi příjemce dle § 9 odst. 1 ZDPH.

V obou případech aplikace nastaví všechny položky na sazbu `CZ-RC` (0 %),
sumace neukáže DPH řádky a do PDF přidá zákonnou poznámku — pro tuzemského
klienta „Daň odvede zákazník (přenesená daňová povinnost dle § 92a zákona
o DPH)", pro zahraničního „…dle čl. 196 směrnice 2006/112/ES".

### Jak RC zapnout

1. **Profil klienta:** v `Klienti → Editace` zaškrtni `Reverse charge`. Tím
   povolíš RC checkbox v editoru faktur pro tohoto klienta.
2. **VIES ověření DIČ:** v editoru faktury po výběru klienta se DIČ ověří
   přes VIES (cache 24 h). Bez platného DIČ partner nemá nárok na RC.
3. **Editor faktury:** RC checkbox je viditelný jen pokud má klient RC
   povolenou. Po zaškrtnutí se všechny položky přepnou na 0 % RC sazbu.

> 💡 RC checkbox je v editoru schovaný i tehdy, když je dodavatel **neplátce
> DPH** — neplátce RC vystavit nemůže (nemá DPH co přenášet). Výjimkou je
> **identifikovaná osoba** (§ 35.1.1) — té se RC u zahraničního klienta s DIČ
> zapne automaticky.

## 35.4 Zahraniční fakturace — limitace a OSS

Tady aplikace končí svou plnou automatiku. MyÚčto je primárně pro **B2B
fakturaci českým plátcem DPH**. Ostatní scénáře dokáže vystavit, ale daňový
režim si určí uživatel sám (volbou sazby).

### Co aplikace umí

- **CZ B2B:** plně podporováno (21 % / 12 % / RC dle situace).
- **EU B2B s platným VAT ID:** RC checkbox + VIES ověření.
- **Mimo EU (Švýcarsko, USA, UK, …):** typicky bez DPH (export služeb / zboží);
  v editoru zvol sazbu `CZ-0` (Osvobozeno). Detaily a režim daně podle země
  příjemce konzultuj s účetní.

### Co aplikace neumí určit automaticky

#### B2C v EU — OSS (One Stop Shop)

Pokud fakturuješ **nepodnikajícímu zákazníkovi v jiném členském státě EU**
(B2C), uplatňují se zvláštní pravidla:

- **Standardní služby B2C** (např. konzultace, IT práce hodinovým paušálem):
  místo plnění je v ČR dle § 9 odst. 2 ZDPH → **21 % CZ DPH** je správně.
  Žádný OSS netřeba.
- **TBE služby** (telekomunikační, broadcast, elektronicky poskytované) +
  **distance sale of goods**: místo plnění je v zemi zákazníka, jakmile
  překročíš celounijní práh **10 000 €/rok** přes všechny B2C transakce do EU.
  Pak musíš použít **DPH sazbu země zákazníka** a hlásit ji přes systém OSS
  (One Stop Shop) na finančním úřadě.

#### Příklad: SK neplátce DPH, distance sale zboží nad 10 000 € prahem

Slovensko má od 2026 sazbu **23 %** (základní). Pokud fakturuješ slovenskému
neplátci nad OSS prahem, faktura má mít:

- DIČ vystavitele s prefixem `CZ`
- DIČ příjemce **prázdné** (B2C) nebo IČO bez VAT ID
- Sazba DPH: **23 %** (sazba SK)
- Měna: typicky EUR
- DPH se odvádí přes OSS, ne klasické přiznání

**Postup v MyÚčtu:**

1. V `Nastavení → Číselníky → DPH sazby` přidej novou sazbu:
   - Kód: `SK-23`, Sazba: `23.00`, Země: `SK`, Label CS: „Standardní 23 % (SK)"
2. V editoru faktury vyber tuto sazbu na položkách, zapni u řádku **OSS**
   a vyplň zemi spotřeby, typ sazby a typ plnění. Opravu období ponech jako
   **Běžné plnění**, pokud nejde o opravu dřívějšího čtvrtletí.
3. PDF doklad bude číselně správný (23 % SK DPH), klient ho dostane. Doklad
   s OSS řádky navíc nese doložku „Daň je přiznána a odvedena ve státě spotřeby
   v režimu jednoho správního místa (One Stop Shop) podle § 110a a násl. zákona
   o DPH." se jmenovitým výčtem států spotřeby — v češtině i angličtině podle
   jazyka dokladu, stejně v PDF i ve veřejném náhledu („web faktura"). Když je
   část plnění tuzemská, doložka to řekne (týká se jen OSS položek).
4. V **Nastavení → Daňové nastavení** zapni OSS režim a vyplň zemi
   identifikace, měnu podání a dobu platnosti registrace.
5. V **Daně → OSS přiznání** zkontroluj čtvrtletní souhrn a případně stáhni
   EPO XML `OSSEI1`. Označené řádky se nezahrnou do českého přiznání k DPH,
   kontrolního hlášení ani Knihy DPH.

Částky v jiné měně se do měny podání přepočtou **kurzem Evropské centrální banky
zveřejněným pro poslední den čtvrtletí** — jedním kurzem pro celé období. Když ECB pro
poslední den kurz nezveřejnila (víkend, svátek), použije se nejbližší následující den;
použité datum kurzu vidíš v souhrnu náhledu. Kurz ČNB k DUZP se tu **nepoužívá** — ten
platí pro tuzemský základ daně, ne pro OSS podání. Dokud kurz ECB pro dané čtvrtletí
neexistuje (období ještě neskončilo, výpadek), zůstanou řádky nepřepočtené, náhled to
oznámí a XML nejde vytvořit; ruční kurz i ruční částky zadané na položce mají přednost
vždy. Opravu staršího období označ původním čtvrtletím; export ji zapíše odděleně jako
opravu. Dobropis k OSS faktuře převezme OSS údaje a částky obrátí.

**Práh 10 000 EUR** MyÚčto **sleduje** — na stránce OSS přiznání uvidíš čerpání prahu za
kalendářní rok, rozpad po zemích a upozornění od 80 % i po překročení. Do prahu se počítají
**všechna** přeshraniční B2C plnění do EU, tedy i ta, která zatím fakturuješ s českou daní
(jinak by práh nikdy nemohl být překročen). Přepočet do EUR je **orientační** (denní kurz
ČNB), takže u hodnot těsně u prahu rozhodne účetní. Sazbu porovnává systém s
[číselníkem sazeb členských států](71_Nastaveni.md#7112b-sazby-statu-oss) k datu plnění
a při neshodě **varuje** — číselník ale může zestárnout, proto nejde o blokaci.

**Historické doklady nemusíš zadávat ručně.** Import vydaných faktur (Pohoda XML, ISDOC)
umí režim OSS odvodit sám — příznak, zemi spotřeby, typ sazby i typ plnění. Postup
a co po importu zkontrolovat je v
[§ 21.4b](21_Importy.md#214b-zahranicni-doklady-a-rezim-oss).

**Řádky s nejistým místem plnění.** Doklady zakládané automaticky (pravidelná fakturace,
synchronizace z iDokladu a Fakturoidu, čtení PDF, vlastní integrace přes API) občas
nedokážou určit, jestli je řádek OSS plnění, nebo tuzemský. Takový řádek MyÚčto **nezařadí
do OSS podání** — tam nepatří, dokud to není potvrzené — ale označí ho k ručnímu posouzení
a nechá ho v českém přiznání na ř. 1 a 2. Aby ti neproklouzl:

- v **seznamu faktur** je najdeš filtrem **Místo plnění (OSS)**, volbou **Nejisté —
  v tuzemsku** ([§ 14.1.1](14_Faktury.md#nejiste-misto-plneni-oss)),
- v **přiznání k DPH** na ně upozorní varování se seznamem dokladů.

Rozhodnutí uděláš v editoru faktury (přepínač OSS na položce) nebo hromadně přes
[hromadné nastavení OSS](14_Faktury.md#1432-hromadne-nastaveni-oss). Dokud nerozhodneš,
zůstává plnění v tuzemském přiznání.

U **importu vydaných faktur** je to obráceně: nejednoznačný řádek se zařadí do OSS
a označí k posouzení — v seznamu faktur ho vypíše volba **Nejisté — v OSS podání**.
Proč se import rozhoduje opačně, vysvětluje
[§ 36 — Plnění k ručnímu posouzení](36_Vykazy_DPH.md#plneni-k-rucnimu-posouzeni).

> ⚠️ MyÚčto neurčuje samo B2C režim, neuzavírá období a XML automaticky nepodává.
> Sledování prahu i kontrola sazeb jsou **upozornění**, ne závazné určení povinnosti;
> před podáním vždy ověř sazby, zemi spotřeby, přepočet a výsledné XML s účetní nebo
> daňovým poradcem.

#### Reverse charge mimo EU

Pro export služeb mimo EU (např. faktura americkému klientovi) se v ČR často
uplatňuje 0 % (mimo předmět DPH dle § 9 odst. 1 ZDPH). To **není reverse charge**
v právním slova smyslu — checkbox „Reverse charge" v editoru je určený pro
EU režim § 92a a generuje českou zákonnou poznámku, která pro export mimo EU
není přesná. **Pro export mimo EU použij sazbu `CZ-0` (Osvobozeno)** a do
poznámky pod položkami doplň anglický text typu „Outside the scope of
EU VAT — § 9(1) of Czech VAT Act".

#### Vícero registrací k DPH

Pokud jsi registrován k DPH ve více zemích (typicky e-shop s lokálními sklady),
potřebuješ **více sazeb a více DIČ**. MyÚčto má jeden dodavatelský profil
s jedním DIČ. Workaround: založ druhého dodavatele (`Nastavení → Dodavatelé →
Přidat`) a přepínej mezi nimi pomocí přepínače v hlavičce. Není to plnohodnotná
multi-jurisdikční podpora — DPH přiznání pro každou zemi řeš s místní účetní.

## 35.5 Co MyÚčto (ne)dělá

Aby bylo úplně jasno, kde je hranice:

### MyÚčto **dělá**

- Vystavení dokladu (faktura, zálohová, dobropis, storno)
- Evidence faktur, klientů, zakázek, plateb
- Generování PDF s QR platbou
- Odesílání faktur e-mailem
- Upomínání po splatnosti
- Bankovní importy (FIO, KB, ČSOB) a párování plateb podle VS
- ARES + VIES lookup (autocomplete IČO/DIČ)
- Export pro účetní: **Pohoda XML, Stereo XML, ISDOC, PDF ZIP** (volitelné,
  pokud chceš část agendy řešit jinde)
- Podvojné účetnictví i daňovou evidenci — **účetní deník, hlavní knihu,
  předvahu, rozvahu, výsledovku**, majetek a odpisy, uzávěrku
- XML pro EPO portál MFČR: **DPH přiznání (DPHDP3), kontrolní hlášení,
  souhrnné hlášení, daň z příjmů (DPFO/DPPO, řádné i opravné/dodatečné,
  vč. hospodářského roku)** — jako pomůcka k ověření s účetní
- Pojistné OSVČ: přehledy sociálního (ČSSZ, včetně XML e-podání) a
  zdravotního pojištění (zatím PDF pomůcka)

### MyÚčto **nedělá**

- IOSS přiznání a automatické podání OSS; MyÚčto připraví kvartální OSS
  přehled a XML `OSSEI1`, ale podání a odbornou kontrolu nechává uživateli
- Kalkulaci marží, výrobu a kusovníky
- Mzdy, fakturace s návazností na pracovní smlouvy
- Insolvenční registr, registr ekonomických subjektů
- E-podání přehledu OSVČ pro zdravotní pojišťovny (zatím jen PDF pomůcka)

Standardní tok je: **MyÚčto vystaví doklady → vygeneruje výkazy DPH →
uživatel/účetní jednou měsíčně exportuje (Pohoda XML / Stereo XML / ISDOC) → účetní
doklady zaúčtuje a ověřené výkazy podá**. Aplikace primárně **eviduje,
podává a generuje výkazy** z dokladů, neúčtuje je.

## 35.6 Když si nejsi jistý

V pochybnostech platí jednoduchá poučka: **vyber konzervativnější variantu
a zeptej se účetní**.

- Nejsi si jistý, jestli klient má nárok na RC? → **Nepoužij RC**, dej 21 %.
  Klient si DPH odpočte, ty odvedeš. V nejhorším řešíš opravným dokladem.
- Nevíš, jestli máš účtovat 21 % CZ nebo 23 % SK? → **Použij 21 % CZ**, není
  to z hlavy chyba; pokud jsi měl být v OSS, doplníš to v daňovém přiznání.
- Klient se ozve, že DPH je špatná? → V editoru opravíš a vystavíš
  **opravný daňový doklad** (dobropis k původní + nová faktura). Aplikace
  to umí v 2 klikách.

> 💡 **Doporučení**: jednou ročně (typicky leden) projdi s účetní seznam
> svých klientů, sazeb a typů plnění. Pravidla DPH se mění (sazby, OSS prahy,
> elektronické fakturace). Hodinová konzultace ti zachrání spoustu
> opravných dokladů.

---

→ Pokračuj na [18. Klienti](18_Klienti.md), nebo se vrať na [INDEX](INDEX.md).
