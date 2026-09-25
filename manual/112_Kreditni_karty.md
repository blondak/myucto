# 112. Kreditní karty

Sekce **Peníze → Kreditní karty** vede úvěrové účty ke kreditním kartám. Kreditní
karta je v podstatě kontokorent: nákupy kartou zvyšují dluh vůči bance, splátky ho
snižují. Aplikace načte PDF výpis ke kreditní kartě, pohyby zaúčtuje na
**231 Krátkodobé úvěry** s analytikou pro každý úvěrový účet a nákupy spáruje
s přijatými doklady stejně jako platby z běžného účtu.

Kreditní karta se vede **jen tady**, ne mezi [Platebními kartami](31_Platebni_karty.md).
Platební karty jsou karty k běžnému účtu: jejich platby jdou z bankovního výpisu
běžného účtu a účtují se proti bankovnímu účtu 221. Nákup kreditkou jde z výpisu
úvěrového účtu a účtuje se přes úvěrový účet, i když výpis nese koncovku karty.
Platební kartu s koncovkou, kterou nesou jen výpisy kreditní karty, aplikace
nezaloží a odkáže sem.

## 112.1 Načtení výpisu

Akce **Nahrát výpis** načte PDF výpis ke kreditní kartě. Podporované banky:

| Banka | Výpis | Identifikace účtu |
|---|---|---|
| Komerční banka | Výpis z účtu ke kreditní kartě | číslo úvěrového účtu |
| Raiffeisenbank | Výpis z kartového účtu | referenční číslo karty (RB číslo účtu netiskne) |
| Česká spořitelna (Erste) | Výpis z kartového účtu | číslo kartového účtu |
| ČSOB | Výpis z úvěrového účtu ke kartě | číslo úvěrového účtu |

Výpis kreditní karty jde načíst i v sekci Banka akcí Nahrát PDF: aplikace ho
pozná a zpracuje stejně jako na stránce Kreditní karty. Výpis z e-mailové
schránky se načte automaticky, pokud úvěrový účet už v aplikaci je; první výpis
nového účtu nahrajte ručně.

Při načtení aplikace:

- **zkontroluje výpis**: součet pohybů musí sedět na rozdíl konečného
  a počátečního zůstatku na haléř, jinak výpis odmítne a nic neuloží,
- **založí úvěrový účet**, pokud ho firma ještě nemá. Účet založený importem je
  označený „Ke kontrole", dokud jeho údaje neuložíte v detailu,
- **převezme z výpisu** úvěrový limit a u Raiffeisenbank i účet a variabilní
  symbol pro splátku (jen do prázdných polí),
- **uloží pohyby** jako bankovní pohyby účtu. Stejný soubor ani pohyb
  z překrývajícího se výpisu se neuloží dvakrát,
- **zaúčtuje** pohyby, které umí (viz Účtování), ostatní jdou do fronty
  Zaúčtuj doklady stejně jako pohyby z běžného účtu.

Úvěrový účet, který eviduje jiná firma, načíst nejde. Kreditní účet vedený
v cizí měně aplikace odmítne; všechny podporované banky vedou kreditní účty
podnikatelů v korunách. Výpisy kreditní karty jde načíst jen ve firmě
v podvojném účetnictví (viz Daňová evidence).

Úvěrový účet se na záložce **Kontace účtů** v bance nevypíná: jeho pohyby se účtují na 231 a vypnutý by je poslal na 221.
Kartu, kterou už nepoužíváte, archivujte v detailu kreditní karty.
Nákupy kreditkou se automaticky párují s přijatými fakturami stejně jako
odchozí platby z běžného účtu; splátky na kreditní účet se s vydanými
fakturami nepárují.

### 112.1.1 Účet vedený dřív jako bankovní účet

Výpis ČSOB ke kreditní kartě má stejný vzhled jako výpis běžného účtu. Pokud jste
ho v minulosti načetli v sekci Banka, vede ho aplikace jako bankovní účet a jeho
pohyby jsou zaúčtované na 221. Při dalším načtení nabídne **převod na kreditní
kartu**. Převod:

- přepne účet na úvěrový účet kreditní karty a přidělí mu analytiku 231,
- přepíše zaúčtované pohyby účtu z 221 na analytiku 231; protiúčty, částky ani
  data se nemění,
- nejde provést, pokud má účet zaúčtované pohyby v uzavřeném účetním období.

Přesun mezi 221 a 231 nemá vliv na daně, proto se zápisy přepisují na místě i
v datu zamčeném podaným přiznáním k DPH.

## 112.2 Přehled a detail

Přehled ukazuje u každého úvěrového účtu banku, číslo účtu, analytiku 231, limit,
**zůstatek v účetnictví** a poslední výpis. Pod zůstatkem je kontrola proti
výpisu: zůstatek analytiky ke dni posledního výpisu se porovná s jeho konečným
zůstatkem. „Sedí na výpis" znamená, že účetnictví odpovídá bance. Dluh je
v účetnictví i na výpisu záporný.

V detailu úvěrového účtu upravíte:

| Pole | Význam |
|---|---|
| Název | vaše pojmenování účtu |
| Úvěrový limit | limit úvěru podle smlouvy nebo výpisu |
| Účet pro splátku, kód banky | kam posíláte splátku z běžného účtu |
| Variabilní symbol splátky | VS, pod kterým banka splátku přijímá |
| Poznámka | volný text |

Úvěrový účet, který už nepoužíváte, **archivujte**. Výpisy i zaúčtované pohyby
zůstanou beze změny, účet se jen skryje z přehledu.

### 112.2.1 Zpracování pohybů ve výpisu

Výpis kreditní karty je bankovní výpis jako každý jiný. Pohyby zpracujete
v detailu výpisu v sekci Banka se všemi akcemi bankovního pohybu: spárování
s dokladem (i částečné a na více dokladů), zrušení párování, zaúčtování MD/D
i rozúčtování na více řádků, přeúčtování, zrušení zaúčtování, ignorování,
poznámka, dimenze, pravidlo z pohybu a návrhy automatiky. Strana úvěru se
u pohybu kreditní karty vždy zapíše na analytiku 231 úvěrového účtu.

Detail kreditní karty slouží jako rozcestník:

- u každého pohybu ukazuje druh (nákup, vratka, splátka, úrok, poplatek, výběr
  hotovosti, odměna) a **stav**: nezaúčtováno, návrh ke schválení, zaúčtováno,
  ignorováno. Klik na pohyb otevře výpis rovnou na něm,
- souhrn **Co zbývá dořešit** sečte počty a částky nezaúčtovaných pohybů
  a návrhů a otevře výpis na nejstarším z nich,
- sekce **Nákupy bez dokladu** ukazuje nákupy tohoto úvěrového účtu, ke kterým
  zatím není doklad: nahrajete k nim účtenku nebo je spárujete (akce stejné jako
  u platebních karet). Nákupy kreditkou se v Platebních kartách neukazují,
- tabulka výpisů ukazuje ke každému výpisu zůstatek analytiky 231 ke dni výpisu
  (zeleně, když sedí na konečný zůstatek výpisu), počet nevyřešených pohybů
  a tlačítko **Zpracovat ve výpisu**.

Měsíční odsouhlasení tak znamená: zůstatek 231 ke dni výpisu sedí na výpis
a souhrn Co zbývá dořešit je prázdný.

### 112.2.2 Počáteční dluh

První načtený výpis většinou začíná dluhem z doby, kdy výpisy ještě v aplikaci
nebyly. Detail úvěrového účtu ho nabídne k zaúčtování: ukáže počáteční zůstatek
výpisu, kolik z něj v účetnictví chybí, protiúčet (výchozí z nastavení, obvykle
379) a datum zápisu (den prvního pohybu výpisu). Zápis je dluh MD protiúčet /
D 231.x, přeplatek obráceně.

- Zaúčtuje se jen rozdíl proti tomu, co na analytice 231 už leží mimo pohyby
  výpisů. Dluh převzatý jinak (převodem zůstatků, ručním zápisem) se nezdvojí.
- Počáteční dluh jde zaúčtovat jen jednou. Po stornu zápisu ho detail nabídne
  znovu.
- Do uzavřeného nebo zamčeného období ho aplikace nezaúčtuje; zvolte datum
  v otevřeném období.
- Protiúčet smí být z tříd 3 a 4. Stav k začátku účetního roku patří do
  počátečních zůstatků (účet 701), ne sem.

### 112.2.3 Nákupy bez dokladu

Sekce **Nákupy bez dokladu** v detailu kreditní karty ukazuje nákupy úvěrového
účtu, ke kterým zatím není spárovaný přijatý doklad. Nahoře je název účtu, počet
plateb a jejich součet; pod obchodníkem je popis z výpisu (datum transakce,
zúčtovaná částka a měna, místo). Sekce se zobrazuje jen v podvojném účetnictví.
Odkaz **Detail kreditní karty** u názvu účtu vede na tentýž detail.

U každého nákupu jsou tyto akce:

- **Nahrát účtenku**: vyberete PDF nebo fotografii (do 32 MB). S nastavenou AI
  se účtenka vytěží do konceptu přijatého dokladu s formou úhrady karta; otevřete
  ho z oznámení, zkontrolujte a potvrďte. Vložené ISDOC má přednost před AI,
  fotografie se převede na PDF a stejný soubor se podruhé nezaloží. Bez AI nebo
  při neúspěšném vytěžení se účtenka uloží do **Příchozích dokladů** (složka
  Příchozí doklady / rok / měsíc) s vazbou na platbu a doklad založíte tam.
  Samotné nahrání nic nezaúčtuje.
- **Spárovat**: po potvrzení dokladu spustí párování nákupu znovu. Výpisy
  kreditních karet často neuvádějí koncovku karty, takže doklad se k nákupu
  přiřadí až tímto tlačítkem. Spárováním se nákup zaúčtuje MD 321 / D 231.x
  (s kurzovým či haléřovým rozdílem). Podobný doklad se nabídne jako návrh
  k potvrzení v detailu výpisu.
- **Výpis**: otevře výpis kreditní karty rovnou na tomto pohybu.

Nákup, ke kterému doklad nebude, zaúčtujete ve výpisu jako každý jiný bankovní
pohyb: ručně (MD/D), pravidlem nebo schválením návrhu automatiky.

**Splátka kreditní karty** se spáruje jako převod. Splátka z vlastního běžného účtu
se spáruje jako vlastní převod: na úvěrovém účtu MD 231.x / D 261, na běžném
účtu MD 261 / D 221.x. Splátka bez protiúčtu ve výpisu se zaúčtuje MD 231.x /
D 261 (nastavitelné 261 nebo 395).

## 112.3 Účtování

Každý úvěrový účet má vlastní analytiku **231.101, 231.102 …**, přidělovanou
postupně. Analytiku, na které už leží cizí zápisy (typicky bankovní úvěr),
si úvěrový účet automaticky nevezme. Jinou existující analytiku 231 lze vybrat
ručně v detailu účtu, pokud na stávající analytice není zůstatek.

Datum zápisu je **datum zaúčtování bankou**, ne datum transakce. Zůstatek
analytiky tak ke každému dni odpovídá výpisu. Datum transakce a původní částka
v cizí měně zůstávají v popisu pohybu.

### 112.3.1 Nákupy kartou

Nákup kartou se účtuje **přímo proti úvěru**, bez mezičlenu: spárováním
s přijatým dokladem (MD 321 / D 231.x, kurzový rozdíl 563/663, haléřový rozdíl
548/648), pravidlem, naučenou kontací nebo ručně. Dokud k tomu nedojde, na 231
chybí a kontrola proti výpisu ukáže rozdíl. Účtenku k nákupu nahrajete v sekci
Nákupy bez dokladu v detailu kreditky; vytěží se do přijatého dokladu a po jeho
kontrole ho spárujete.

Akce **Zaúčtovat čekající pohyby** v detailu účtu pošle nezaúčtované pohyby
výpisů znovu automatikou podle aktuálního nastavení, typicky po spárování
nákupů s doklady.

### 112.3.2 Zápisy

| Případ | Zápis |
|---|---|
| Nákup kartou spárovaný s dokladem | MD 321 / D 231.x |
| Nákup bez dokladu podle pravidla nebo ručně | MD 5xx / D 231.x |
| Vratka spárovaná s dobropisem | MD 231.x / D 321 |
| Počáteční dluh z prvního výpisu | MD 379 / D 231.x |
| Splátka z vlastního běžného účtu | kreditní karta MD 231.x / D 261, běžný účet MD 261 / D 221.x |
| Splátka bez protiúčtu na výpisu | MD 231.x / D 261 |
| Úrok | MD 562 / D 231.x |
| Poplatek | MD 568 / D 231.x |
| Výběr hotovosti kartou | MD 261 / D 231.x |
| Odměna, cashback připsaný na úvěrový účet | MD 231.x / D 648 |

V pravidlech se strana banky zadává jako 221; u pohybu kreditní karty se zapíše
na analytiku 231 úvěrového účtu. Nákup v cizí měně banka přepočte a výpis ho
nese v korunách; kurzový rozdíl proti dokladu v cizí měně jde na 563 nebo 663.
Poplatek za převod měny, který banka strhne zvlášť, je poplatek.

Splátku z vlastního účtu aplikace spáruje jako převod mezi vlastními účty přes
261. Raiffeisenbank přijímá splátky na svůj sběrný účet s variabilním symbolem:
když ho v detailu úvěrového účtu vyplníte, platba z běžného účtu na tento účet
a s tímto VS se zaúčtuje jako převod MD 261 / D 221.x, ne jako výdaj.

Úroky, poplatky, splátky bez protiúčtu, výběry a odměny se účtují automaticky
nebo jako návrh podle nastavení automatiky: úroky a odměny podle typu Bankovní
úroky, poplatky podle Bankovní poplatky, splátky a výběry podle Převody mezi
vlastními účty. Zamítnutý návrh se u pohybu znovu nenabízí.

### 112.3.3 Nastavení účtování

Záložka **Nastavení účtování** určuje účty:

| Pole | Výchozí účet | Povolené účty |
|---|---|---|
| Úroky z úvěru | 562 | 56x |
| Poplatky | 568 | 5xx |
| Splátka bez protiúčtu | 261 | 261, 395 |
| Výběr hotovosti kartou | 261 | 261, 211 |
| Odměna a cashback | 648 | 6xx |
| Protiúčet počátečního dluhu | 379 | třídy 3 a 4 |

Nedaňové účty jsou v nabídce označené podle příznaku v účtové osnově. Změna
platí jen pro nové zápisy, zaúčtované pohyby se nepřeúčtovávají.

### 112.3.4 Daňová evidence

Kreditní karty jsou jen pro firmy v **podvojném účetnictví**. Firma v daňové
evidenci výpis kreditní karty nenačte: úvěrový účet by v peněžním deníku
vystupoval jako peníze, dluh by snižoval zůstatek peněz a splátky by se tvářily
jako příjem. Nákupy kreditkou v daňové evidenci evidujte přes doklady
(výdaj v den úhrady splátkou z běžného účtu).

## 112.4 Oprávnění

Přehled, detail a nastavení vidí uživatelé s přístupem k bance. Načtení výpisu
vyžaduje oprávnění k importu bankovních výpisů. Nastavení účtování,
výběr analytiky 231, zaúčtování čekajících pohybů a počátečního
dluhu a převod účtu na kreditní kartu vyžadují oprávnění k zaúčtování
bankovních pohybů. Údaje úvěrového účtu upravují uživatelé s oprávněním ke
správě bankovních účtů firmy.
