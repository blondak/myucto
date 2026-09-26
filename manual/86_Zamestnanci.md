# 86. Zaměstnanci

## 86.1 Účel

Agenda zaměstnanců spojuje osobní kartu s jedním nebo více pracovními vztahy. Oddělení osoby od vztahu zachovává historii a umožňuje správně zpracovat souběhy.

## 86.2 Předpoklady a oprávnění

Uživatel potřebuje právo číst nebo měnit osoby a pracovní vztahy. Připravte pouze údaje potřebné pro mzdu, daň, pojištění, platbu, dokumenty a podání a ověřte je z oprávněného podkladu.

## 86.3 Krokový postup

1. Otevřete **Mzdy → Zaměstnanci** a založte osobu nebo otevřete existující.
2. Vyplňte identifikační, adresní, daňové, pojistné a platební údaje.
3. Založte samostatný vztah pro každý právně odlišný pracovní poměr či dohodu.
4. Doplňte typ vztahu, data, úvazek, odměňování, pojištění a daňový režim.
5. Změny podmínek zapisujte s účinností; nepřepisujte údaje použité v uzavřených obdobích.
6. Při skončení uzavřete poslední období, připravte dokumenty a proveďte podporovaná hlášení; nepodporované registrace dokončete ručně.

## 86.4 Stavy

Osoba může existovat bez aktivního vztahu. Vztah může být budoucí, aktivní nebo skončený. Neúplná data lze evidovat, ale výpočet, dokument či podání může vyžadovat jejich doplnění.

## 86.5 Kontroly a bezpečnost

Ověřte identifikátory, pojišťovnu, účet, data vztahu a souběhy. Osobní a zdravotní údaje zpřístupněte jen podle role. Odkaz na dokument je volitelná stopa k bezpečně uloženému podkladu; nenahrazuje zákonný identifikátor a nesmí obsahovat tajné údaje.

## 86.6 Časté chyby

- Duplicitní osobní karta místo dalšího vztahu.
- Přepsání historické podmínky bez data účinnosti.
- Nesprávné přiřazení vstupu k jednomu ze souběžných vztahů.
- Skončení vztahu bez poslední mzdy, dokumentů nebo ruční oznamovací povinnosti.

## 86.7 Návaznosti

Zaměstnavatele a předkontace nastavuje [kapitola 58o](90_Nastaveni_mezd.md), pravidelné odměňování [58p](91_Mzdove_slozky_a_vstupy.md), běhy [58e](80_Mzdove_behy.md) a životní události vůči institucím [58j](85_Podani_a_hlaseni.md).



## 86.8 Podrobný pracovní postup a kontroly

V **Mzdy → Zaměstnanci** se zobrazují stejné karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se proto týká
téže osoby; žádné slučování duplicitních karet není potřeba.

Primárním tlačítkem **Přidat zaměstnance** založíš právě tuto společnou kartu,
nikoli druhou osobu jen pro úplné mzdy. Formulář se otevře místo seznamu, takže
nemusíš nikam scrollovat ani hledat založenou osobu. Nahoře je jen to, bez čeho
uložení neprojde — jméno, druh vztahu a plánovaný nástup — a nepovinné
**osobní číslo**. Nevyplněné osobní číslo aplikace přidělí sama (`ZAM-…`);
zadat lze písmena, číslice a znaky `.` `_` `/` `-`, nejvýš 64 znaků. Osobní
číslo patří k pracovnímu vztahu, takže osoba se dvěma vztahy má dvě. V seznamech
a na kartách se zobrazuje u jména jako „os. č. …“. Zbytek je hned pod
tím ve sbalitelné části **Další údaje**: rodné číslo, datum narození, základní
mzda, týdenní pracovní doba, mzdová účtárna a zdravotní pojišťovna. Jedno
uložení tak založí kartu, první pracovní vztah i jeho podmínky; nový zaměstnanec
se pak otevře k doplnění zbytku osobního profilu.

Týdenní pracovní doba je součástí zakládacího formuláře. Zadejte skutečný
úvazek hned při založení, aby první interval podmínek odpovídal realitě.
U dohod (DPP, DPČ), společníka a člena statutárního orgánu se stanovená
týdenní doba do měsíčního hlášení neuvádí: podle pokynů MPSV tam patří
hodnota 99 a potvrzení pracovní doby ji předvyplní i vyžaduje.
Automatický nárok dovolené tuto sjednanou dobu převezme; není nutné ji znovu
opisovat v agendě absencí. Firemní výměra dovolené platí všem vztahům. Pole
**Výjimka z výměry dovolené** vyplňte jen tam, kde má konkrétní vztah jiný
nárok; prázdná hodnota znamená převzetí účinné firemní politiky. Změna se
ukládá jako nová účinná verze podmínek, takže starší nároky zůstanou dohledatelné.
Mzdová účtárna se
nabízí firmě s víc než jednou aktivní účtárnou; ostatním ji aplikace dosadí
z výchozí účtárny zaměstnavatele. Zdravotní pojišťovna se předvyplní tou, kterou
má firma v nastavení jako výchozí, a zapíše se do **zákonné evidence osoby**
k datu nástupu — týmž uložením jako zaměstnanec, takže nemůže vzniknout karta
bez ní. Neznámý kód pojišťovny proto celé založení odmítne a nic se neuloží.
Zaměstnance bez českého rodného čísla lze založit bez náhradní hodnoty.
EČP, VČP a zahraniční identifikátor se vedou samostatně a lze je doplnit
přímo v běžné editaci; úplná osobní evidence dál uchovává jejich 1:N historii.
Rodné číslo se v seznamu nezobrazuje. Kde se maskované rodné číslo zobrazuje
jinde, jsou z něj vidět už jen **poslední dvě číslice**: se čtyřmi šlo celé
rodné číslo dopočítat z data narození a pohlaví. Otevřít celou hodnotu lze jen
samostatnou oprávněnou akcí, která se zaznamenává. Tlačítko zůstává viditelné
i uživateli bez práva zápisu, ale je neaktivní a vysvětlí chybějící oprávnění.

Hvězdička u popisku znamená, že bez toho pole uložení neprojde. Při zakládání
jsou takové jen tři: jméno, druh vztahu a plánovaný nástup. Rodné číslo, datum
narození ani mzda povinné nejsou a jdou doplnit kdykoli později — rodné číslo
je potřeba až u přihlášky na ČSSZ (kde stačí i EČP) a u oznámení zdravotní
pojišťovně.

Toolbar nad seznamem umožňuje hledání podle jména nebo osobního čísla (stačí
i jeho část), přepnutí mezi aktivními,
všemi a kartami vyžadujícími doplnění a rychlý přechod na měsíční zadání mezd.
Hledání i stránkování probíhá nad celou firmou na serveru po 25 osobách, takže
se stejným postupem pracujete s deseti i pěti sty zaměstnanci.

Seznam ukazuje:

- aktivní nebo neaktivní stav osoby;
- co je nejbližší konkrétní krok k dokončení karty, například doplnění bydliště,
  identifikátoru nebo pracovního vztahu;
- počet a druh pracovních vztahů;
- původní vztah převzatý z Mzdové rekapitulace.

Akce v řádku odpovídá tomuto kroku, například **Doplnit bydliště**; u hotové
karty se jmenuje **Otevřít kartu**. Detail nejdřív ukáže čtecí souhrn běžných
údajů. Citlivé hodnoty zůstávají maskované a odkryjí se jen samostatnou
oprávněnou akcí. Editor se otevře až tlačítkem **Upravit**, takže pouhá kontrola
karty nezobrazuje desítky vstupních polí. Technická verze záznamu se uživateli
nezobrazuje, ale dál se interně posílá při ukládání a chrání před přepsáním
souběžné změny.

V editoru **Běžné údaje zaměstnance** bez přepínání záložek upravíš jméno
a příjmení, rodné číslo, bydliště, e-mail, telefon, osobní číslo, týdenní
pracovní dobu a pravidelnou hrubou mzdu. Změna samotného osobního čísla
nezakládá novou verzi sjednaných podmínek; smazat ho nejde, jen změnit.
Stát bydliště se vybírá ze společného číselníku
zemí. Pokud je číselník dočasně nedostupný, formulář dovolí ručně zadat
dvoupísmenný ISO kód, aby úpravu adresy nezablokoval výpadek sítě. Změna
jména, bydliště, kontaktu, pracovní doby nebo mzdy nevynuluje historii:
starší záznam uzavře a založí novou účinnou verzi; záznam založený tentýž den
lze ještě opravit na místě. Uzavřená historická adresa se při založení nové
adresy nemění. Pokud je u osoby uloženo rodné příjmení, nová verze jména je
bez jeho odkrytí bezpečně převezme na serveru. Jméno a příjmení se zadávají
samostatně a systém je nikdy neodhaduje z celého zobrazovaného jména. Osobní
profil a primární pracovní vztah se ukládají jednou transakcí, takže při chybě
nezůstane změněná jen jedna část.

Na telefonu se seznam automaticky mění z tabulky na karty. Historii identit,
adres a kontaktů, výplatní účty a další méně časté údaje otevřeš pod formulářem
ve sbalené části **Úplná osobní evidence a historie**. Nejde o nahrazenou nebo
ztracenou evidenci: zůstávají zde vazby 1:N pro historické identity, adresy,
kontakty, výplatní účty a jejich období účinnosti. Také u všech historických
adres se stát vybírá ze stejného číselníku. U citlivých údajů se zobrazuje
pouze maska; novou hodnotu zadej jen tehdy, když ji chceš změnit. Po uložení
aplikace otevřenou hodnotu z formuláře odstraní.

Každá verze jména má sbalenou část **Údaje pro registraci zaměstnance**.
Zadává se v ní titul před a za jménem, datum a místo narození, stát narození,
státní občanství a pohlaví používané registračním formulářem ČSSZ. Běžnou práci
s kartou tato pole nezahltí; rozbal je při nástupu nebo při opravě registrační
identity. Státy vyber ze stejného číselníku zemí jako u adres. Údaje se uloží
do konkrétní historické verze a platí od data **Platí od** uvedeného nad nimi.
Při registraci pracovního vztahu proto aplikace použije verzi účinnou k datu
nástupu, nikoli dnešní nebo poslední zadanou hodnotu. Prázdné nepovinné pole lze
doplnit později; test registrace pak přesně řekne, který údaj ještě chybí.

Výplatní účet musí mít název, období účinnosti a rozdělení výplaty. Před
zařazením do platební dávky jej samostatně ověř tlačítkem **Ověřit účet** a
uveď druh podkladu i datum ověření. Máš-li ve formuláři neuloženou změnu účtu,
ověření je zablokované: nejdříve kartu ulož, aby se nikdy neověřila předchozí
uložená hodnota pod nově zobrazenými údaji. Každá pozdější změna čísla účtu,
účinnosti nebo aktivního stavu ověření automaticky zneplatní.

### 86.8.1 Vyživované osoby a daňové zvýhodnění na dítě

Ve sbalené části **Úplná osobní evidence a historie** je pod osobním profilem
sekce **Vyživované osoby a daňové zvýhodnění**. Eviduje děti, na které se
uplatňuje měsíční daňové zvýhodnění podle § 35c zákona o daních z příjmů,
a manžela nebo partnera, u kterých lze slevu uplatnit až v ročním zúčtování.

U osoby zadáš vztah k poplatníkovi, jméno, datum narození, volitelné rodné
číslo, průkaz ZTP/P, soustavné studium a období, po které je osoba vyživovaná.
Rodné číslo dítěte se ukládá šifrovaně, v seznamu i v detailu se zobrazuje jen
maskované a odkrýt je lze pouze auditovaným odhalením citlivých údajů.

Samotná evidence osoby ještě nezakládá nárok. Ten vzniká až **uplatněním**
s vlastním obdobím účinnosti, kde uvedeš:

- **kdo zvýhodnění uplatňuje** — zaměstnanec, nebo jiná osoba ve společně
  hospodařící domácnosti (dítě s pořadím **N**, viz níže);
- **pořadí dítěte** — pořadí dítěte ve společně hospodařící domácnosti; určuje
  výši zvýhodnění a počítají se do něj i děti, které uplatňuje druhý rodič;
  dvě děti nesmí mít v jednom měsíci stejné pořadí;
- **ZTP/P** — zvýhodnění za dítě s průkazem ZTP/P je dvojnásobné a zaškrtnout
  je lze jen tehdy, je-li ZTP/P vedeno i u samotné osoby;
- **důvod a stav ověření** — podepsané prohlášení poplatníka musí být platné
  k počátku nároku; volitelně lze přidat odkaz do mzdové dokumentace, ale jeho
  vyplnění není podmínkou uložení ani výpočtu;
- **potvrzení společně hospodařící domácnosti a druhého poplatníka** — chybí-li,
  výpočet skončí v ruční kontrole;
- **jiná osoba vyživující tytéž děti v téže domácnosti** — na tuhle otázku se
  ptá měsíční hlášení pro ČSSZ. Dokud u nároku zůstane „zatím nerozhodnuto",
  hlášení se nesestaví; odpovíš-li „ano", doplň jméno, příjmení a datum
  narození druhé osoby, jinak podání odmítne kontrola ČSSZ. Odpověď musí být
  u všech dětí téže domácnosti stejná.

Jméno a příjmení dítěte zadávej i zvlášť, ne jen v jednom poli — měsíční
hlášení pro ČSSZ je vykazuje odděleně a aplikace celé jméno sama nedělí.
Rodné číslo ani datum narození dítěte se do měsíčního hlášení neodesílají.

**Dítě uplatňované jinou osobou (pořadí N).** Pořadí dítěte se určuje za celou
domácnost a v jednom měsíci smí dítě uplatnit jen jeden z rodičů. Uplatňuje-li
zaměstnanec například jen druhé dítě, protože první uplatňuje partner, zapiš
i první dítě a u uplatnění zvol **Jiná osoba v domácnosti (pořadí N)** s jeho
pořadím v domácnosti (1). Takové dítě nezakládá žádnou částku, ale drží pořadí,
takže druhé dítě dostane sazbu druhého dítěte a mzda se nezastaví na mezeře
v pořadí. U dítěte s pořadím N musí být potvrzená společná domácnost a uvedená
osoba, která zvýhodnění uplatňuje (jméno, příjmení, datum narození); tvrzení
„druhý poplatník neuplatňuje" se u něj nevyplňuje. Do měsíčního hlášení pro
ČSSZ jde dítě s kódem N a otázka na jinou vyživující osobu s odpovědí ano,
v ročním zúčtování s kódem N ve všech měsících. Uvádí-li zaměstnanec jen děti
s pořadím N, zvýhodnění na děti neuplatňuje vůbec.

**Měsíce nároku.** Nárok se zadává po celých měsících. Zvýhodnění náleží za
měsíc, na jehož počátku byly splněny podmínky, a podle § 35c odst. 10 zákona
o daních z příjmů už v měsíci, ve kterém se dítě narodilo, bylo osvojeno nebo
převzato do péče nahrazující péči rodičů, anebo ve kterém začalo soustavně
studovat. U osoby proto zadej do pole **Vyživovaná od** den té události
(narození, osvojení, svěření do péče, zahájení studia) a do pole **Vyživovaná
do** den, kdy vyživování skončilo (úmrtí, ukončení studia, 26. narozeniny):

- dítě narozené v průběhu měsíce má nárok už od prvního dne měsíce narození;
- u osvojení, převzetí do péče a zahájení studia v průběhu měsíce zvol stejnou
  událost jako **důvod uplatnění** a nárok začne prvním dnem toho měsíce;
- začne-li vyživování v průběhu měsíce z jiného důvodu (například dítě
  manžela se přistěhuje), nárok začíná až prvním dnem dalšího měsíce;
- měsíc, ve kterém vyživování skončí, patří do nároku celý, nárok tedy končí
  posledním dnem toho měsíce.

Formulář nároku datum začátku i konce podle těchto pravidel předvyplní
a upozorní, když zadané období mimo ně vybočí. Dvojnásobek za dítě s průkazem
ZTP/P výjimku nemá: náleží od měsíce, na jehož počátku byl nárok na průkaz
přiznán. Při přiznání v průběhu měsíce ukonči dosavadní nárok koncem měsíce
a nárok se ZTP/P zadej od dalšího. Stejná pravidla platí pro import měsíčních
hlášení JMHZ a pro převod z předchozího mzdového systému.

Aplikace nedovolí dvě překrývající se uplatnění na totéž dítě u jednoho
poplatníka ani uplatnění mimo období, kdy je osoba vedena jako vyživovaná.
Uplatňuje-li totéž dítě (rozpoznané podle rodného čísla) ve stejném měsíci jiný
zaměstnanec téže firmy, uložení se odmítne. Výjimkou jsou oba rodiče u téže
firmy: jeden dítě uplatňuje a druhý ho uvádí s pořadím N. Odmítne se naopak,
když ho oba uvádějí s pořadím N — zvýhodnění musí jeden z nich uplatňovat.

Sazby zvýhodnění se berou z legislativního rulesetu. Pokud pro dané období
žádná účinná sazba neexistuje, aplikace částku neodhaduje — označí nárok
k ruční kontrole.

Nárok zasahující do měsíce uzavřeného schválenou mzdovou revizí se věcnou
změnou nepřepisuje. Původní záznam se ukončí posledním zmrazeným měsícem
a vznikne nová účinná verze od měsíce následujícího, takže historický výsledek
zůstane nedotčený. Ukončení nároku mimo zmrazené období se provede běžnou
úpravou data „Nárok do".

### 86.8.2 Zákonná evidence osoby

Pod běžnými údaji zaměstnance je sekce **Zákonná evidence osoby**. Vede právní
skutečnosti, ze kterých vychází zákonný výpočet:

- **prohlášení poplatníka k dani** — rozhoduje, zda se uplatní měsíční slevy
  a zvýhodnění, nebo se sráží daň bez nich; podrobně v
  [§ 86.8.4](#8684-prohlaseni-k-dani-ma-jedine-misto);
- **daňová rezidence** — rezident, nerezident (se zemí), nebo neověřeno;
- **příslušnost k sociálnímu pojištění** včetně formuláře A1 u zahraničního
  režimu. Podle ní se počítá pojistné. A1 musí platit po celou dobu zahraniční
  příslušnosti v měsíci; končí-li dřív, mzda dotčené osoby se zastaví, dokud
  nedoplníte platnost nového A1, nebo nezapíšete od dalšího dne českou
  příslušnost. Účast „zahraniční", stát cizích předpisů a platnost A1
  v podmínkách vztahu jí musí odpovídat, jinak výpočet ohlásí rozpor;

- **sleva pro pracujícího poplatníka v důchodu**;
- **příslušnost ke zdravotnímu pojištění** a zdravotní pojišťovna;
- **měsíční evidence zdravotního minima** — kdo za daný měsíc doplácí do
  minimálního vyměřovacího základu;
- **výjimky z minima zdravotního pojištění** (nepovinné);
- **vyměřovací základ u jiného zaměstnavatele** při souběhu zaměstnání
  (nepovinné).

Chybí-li kterýkoli z prvních pěti údajů, mzdový běh zákonný výpočet této osoby
nespočítá a skončí v ručním posouzení. Sekce proto v hlavičce ukazuje počet
chybějících údajů a uvnitř je vyjmenuje pro konkrétní měsíc; datum **Ke kterému
dni** určuje, který měsíc se kontroluje.

Měsíční evidence zdravotního minima je **nepovinná**. Není-li za měsíc zadaná,
platí zákonný výchozí stav podle § 3 odst. 10 zákona č. 592/1992 Sb.: doplatek
do minimálního vyměřovacího základu hradí zaměstnanec. Výjimkou je měsíc se
schválenou překážkou na straně zaměstnavatele se sníženou náhradou (prostoj,
počasí, částečná nezaměstnanost): tehdy doplatek hradí zaměstnavatel a běh to
odvodí z nepřítomnosti sám. Zadává se tedy jen tehdy, když je skutečnost jiná —
doplatek jde k tíži zaměstnavatele z důvodu, který evidence nepřítomností nezná,
v měsíci je vedle překážky zaměstnavatele i neplacená nepřítomnost (běh se pak
zastaví a zeptá), nebo si zaměstnanec
při souběhu zvolil pro doplatek jiného zaměstnavatele. Rozklad pojistného u
schválené mzdy pak ukazuje i to, jestli hodnota vznikla zápisem, nebo odvozením
ze zákona. Volba **neověřeno** dál znamená ruční posouzení.

Minimální vyměřovací základ se krátí sám podle schválených nepřítomností, nic se
k tomu nezadává. O dny nemoci, karantény, ošetřování člena rodiny a dlouhodobého
ošetřovného se minimum poměrně snižuje (§ 3 odst. 9 písm. b) zákona č. 592/1992
Sb.). Za dny peněžité pomoci v mateřství a rodičovské dovolené platí pojistné
stát (§ 7 odst. 1 písm. d) zákona č. 48/1997 Sb.), takže se minimum za ně
nepoužije; trvá-li to celý měsíc, doplatek nevzniká vůbec. Neplacené volno ani
neomluvená absence minimum nesnižují a doplatek za ně hradí zaměstnanec.
Otcovská poporodní péče minimum také nesnižuje: zákon ji mezi důvody snížení
v § 3 odst. 8 a 9 zákona č. 592/1992 Sb. nejmenuje a za jejího příjemce stát
pojistné neplatí.

#### Výjimky z minima zdravotního pojištění

Na některé osoby se minimální vyměřovací základ nevztahuje vůbec (§ 3 odst. 8
zákona č. 592/1992 Sb.). Doplatek do minima za ně nevzniká, a proto je potřeba
výjimku zaevidovat v sekci **Výjimky z minima zdravotního pojištění**:

| Důvod výjimky | Kdy platí |
|---|---|
| Státní pojištěnec | za osobu platí pojistné i stát, např. poživatel důchodu, student, příjemce rodičovského příspěvku, uchazeč o zaměstnání, osoba pečující o závislou osobu |
| Držitel průkazu ZTP nebo ZTP/P | osoba s těžkým tělesným, smyslovým nebo mentálním postižením s průkazem |
| Důchodový věk bez nároku na důchod | dosáhla důchodového věku, ale nesplňuje další podmínky pro přiznání starobního důchodu |
| OSVČ platí zálohy alespoň z minima | vedle zaměstnání je OSVČ a odvádí zálohy aspoň z minimálního vyměřovacího základu pro OSVČ; jen za celý měsíc |
| Jen odměna pěstouna | osoba je pouze příjemcem odměny pěstouna; jen za celý měsíc |
| Nemoc, karanténa nebo ošetřování | jen pro případ, kdy nepřítomnost není vedená v aplikaci |

U každé výjimky se zadává důvod, **Platí od** a případně **Platí do** a nepovinně
odkaz na doklad (rozhodnutí o důchodu, průkaz ZTP/P, potvrzení pojišťovny…)
s poznámkou. Na rozdíl od ostatní zákonné evidence se výjimka zadává **s přesným
dnem**: začne-li nebo skončí-li během měsíce, minimum se poměrně sníží podle
kalendářních dnů (§ 3 odst. 9 písm. c)). Výjimky OSVČ a pěstouna musí trvat
celý měsíc, jinak výpočet ohlásí nález k posouzení. Každý důvod je samostatná
řada, takže například ZTP/P a státní pojištěnec mohou platit současně; překryv
téhož důvodu se odmítne už při uložení. Důvod **Neověřeno** jde uložit jako
rozpracovaný stav, zůstane ale vidět jako chybějící údaj a výpočet osobu pošle
do ručního posouzení.

Některé výjimky výpočet odvodí sám a zadávat je není potřeba:

- **doložená sleva pracujícího důchodce** (§ 7d zákona č. 589/1992 Sb.) náleží
  jen poživateli starobního důchodu, a za poživatele důchodu platí pojistné
  i stát, takže se na něj minimum nevztahuje;
- **doložená sleva na dani pro držitele ZTP/P** dokládá průkaz ZTP/P;
- nemoc, karanténa, ošetřování, mateřská a rodičovská ze schválených
  nepřítomností (viz výše).

Sleva na invaliditu se za výjimku nepovažuje, protože se přiznává i tomu, komu
nárok na invalidní důchod nevznikl. Poživatele invalidního důchodu zadejte jako
státního pojištěnce ručně.

#### Vyměřovací základ u jiného zaměstnavatele

Má-li zaměstnanec souběžně zaměstnání u jiného zaměstnavatele, posuzuje se
minimum z **úhrnu** vyměřovacích základů (§ 3 odst. 10 zákona č. 592/1992 Sb.).
V sekci **Vyměřovací základ u jiného zaměstnavatele** se za daný měsíc zadá
označení zaměstnavatele (písmena bez diakritiky, číslice a `.`, `:`, `/`, `_`,
`-`, např. `zamestnavatel:firma-b`), jeho vyměřovací základ v korunách a od kdy
(případně do kdy) tam zaměstnání trvá. Kdo z obou zaměstnavatelů doplatek
odvádí, určuje volba **Zvolený zaměstnavatel** v měsíční evidenci zdravotního
minima; nabídka obsahuje zaměstnavatele zapsané za týž měsíc, i ty, které
přidáte ve stejné úpravě.

#### Doplatek u jednatele s nízkou odměnou

Minimum platí pro každého zaměstnance, tedy i pro jednatele nebo člena orgánu
s odměnou pod minimální mzdou a bez podepsaného prohlášení. Některé mzdové
programy doplatek u orgánů společnosti nepočítají; MyÚčto ho počítá, protože ho
zákon ukládá. Rozklad pojistného u mzdového běhu u doplatku vysvětlí, proč
vznikl, a tlačítkem **Zadat výjimku z minima** otevře kartu osoby přímo v této
sekci. Pokud se na jednatele výjimka vztahuje (je například poživatelem
důchodu), zaevidujte ji a běh přepočítejte.

Ověřené hodnoty (český nebo zahraniční režim, ověřená pojišťovna, platný A1)
jsou rozhodnutím uživatele. **Odkaz na podklad je všude volitelný**: lze zvolit
typický podklad nebo přes volbu **Jiné** zapsat konkrétní číslo dokladu (písmena,
číslice a znaky `.`, `:`, `/`, `_`, `-`), ale prázdné pole uložení, výpočet ani
podání neblokuje. Aplikace žádný domnělý odkaz sama nevytváří. Za ověření
správnosti právní skutečnosti odpovídá uživatel. Varianta **neověřeno** se dál
ukládá jako důvod ručního posouzení.

Tlačítko **Přidat záznam** předvyplní běžný český případ: daňový rezident ČR,
český sociální i zdravotní režim, formulář A1 se netýká, sleva pracujícího
důchodce se neuplatňuje a zdravotní pojišťovna je ta, u které je osoba dosud
vedená (jinak výchozí pojišťovna zaměstnavatele z nastavení mezd). U běžného
zaměstnance tak není co vyplňovat — stačí zkontrolovat a uložit.

Na co se evidence neptá, to si odvodí: u českého daňového rezidenta je stát vždy
ČR, u českého sociálního režimu je A1 vždy „netýká se". Tato pole se proto
nezobrazují a objeví se až po přepnutí na cizí režim — tehdy si evidence vyžádá
stát ze seznamu; odkaz k režimu zůstává nepovinný. Stát i zdravotní pojišťovna se vždy
vybírají ze seznamu, nepíšou se. Chybí-li něco, co server nepřijme, napíše to
evidence rovnou u záznamu i s tím, co s tím udělat.

Evidence se zadává **po celých měsících** (kromě výjimek z minima zdravotního
pojištění, viz výše) a záznamy jedné řady musí na sebe
navazovat den po dni — čtecí cesta vyhodnocuje evidenci k prvnímu dni měsíce,
takže změna uprostřed měsíce by se buď ztratila, nebo by pro daný měsíc vznikly
dvě současně platné verze. Díra v řadě se odmítne už při uložení; jinak by se
projevila až tím, že mzdový běh za chybějící měsíc spadne do ručního posouzení.

Záznam, který začal před koncem posledního schváleného mzdového období, je
uzavřený: jeho začátek nejde posunout ani ho smazat. Věcná změna se do něj
nezapíše — původní záznam se ukončí posledním uzavřeným dnem a nová právní
skutečnost vznikne jako nový záznam od dalšího měsíce. Doplnit dosud chybějící
záznam do uzavřeného období naopak jde; nic tím nepřepisuje.

Uzamčený řádek proto nemá jen zašedlá pole, ale dvě akce. **Změnit od** s datem
prvního dne následujícího měsíce ukončí platný záznam na hranici zmrazení a
rovnou založí jeho novou verzi, kterou upravíš. **Otevřít mzdu k opravě** je
pro případ, kdy se změna musí projevit už v uzavřeném měsíci: spustí korekční
tok a otevře **všechny** běhy, které tu hranici drží. Otevřít jen jeden by
hranici neposunulo, protože ji určuje nejpozdější z nich. Tlačítko se nabídne
jen tam, kde ho server přijme a kde na to máš oprávnění; do historie běhu se
zapíše důvod „Oprava zákonné evidence osoby". Panel nad historií vždy ukazuje,
do kterého dne je historie uzavřená schválenou mzdou.

Celá sekce se ukládá jedním tlačítkem **Uložit**. Čtení stačí obecné oprávnění
pro mzdy, zápis vyžaduje **Spravovat zaměstnance** (`payroll.person.write`) —
evidence je vedená na osobě, ne na jednotlivém pracovním vztahu.

### 86.8.3 Pobytová a pracovní oprávnění cizinců

Ve sbalené části **Úplná osobní evidence a historie** je samostatná sekce
**Pobytová a pracovní oprávnění**. Každé oprávnění eviduje druh, označení,
stát vydání, počátek účinnosti, konec platnosti a autoritativní podklad ve
firemních Dokumentech. Osobní dokument, dokument jiné firmy nebo dokument
v koši aplikace nepřijme.

Historie se nepřepisuje. Prodloužení založte akcí **Navázat obnovení** u
předchozího oprávnění; aplikace zachová původní podklad a vytvoří nový
neměnný záznam. Jedno oprávnění může mít jen jedno přímé pokračování a
překrývající se záznam bez uvedeného předchůdce se odmítne.

Sekce upozorňuje na oprávnění, jejichž platnost skončila nebo skončí do
30 dnů. Čtenář mezd bez oprávnění k Dokumentům uvidí věcnou historii a
upozornění, nikoli odkaz na podklad. Zápis vyžaduje současně právo
**Spravovat zaměstnance** a právo číst firemní Dokumenty.

### 86.8.4 Prohlášení k dani má jediné místo

Prohlášení poplatníka k dani se nastavuje **výhradně v zákonné evidenci osoby**,
v sekci **Prohlášení poplatníka k dani**. Na kartě pracovního vztahu už není
zaškrtávátko, jen popsaný řádek se stavem a odkazem **Nastavit v zákonné
evidenci**, který cílový panel rovnou otevře.

Proč to stojí za pozornost: dřív šel tentýž údaj měnit i na kartě vztahu, ve
formuláři nové verze smluvních podmínek. Obě místa se rozcházela a mzdový běh na
to padal blokátorem o konfliktu prohlášení. Rozejít se přitom musela: prohlášení
se podepisuje i odvolává kdykoli v průběhu vztahu, kdežto smluvní podmínky jsou
verze smlouvy, kterou kvůli podpisu nikdo neverzuje. Nově se hodnota na kartě
vztahu **odvozuje** z evidence, mzdový snímek i měsíční hlášení berou hodnotu ze
stejného zdroje, a blokátor tím zmizel. Na kartě vztahu tedy vidíš stav, ale
měníš ho jinde.

Stavy jsou čtyři a rozlišuj je:

| Stav | Co znamená |
|---|---|
| Podepsáno | prohlášení platí, měsíční slevy a zvýhodnění se uplatní |
| Nepodepsáno | vědomě zapsané „nepodepsal", daň se sráží bez slev |
| Neověřeno | zapsané, ale nedoložené; osoba jde do ručního posouzení |
| Nezadáno | v evidenci k danému měsíci není žádný záznam |

**Nezadáno není totéž co Nepodepsáno**, i když se počítá stejně opatrně: bez
záznamu se prohlášení bere jako nepodepsané, protože podle § 38k odst. 4 zákona
o daních z příjmů se bez prohlášení měsíční sleva uplatnit nesmí a za nesraženou
zálohu ručí plátce (§ 38s). Dřív karta chybějící evidenci ukazovala jako
„nepodepsáno", takže nebylo poznat, jestli to někdo rozhodl, nebo jen zapomněl.

Evidence se vede **po celých měsících** s platností od a do, řady na sebe musí
navazovat a otevřený smí zůstat vždy jen jeden záznam. Vyhodnocuje se ke dni,
který nastavíš v hlavičce panelu. Bez podepsaného prohlášení se v daném měsíci
neuplatní žádná měsíční sleva ani daňové zvýhodnění na dítě. Dva současně účinné
záznamy panel odmítne jako vzájemný konflikt; jde ale o kontrolu dvou
překrývajících se záznamů u tebe, ne o detekci prohlášení u jiného
zaměstnavatele - do cizí firmy aplikace nevidí, souběh u víc plátců si musíš
ohlídat sám.

## 86.9 Pracovní vztah a předkontace

Jedna osoba může mít více samostatných právních vztahů. Rozlišení je důležité
pro výpočet, podání i účetnictví:

| Druh vztahu | Hrubý náklad | Závazek |
|---|---:|---:|
| pracovní poměr mimo výkon funkce, zaměstnání malého rozsahu, DPP, DPČ | 521 | 331 |
| příjem společníka ze závislé činnosti | 522 | 366 |
| odměna za výkon funkce člena orgánu | 523 | 366 |
| pojistné hrazené zaměstnavatelem | 524 | 336 |

Odměna jednatele za výkon funkce tedy není totéž co pracovní poměr jednatele
mimo výkon funkce ani jiný příjem společníka. Souběh se vede jako více vztahů
jedné osoby.

Převzatý legacy vztah zachovává dosavadní kontaci Mzdové rekapitulace. Před
ostrým použitím úplných mezd zkontroluj, zda právní titul odpovídá skutečnosti;
zejména starší karta „jednatel-společník“ sama nerozliší smlouvu o výkonu funkce
od ostatní závislé činnosti.

## 86.10 Životní cyklus vztahu

Nový vztah začíná jako **Plánovaný**. Stav se nemění volným přepsáním pole, ale
jen nabízenými akcemi:

`Plánovaný → Předregistrovaný → Aktivní → Přerušený → Skončený → Archivovaný`

Z přerušeného vztahu se lze vrátit do aktivního stavu nebo jej ukončit.
Plánovaný či předregistrovaný vztah lze samostatně označit jako **Nenastoupil**
a potom archivovat. U každé akce zvolíš datum účinnosti. Přeskočení povinného
kroku nebo návrat ze skončeného vztahu aplikace odmítne.

Skončení vztah nemaže. Zůstává dostupný pro pozdější doplatek, opravu, podání a
dohledání tehdy platných údajů. Archivace jej pouze odklidí z aktivního workflow.

**Odložený příjem po skončení.** Doplatek zúčtovaný v měsíci po skončení
pracovního poměru (typicky odměna) potvrďte na kartě skončeného vztahu v části
**Odložený příjem po skončení vztahu**: zvolte měsíc zúčtování a druh
*Příjem po skončení zaměstnání (1)*. Mzdový běh pak příjem přijme, pojistné
vypočte za měsíc zúčtování a měsíční hlášení JMHZ ho vykáže samostatným
formulářem Odložený příjem s ELDP za tento měsíc (0 dnů, kód s „P“ na druhé
pozici). Záloha na daň zůstává zálohou a hlášení uvádí podepsané prohlášení,
měsíční slevu na poplatníka ani daňové zvýhodnění na děti ale za měsíc, kdy už
u vás nepracuje, aplikace neodečte: za kalendářní měsíc je smí poskytnout jen
jeden plátce a poplatník je mezitím mohl uplatnit u nového zaměstnavatele.
Nárok si uplatní v ročním zúčtování nebo v přiznání. Bez potvrzení běh příjem
po skončení odmítne a z kontroly vás pošle přímo sem. Ostatní druhy odloženého příjmu (například doplatek za dřívější
měsíce trvajícího vztahu) a odložený příjem z dohod podejte opravným hlášením
na ePortálu ČSSZ.

Oznamovací povinnosti vůči zdravotní pojišťovně se odvozují od **skutečného**
nástupu, je-li vyplněný; teprve když není, použije se plánovaný. Vztah označený
jako **Nenastoupil** ani archivovaný vztah už žádnou oznamovací povinnost
nevytváří.

> [!WARNING]
> Vztah proto vždy nejdřív **ukončete** a teprve potom případně archivujte.
> Archivací neukončeného vztahu by z přehledu zmizela odhláška ze zdravotního
> pojištění, kterou je stále nutné podat.

## 86.11 Historie smluvních podmínek a souběhy

Tlačítko **Nová verze podmínek** založí další účinný interval. Předchozí verzi
uzavře dnem před novou účinností; starší mzdové období proto pozdější změna
nepřepíše. Historie drží zejména:

- uzavření smlouvy, plánovaný a skutečný nástup a dobu určitou;
- úvazek, týdenní hodiny, místo práce, pravidelné pracoviště, CZ-ISCO a druh
  činnosti;
- mzdovou účtárnu, pojistnou účast, A1 a cizí předpisy, rizikovou práci
  a daňový režim;
- příznak primárního pracovního vztahu a důvod změny.

Formulář nové verze podmínek je rozdělený na dvě části. Nahoře je jen to, co se
běžně mění: účinnost, plánovaný nástup, **mzdová účtárna**, týdenní hodiny,
úvazek, data smlouvy, příznak primárního vztahu a nepovinný
důvod změny. Zbytek — evidence pro JMHZ, režimy sociálního a zdravotního
pojištění, daňový režim, cizí předpisy, sazbová kategorie § 5a a sleva § 7a —
je ve sbalené části **Další údaje**; ta se sama otevře jen u vztahu, kde už je
něco z ní vyplněné.

Hvězdička u popisku označuje pole, bez kterého uložení neprojde. Důvod změny
mezi ně nepatří — vyplň ho, jen když chceš, aby v časové ose bylo vidět proč.

Mzdovou účtárnu vybíráš přímo na kartě vztahu. Je to jediné místo, kde jde
změnit: z účtárny vychází variabilní symbol zaměstnavatele pro odvod sociálního
pojistného a mzdový běh se dá na účtárnu zúžit, takže vztah bez ní nemá čím
vykázat odvod. Novému vztahu ji aplikace dosadí z výchozí účtárny
zaměstnavatele; firmě s víc účtárnami nabídne výběr už při zakládání vztahu.
Vztah, který účtárnu nemá (typicky převzatá data), na to na kartě upozorní —
uložení to ale nikde neblokuje, blokátorem se to stane až při uzamčení vstupů
mzdového běhu. V nabídce se objeví jen aktivní účtárny; deaktivovaná účtárna,
kterou vztah drží, v ní zůstává, aby ji úprava podmínek tiše nezměnila.

Jestli se příjem zaměstnance, který nepodepsal prohlášení k dani, zdaní zálohou,
nebo srážkovou daní, určuje aplikace sama podle § 6 odst. 4 zákona o daních
z příjmů. Na kartě vztahu se na to nic nevyplňuje:

- **Dohoda o provedení práce** — srážková daň 15 %, když úhrn odměn z dohod
  u vás za měsíc nedosáhne rozhodné částky pro DPP (pro rok 2026 je to
  12 000 Kč). Srazí se bez ohledu na další příjmy, které od vás zaměstnanec
  v tom měsíci má.
- **Každý jiný vztah** (pracovní poměr, zaměstnání malého rozsahu, DPČ, odměna
  člena statutárního orgánu, práce společníka) — srážková daň 15 %, když úhrn
  všech těchto příjmů od vás za měsíc nedosáhne rozhodné částky pro účast na
  nemocenském pojištění (pro rok 2026 je to 4 500 Kč). Rozhoduje skutečně
  zúčtovaný příjem v měsíci, ne sjednaná mzda ani to, jestli vztah zakládá
  účast na pojištění. Souběžné vztahy se sčítají; příjem z DPP se do úhrnu
  přičte jen tehdy, když sám srážkou nešel.
- Příjem **přesně na rozhodné částce** se už daní zálohou.
- S **podepsaným prohlášením** se daní vždy zálohou.

Základ srážkové daně i daň se zaokrouhlují na celé koruny dolů. Sražená daň je
konečná; do ročního zúčtování nevstupuje.

Ve stejné verzi podmínek je skupina **JMHZ – vykonávaná pozice**. Eviduje
strukturovanou obec pracoviště, kód obce a stát, druh činnosti, bližší určení
pracovněprávního vztahu, příspěvek od úřadu práce (aktivní politika
zaměstnanosti) a jeho nástroj, funkční požitky podle § 6 odst. 10 zákona
o daních z příjmů a dočasné přidělení k jinému zaměstnavateli. Druh činnosti
i bližší určení se vybírají z připnutých číselníků
JMHZ. U druhů 1 až 9 je bližší určení povinným podkladem pro výběr scénáře;
chybějící hodnota se nikdy nevykládá jako „Žádné“.

Na příspěvek od úřadu práce, funkční požitky a dočasné přidělení se aplikace ptá
už při zakládání zaměstnance i pracovního vztahu, celou větou a s předvybraným
**Ne** — u drtivé většiny firem je odpověď třikrát ne. Otázky jsou ve sbalitelné
sekci a povinné nejsou. Na kartě vztahu mají tři stavy **Nevyplněno**, **Ne**
a **Ano**. Nevyplněno měsíční hlášení pro ČSSZ **nezastaví**: vyloží si ho jako
„ne“. Uložená hodnota se přitom nepřepisuje — v evidenci zůstane nevyplněná, aby
bylo zpětně poznat, že ji nikdo výslovně nepotvrdil, a zmrazený snímek podání si
u ní poznamená, že vznikla výkladem výchozího stavu, ne prohlášením. Ostatní
údaje skupiny se nadále nedomýšlejí: obec, její kód a stát se ukládají jen jako
úplná trojice. Dokud nejsou
údaje vybrané z autoritativních číselníků CISOB a CZEM, aplikace kontroluje
shodu názvu obce s kódem i platnost státu. Obec se vybírá našeptávačem; stát z
připnuté nabídky. Podmínky před začátkem účinnosti připnutých číselníků nelze
takto označit jako ověřené. Budoucí personální změnu lze naplánovat podle
posledního připnutého snapshotu, ale mzdový snapshot ji pro JMHZ označí jako
neověřenou, pokud vykazované období přesahuje jeho ověřené pokrytí. Taková data
nesmějí projít budoucí readiness bránou ani se odeslat bez novějšího snapshotu.
Při dočasném přidělení **Ano** (agentura práce) se karta zeptá na uživatele,
ke kterému je zaměstnanec přidělen: buď **česká firma nebo podnikatel** s IČO
(osm číslic s platnou kontrolní číslicí), nebo **zahraniční osoba** se státem,
osmimístným registračním číslem a názvem. Hlášení uživatele vykazuje u každého
měsíce přidělení; bez něj ho příprava hlášení zastaví s odkazem zpět na kartu
vztahu. Přidělení k nepodnikající fyzické osobě (identifikace rodným číslem)
aplikace nevykazuje, takové hlášení podejte přes ePortál ČSSZ.

Sazbová kategorie § 5a odst. 1 ve skupině výjimečných situací se promítá i do
měsíčního hlášení jako riziková práce. U **rizikového zaměstnání** hlášení
vykáže kategorizaci rizika 1 (práce kategorie 4) a hodiny rizikové práce rovné
odpracovaným hodinám vztahu. U kategorie **zdravotnický záchranář nebo hasič
podniku** vyberte pole **Kategorizace rizika pro JMHZ**: práce zdravotnického
záchranáře, nebo práce člena jednotky HZS podniku. Dokud volba chybí, příprava
hlášení se zastaví a odkáže na toto pole.

Jedna osoba může mít souběžně například HPP a DPP nebo samostatný pracovní poměr
a odměnu za výkon funkce. V aktivním workflow může být právě jeden vztah označen
jako primární. Každý souběh má vlastní kód, stav, historii a budoucí registrační
identitu. Když je v měsíci účastných na sociálním pojištění víc vztahů téže
osoby, počítá se pojistné zaměstnance i sleva pracujícího důchodce u každého
vztahu zvlášť a zaokrouhluje se po vztazích. Tak je vykazuje i měsíční hlášení
na formuláři každého vztahu a pojistné za zaměstnance v přehledu je jejich
součet. Rozklad pojistného na kartě osoby ukáže výpočet každého vztahu.

### 86.11.1 OIČ / IK MPSV a ID PPV pro JMHZ

Po přidělení identifikátorů ČSSZ otevřete na kartě konkrétního pracovního vztahu
sbalenou sekci **Identifikátory JMHZ od ČSSZ**. Sekce se načte až po otevření,
takže ani firma se stovkami zaměstnanců neposílá zbytečně stovky dotazů.

Rozlišujte, komu údaj patří:

- **OIČ / IK MPSV** identifikuje osobu a použije se u jejích pracovních vztahů;
- **ID PPV** identifikuje právě jeden pracovní vztah. Souběžný HPP a DPP téže
  osoby proto mají stejný osobní identifikátor, ale každý vlastní ID PPV.

Identifikátory pro **Testovací prostředí** a **Produkci** jsou oddělené. Před
uložením vždy zkontrolujte zvolené prostředí, datum platnosti a oba údaje podle
protokolu ČSSZ. Přepnutí prostředí rozepsané hodnoty zahodí, aby se testovací
identifikátor omylem neuložil do produkce. Odkaz na zdroj je nepovinná interní
poznámka; uložení neblokuje. Povinné je pouze výslovné potvrzení, že jste údaje
ověřili v podkladu ČSSZ.

Po uložení aplikace zobrazuje jen masky a otevřené hodnoty už do formuláře
nevrací. Již platný identifikátor nelze tiše přepsat jinou hodnotou. Pokud ČSSZ
identifikátor opravila nebo změnila, nejprve ověřte datum účinnosti a navazující
protokol; nesprávnou hodnotu neobcházejte založením druhé osobní karty.

Pro zobrazení stačí právo číst mzdy. Uložení mění údaj osoby i pracovního
vztahu, a proto vyžaduje současně oprávnění spravovat zaměstnance i pracovní
vztahy. Nejde o pravidlo čtyř očí: uživatel s oběma oprávněními provede celý
krok sám.

## 86.12 Checklist a časová osa

Detail ukazuje povinnosti nástupu, změny a skončení. Patří sem smlouva nebo
dohoda, registrace a změny pro zdravotní pojišťovnu a ČSSZ/JMHZ, daňové
prohlášení, výstupní doklady, kontrola exekucí či insolvence a kontrola
pozdějšího doplatku. U každé položky je termín a stav **Nesplněno**,
**Splněno** nebo **Netýká se**.

Ve výstupní části jsou navíc **Evidenční list důchodového pojištění (ELDP)**
a **Potvrzení o zdanitelných příjmech**. Položka, která na konkrétní vztah
nedopadá, se vůbec nezaloží — ELDP se u vztahů skončených **od 1. 4. 2026**
nezakládá, protože jej podle pravidel JMHZ sestavuje ČSSZ z měsíčního hlášení.
Potvrzení o zdanitelných příjmech se vydává na žádost zaměstnance do 10 dnů
od jejího podání (§ 38j odst. 3 zákona o daních z příjmů). U nesplněné položky
je proto pole **Den žádosti zaměstnance**; po stisku **Zapsat žádost** dostane
položka termín (den žádosti + 10 dnů) a hlídá ji panel **Zákonné termíny**.
Dokud den žádosti není zapsaný, položka termín nemá. Povinnost se uzavře sama,
jakmile se v aplikaci vytvoří potvrzení o zdanitelných příjmech; potvrzení
vydané před zapsanou žádostí ji neuzavře.

Přihlášky a odhlášky se odškrtnou samy až podle **stavu podání**: splněné jsou,
když podání odešlo (nebo bylo přijato) v ostrém prostředí. Připravené, ale
neodeslané hlášení ani podání do testovacího prostředí položku nesplní — lhůta
dál běží a přehled termínů ji dál připomíná. Odhláška z ČSSZ se odškrtne
odesláním odhlášky REGZEC A2 (u nenastoupení A8), odhláška u zdravotní
pojišťovny odesláním oznámení o ukončení.

Termíny u položek checklistu se neodvozují ode dne události, ale z pravidel,
která už aplikace používá jinde, takže se s nimi nemohou rozejít. U několika
lhůt aplikace přiznává, že je nemá doložené z ověřeného zdroje — u prohlášení
poplatníka (§ 38k odst. 4 ZDP), pracovní smlouvy ke dni nástupu (§ 34 odst. 2
zákoníku práce) a zápočtového listu ke dni skončení (§ 313 odst. 1 zákoníku
práce, jehož rozsah novela pro rok 2025 zúžila). U těchto položek si termín
ověřte podle platného znění předpisu.

Vyřídil-li položky za víc lidí jiný systém (typicky po převzetí mezd), není
nutné je odškrtávat na každé kartě zvlášť. V panelu **Zákonné termíny** na
přehledu mezd je lze označit jako splněné hromadně, s povinnou poznámkou
(viz kapitola 73.2.2).

### 86.12.1 Upozornění na chybějící přihlášku zaměstnance

Není-li zaměstnanec přihlášen na ČSSZ nebo u zdravotní pojišťovny, mzdový běh
to ohlásí jako **varování**, ne jako blokaci — mzda za odpracovanou práci
náleží bez ohledu na to, jestli přihláška odešla. Varování je nutné vzít na
vědomí, aby šel běh schválit.

Aby nevznikal planý poplach, ozve se jen tehdy, když platí všechno naráz:
příslušná položka nástupního checklistu je nesplněná, k vztahu není evidovaná
žádná odpovídající povinnost podání, vztah není označen jako **Nenastoupil**
ani archivovaný, nástup už nastal a vztah zakládá účast na pojištění. Dohoda
s automatickým posouzením účasti, vztah bez účasti i cizinec s formulářem A1
tedy mlčí. Podali-li jste přihlášku mimo aplikaci, odškrtněte položku
checklistu — tím varování umlčíte. Týká-li se to víc lidí, označte je hromadně
v panelu **Zákonné termíny** na přehledu mezd; u nástupu před 1. 7. 2026 je
položka v sekci **Bez termínu** (viz kapitola 73.2.2).

Časová osa zachovává stavové přechody, změny checklistu i rozdíl každé smluvní
verze. Pokud jiný uživatel mezitím vztah změnil, starší formulář se neuloží a je
nutné načíst aktuální verzi.

### 86.12.2 Navazující agendy

Karta vztahu má sekci **Navazující agendy**. Vede z ní jedno kliknutí do každé
agendy, kde se k tomuto člověku dá něco pořídit — docházka a směny,
nepřítomnosti, mzdové vstupy, pracovní cesty, opakované složky, průměrný
výdělek, dohody o srážkách, exekuce, dokumenty a roční zúčtování. Cílová
obrazovka se otevře už zúžená na daného zaměstnance; zúžení je vidět v horní
liště a jedním tlačítkem se ruší. Zužuje server, ne jen zobrazená stránka —
hledaný člověk se najde, i kdyby jeho záznamy ležely až na několikáté straně,
a stránkování i počty mluví o zúženém seznamu. Když zúžení nedá žádný záznam
(cizí nebo zaniklý vztah, zestaralý odkaz), řekne to lišta větou; prázdná
tabulka bez vysvětlení se nezobrazí.

Pod tlačítky je souhrn: u agend, ve kterých něco je, počet záznamů, datum
posledního a případně částka. Agendy, ve kterých zatím nic není, se jmenují
jednou nenápadnou větou pod souhrnem. Agenda, na kterou uživatel nemá
oprávnění, se nenabízí ani nezapočítává.

## 86.13 Skončení vztahu

Po ukončení vztahu (akce **Ukončit** s datem skončení) se na kartě vztahu objeví
sekce **Skončení vztahu**. Je to jediné místo, kde se zadává, jak a proč vztah
skončil. Odsud se:

- předvyplní odhláška **REGZEC A2** (důvod ukončení pro Úřad práce, čistý
  průměrný výdělek, odstupné) tlačítkem **Předvyplnit ze skončení vztahu**,
- převezme způsob skončení do **potvrzení zaměstnavatele pro Úřad práce**
  (§ 313 odst. 2 zákoníku práce) — ve formuláři potvrzení je volba zamčená,
- založí proplacení nevyčerpané dovolené a odstupné jako vstupy posledního běhu.

Schválení odhlášky A2 i vydání potvrzení odmítne údaj, který záznamu odporuje.
Dokud záznam neexistuje, obě místa se vyplňují ručně jako dřív.

### 86.13.1 Způsob a důvod skončení

Vyberte **Způsob skončení** (výpověď zaměstnavatele, dohoda, výpověď
zaměstnance, okamžité zrušení, zkušební doba, uplynutí doby určité, úmrtí, …)
a u výpovědi zaměstnavatele, dohody a okamžitého zrušení i **Zákonný důvod**.
Nabídka důvodů odpovídá zvolenému způsobu. U dohody vyberte důvod, o který se
opírá: dohoda z organizačních nebo zdravotních důvodů zakládá odstupné stejně
jako výpověď a v odhlášce A2 jde jako důvod 4 nebo 5, protože jen u nich ČSSZ
přijme údaj o odstupném. Dohoda bez důvodu jde jako důvod 2.

Pod formulářem se po uložení ukáže, jaký kód důvodu uvede odhláška A2 a jaký
způsob skončení tiskne potvrzení pro Úřad práce.

### 86.13.2 Nevyčerpaná a přečerpaná dovolená

Sekce ukazuje zůstatek knihy dovolené za rok skončení a náhradu ve výši
průměrného výdělku (§ 222 odst. 2 a 3 zákoníku práce). Průměr je schválený
průměr za čtvrtletí, do kterého spadá den skončení.

- **Proplatit nevyčerpanou dovolenou** založí schválený vstup složky
  **Náhrada mzdy za dovolenou** za měsíc skončení a do knihy dovolené zapíše
  položku proplacení, takže zůstatek klesne na nulu. V měsíčním hlášení JMHZ
  se náhrada objeví v náhradách za dovolenou.
- **Srazit přečerpanou dovolenou** založí záporný vstup téže složky (§ 147
  odst. 1 písm. e) zákoníku práce). Zkontrolujte, že výplata po srážce
  neklesne pod nezabavitelnou částku; zbytek je nutné vymáhat jinak. Po úmrtí
  zaměstnance se přečerpaná dovolená nesráží (§ 328 odst. 2).
- **Vzít vyrovnání dovolené zpět** (v nabídce „…") založí opravný vstup ve
  stejném měsíci a položku proplacení v knize stornuje.

Proplácí se jen zůstatek roku skončení. Zbyla-li nevyčerpaná dovolená
z předchozího roku a nebyla převedena, sekce na to upozorní; převeďte ji v knize
dovolené. Chybí-li schválený průměr nebo nárok za rok skončení, sekce řekne co
chybí a nabídne proklik do **Absence a průměry**.

### 86.13.3 Odstupné

U výpovědi nebo dohody z organizačních důvodů navrhne sekce odstupné podle
§ 67 odst. 1 zákoníku práce: jednonásobek průměrného měsíčního výdělku při
trvání pracovního poměru kratším než rok, dvojnásobek při trvání od roku do
dvou let a trojnásobek od dvou let. Do trvání se započte předchozí pracovní
poměr u téhož zaměstnavatele, skončil-li nejvýše šest měsíců před vznikem
nového (§ 67 odst. 2). Zaškrtnutím konta pracovní doby podle § 86 odst. 4 se
odstupné zvýší o trojnásobek. U dosažení nejvyšší přípustné expozice (§ 52
písm. e)) je odstupné dvanáctinásobek.

Vyšší násobek podle kolektivní smlouvy nebo vnitřního předpisu zadejte do pole
**Násobek odstupného podle kolektivní smlouvy** i s tím, o co se opírá. Nižší
než zákonný násobek aplikace nepřijme.

**Založit odstupné do posledního běhu** založí schválený vstup složky
**Odstupné** za měsíc skončení. V měsíčním hlášení JMHZ je odstupné jen
v zúčtovaném příjmu celkem a v základu daně; do mzdy za práci ani do náhrad
mzdy nepatří (pokyny MPSV k vyplnění hlášení), takže složka zařazení do rozpadu
mzdy nepotřebuje. V **Mzdové složky** má u JMHZ štítek **Jen do úhrnu příjmu**.

U výpovědi nebo dohody pro dlouhodobou zdravotní nezpůsobilost z pracovního
úrazu nebo nemoci z povolání náleží jednorázová náhrada dvanáctinásobku
průměrného měsíčního výdělku podle § 271ca zákoníku práce. Sekce ji spočítá,
ale jako vstup ji nezakládá — založte ji ručně na složce, jejíž zařazení
určíte, a v odhlášce A2 ji uveďte jako jednorázovou náhradu.

### 86.13.4 Úmrtí zaměstnance

Je-li způsobem skončení **Úmrtí zaměstnance**, sekce vede osoby blízké podle
§ 328 zákoníku práce. Mzdová práva do výše trojnásobku průměrného měsíčního
výdělku přecházejí postupně na manžela nebo partnera, děti a rodiče, žili-li
se zaměstnancem v době smrti ve společné domácnosti. Zapište jméno, vztah,
společnou domácnost a účet pro výplatu; sekce označí, kdo nárok nabývá
(první skupina v pořadí, uvnitř skupiny rovným dílem) a jaký je jeho podíl na
limitu. Zbytek nároků, a všechny, není-li oprávněná osoba, je předmětem
dědictví.

Zdanění výplaty pozůstalým aplikace sama neurčuje — zákon o daních z příjmů
výplatu nároků přešlých podle § 328 zákoníku práce výslovně neupravuje. Než
výplatu provedete, zapište do pole **Daňové posouzení výplaty pozůstalým**, jak
ji zdaníte a z jakého podkladu, a potvrďte ho.

Běží-li u zaměstnance exekuce, insolvence nebo dohody o srážkách, sekce na ně
upozorní s proklikem. Ukončete je v příslušné agendě; aplikace je sama
nezastavuje. Odhláška A2 se u úmrtí předvyplní s příznakem skončení úmrtím
a bez podkladů pro Úřad práce.
