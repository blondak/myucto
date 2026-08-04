# 56a. Úplné mzdy — aktivace, zaměstnavatel a zaměstnanci

**Cesta: `Mzdy`**

Samostatná sekce Mzdy rozšiřuje zaměstnance z existující Mzdové rekapitulace
o personální profil a více pracovních vztahů. Nevytváří druhý seznam lidí a
nemění dosavadní Mzdovou rekapitulaci.

> [!IMPORTANT]
> V novém modulu je dostupná aktivace, nastavení zaměstnavatele a mzdových
> účtáren, osobní karty, pracovní vztahy, vstupy, absence, evidence srážek,
> řízený mzdový běh a archiv výstupů. Zákonné výpočty sociálního a zdravotního
> pojištění, daně a čisté mzdy jsou napojené do neměnné revize, ale vestavěná
> pravidla pro rok 2026 zatím nejsou odborně schválená a aktivní. Systém proto
> výpočet bezpečně zastaví v ruční kontrole. Úplné mzdy, dávková tvorba všech
> dokumentů a elektronická podání zatím nejsou určeny k ostrému použití.
> Pro zaúčtování nadále používej [Mzdovou rekapitulaci](56_Mzdy.md).

## 56a.1 Zapnutí pro firmu

V **Firma → Nastavení** je přepínač **Vést mzdy**. Ve výchozím stavu je zapnutý
a nyní není navázaný na samostatnou licenci. Je-li vypnutý, sekce Mzdy se skryje
z menu a její přímé adresy nejsou dostupné.

Na přehledu mezd zvolíš první měsíc, který má v budoucnu zpracovat nový modul.
Starší měsíce mohou zůstat v Mzdové rekapitulaci. Jeden měsíc však nelze
zpracovat současně oběma cestami.

Rozpracovanou aktivaci lze zrušit, dokud je pouze ve stavu nastavení. Ostrý
začátek se nezruší obyčejným přepínačem, aby nezmizely vazby na uzavřené mzdy,
platby, dokumenty nebo podání.

## 56a.2 Podporovaný rozsah

Přehled mezd ukazuje podporované roky a schopnosti modulu. Stav má tento význam:

- **Podporováno** — funkce má implementované kontroly pro uvedený rozsah;
- **Ruční kontrola** — systém připraví podklady, ale výsledek vyžaduje odborné
  posouzení;
- **Nepodporováno** — funkci nelze použít pro ostrý mzdový běh.

Označení na přehledu je bezpečnostní hranice. Modul nesmí chybějící pravidlo
nahradit nejbližším rokem nebo odhadem.

## 56a.3 Nastavení zaměstnavatele

V **Mzdy → Nastavení zaměstnavatele** se evidují registrační a kontaktní údaje
pro mzdovou agendu. Firma může mít více mzdových účtáren, ale právě jedna
aktivní účtárna musí být označena jako výchozí. Každá účtárna má vlastní kód,
název a vlastní variabilní symbol pro platby sociálního pojištění. Pole
**Registrační číslo zaměstnavatele** slouží pro evidenci a podání; není
variabilním symbolem platby.

V části **Účty zdravotních pojišťoven** se pro každou pojišťovnu eviduje
zaměstnavatelský variabilní symbol společně s účtem, měnou a obdobím platnosti.
Ulož také druh ověřovacího zdroje, jeho referenci a datum ověření. V seznamu se
bankovní účet zobrazuje jen maskovaně. Změnu samotného účtu nebo začátku
platnosti založ jako nový historický záznam; u existujícího záznamu lze bezpečně
upravit název, platební symboly, konec platnosti a údaje o ověření. Období stejné
pojišťovny a měny se nesmějí překrývat.

Osobní variabilní symbol ČSSZ a číslo pojištěnce OSVČ v obecném nastavení firmy
zůstávají určena pro vlastní odvody fyzické osoby. Platby zaměstnavatele je
nepřebírají. U právnické osoby se tato osobní pole v obecném nastavení
nezobrazují; identifikátory zaměstnavatele se ukládají jen v mzdovém nastavení.
Automatické návrhy a rozpoznání bankovních plateb používají aktivní mzdovou
účtárnu a účet pojišťovny platný k datu platby; nejednoznačný nebo historický
údaj zůstane k ručnímu posouzení.

Na stejné stránce se nastavují výchozí účty automatického zaúčtování. Samostatně
se rozlišuje mzda zaměstnance mimo výkon funkce, příjem společníka a odměna za
výkon funkce člena orgánu. Dále se vybírají účty pojistného, daně a ostatních
srážek. Nabídka obsahuje jen aktivní účty vhodného typu z účtového rozvrhu firmy.

Změny se ukládají s kontrolou souběžné editace. Pokud mezitím nastavení změnil
jiný uživatel, aplikace načte aktuální data a vyžádá nové potvrzení změn.

## 56a.4 Společný seznam zaměstnanců

V **Mzdy → Zaměstnanci** se zobrazují stejné karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se proto týká
téže osoby; žádné slučování duplicitních karet není potřeba.

Seznam ukazuje:

- aktivní nebo neaktivní stav osoby;
- zda její rozšířený mzdový profil vyžaduje doplnění;
- počet a druh pracovních vztahů;
- původní vztah převzatý z Mzdové rekapitulace.

Tlačítkem **Zobrazit vztahy** rozbalíš detail. Na telefonu se seznam automaticky
mění z tabulky na karty.

## 56a.5 Pracovní vztah a předkontace

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

## 56a.6 Životní cyklus vztahu

Nový vztah začíná jako **Plánovaný**. Stav se nemění volným přepsáním pole, ale
jen nabízenými akcemi:

`Plánovaný → Předregistrovaný → Aktivní → Přerušený → Skončený → Archivovaný`

Z přerušeného vztahu se lze vrátit do aktivního stavu nebo jej ukončit.
Plánovaný či předregistrovaný vztah lze samostatně označit jako **Nenastoupil**
a potom archivovat. U každé akce zvolíš datum účinnosti. Přeskočení povinného
kroku nebo návrat ze skončeného vztahu aplikace odmítne.

Skončení vztah nemaže. Zůstává dostupný pro pozdější doplatek, opravu, podání a
dohledání tehdy platných údajů. Archivace jej pouze odklidí z aktivního workflow.

## 56a.7 Historie smluvních podmínek a souběhy

Tlačítko **Nová verze podmínek** založí další účinný interval. Předchozí verzi
uzavře dnem před novou účinností; starší mzdové období proto pozdější změna
nepřepíše. Historie drží zejména:

- uzavření smlouvy, plánovaný a skutečný nástup a dobu určitou;
- úvazek, týdenní hodiny, místo práce, pravidelné pracoviště, CZ-ISCO a druh
  činnosti;
- mzdovou účtárnu, pojistnou účast, A1 a cizí předpisy, rizikovou práci,
  daňový režim a prohlášení k dani;
- příznak primárního pracovního vztahu a důvod změny.

Jedna osoba může mít souběžně například HPP a DPP nebo samostatný pracovní poměr
a odměnu za výkon funkce. V aktivním workflow může být právě jeden vztah označen
jako primární. Každý souběh má vlastní kód, stav, historii a budoucí registrační
identitu.

## 56a.8 Checklist a časová osa

Detail ukazuje povinnosti nástupu, změny a skončení. Patří sem smlouva nebo
dohoda, registrace a změny pro zdravotní pojišťovnu a ČSSZ/JMHZ, daňové
prohlášení, výstupní doklady, kontrola exekucí či insolvence a kontrola
pozdějšího doplatku. U každé položky je termín a stav **Nesplněno**,
**Splněno** nebo **Netýká se**.

Časová osa zachovává stavové přechody, změny checklistu i rozdíl každé smluvní
verze. Pokud jiný uživatel mezitím vztah změnil, starší formulář se neuloží a je
nutné načíst aktuální verzi.

## 56a.9 Mzdové složky a vstupy

V **Mzdy → Mzdové složky a vstupy** jsou běžnými záložkami oddělené:

- katalog mzdových složek;
- pravidelné předpisy;
- jednorázové měsíční vstupy;
- CSV/XLSX import s povinným náhledem před uložením.

Výchozí složky používají české kódy bez diakritiky, aby byly bezpečné i pro
CSV a jiné strojové zpracování. Patří mezi ně například `MZDA_MESICNI`,
`MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`, `NEPENEZNI_PRIJEM`,
`PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy používej také ve
sloupci `component_code` importovaného souboru. U nové vlastní složky zadej
nejprve název; kód se z něj automaticky vytvoří bez diakritiky. Dokud jej ručně
neupravíš, sleduje změny názvu. Po uložení už kód ani začátek platnosti změnit
nelze; další účinnost se zakládá jako nová verze.

Každá složka samostatně určuje dopad do daně, sociálního a zdravotního
pojištění, průměrného výdělku, exekučního základu, JMHZ, statistiky a
účetnictví. Schválený vstup si uloží neměnný snapshot této klasifikace; pozdější
změna katalogu proto nepřepíše již zpracované období.

Pravidelný předpis má vlastní interval platnosti a lze jej zadat pevnou částkou
nebo procentem. Jednorázový vstup se nejprve zkontroluje a potom samostatně
schválí. Import odmítá nebezpečné sešity, vzorce a duplicitní řádky a před
zápisem vždy ukáže výsledek náhledu. Soubor můžeš vybrat kliknutím nebo jej
přetáhnout do fialové plochy; stejný ovládací prvek používá také import
docházky. Přijímá CSV a XLSX do 5 MB a chybu zobrazí přímo u souboru.

### 56a.9.1 Rychlý měsíční vstup bez docházky

V **Mzdy → Rychlý měsíční vstup** vybereš měsíc a upravíš všechny účinné
pracovní vztahy na jedné stránce. U každého zaměstnance se zobrazí jméno,
maskované rodné číslo, základní mzda ze vztahu, přesčas a bonus nebo odměna.
Náhled hrubé mzdy se přepočítává okamžitě; další již existující mzdové vstupy
jsou v něm zobrazeny samostatně.

Přesčas lze zadat celkovou částkou. Zadání v hodinách je dostupné pouze tehdy,
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek. Systém
pak použije tento doložený průměr a 25% příplatek; bez schváleného podkladu
hodinovou sazbu neodhaduje a vyžádá celkovou částku.

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY` a `ODMENA`, takže nevzniká paralelní evidence mezd.
Opakované uložení stejného měsíce nevytvoří duplicity. Rozpracované vstupy se
mění s kontrolou jejich verze; schválený nebo uzamčený vstup formulář nikdy
nepřepíše. Pokud základní mzdu už spravuje pravidelný či jiný měsíční vstup,
rychlý formulář ji zobrazí pouze pro čtení.

## 56a.10 Absence, dovolená a DPN

V **Mzdy → Absence a dovolená** jsou tři navazující agendy:

- absence se schvalovacím stavem a kontrolou překryvu;
- čtvrtletní snapshot průměrného nebo pravděpodobného hodinového výdělku;
- hodinový ledger dovolené, ve kterém oprava vytváří novou položku a nemaže historii.

Nejprve vyber pracovní vztah a založ snapshot průměrného výdělku. Skutečný
průměr používá započitatelnou mzdu a odpracované minuty v rozhodném období.
Při méně než 21 odpracovaných dnech je povinný pravděpodobný hodinový výdělek
a jeho odůvodnění. Snapshot musí projít ruční kontrolou a schválením; teprve
potom jej lze připojit k absenci s náhradou.

Nárok dovolené se vede v minutách. U DPP a DPČ výpočet používá zákonnou
fiktivní týdenní pracovní dobu 20 hodin. Započitatelné a náhradní doby,
změny úvazku, krácení a další právní okolnosti před uložením vždy ověř.
Schválení čerpání zapíše zápornou položku podle publikovaných směn. Zrušení
schváleného čerpání ji nemaže, ale vytvoří kladnou reverzi a označí absenci
pro kontrolu případné opravy mzdy.

Náhrada při DPN se počítá pouze z publikovaných směn v prvních 14 kalendářních
dnech. Před schválením potvrď účast na nemocenském pojištění a vyloučení
souběžné dávky. Pokud zaměstnanec první plánovanou směnu celou odpracoval,
označ tuto skutečnost; čtrnáctidenní okno pak začíná následujícím dnem.
Výsledek uchovává použitý průměr, redukční hranice, pravidla, zaokrouhlení
a rozpad po směnách. Diagnóza se v agendě absence neeviduje.

> [!WARNING]
> Agenda je označena **Vyžaduje ruční kontrolu**. Bez schváleného průměru,
> publikovaného rozvrhu nebo potvrzených zákonných podmínek výpočet bezpečně
> selže; systém chybějící údaj neodhaduje. Výpočty náhrad a dovolené jsou
> aktuálně dostupné pouze pro legislativní ruleset roku 2026.

## 56a.11 Mzdové běhy

V **Mzdy → Mzdové běhy** založíš zpracování konkrétního měsíce. K období se
zadává také skutečné datum výplaty; podle něj se vybírají účinná pravidla
srážek. Jeden běh prochází řízenými kroky **Uzamknout vstupy → Vypočítat →
Zkontrolovat → Schválit**. Výpočet a kontrolu musí provést různí uživatelé a
schválení vyžaduje samostatné oprávnění.

Uzamknutí vytvoří neměnný snapshot zaměstnanců, vztahů, složek, data výplaty
a měsíčních podkladů srážek. Pozdější změna živé karty už rozpracovanou revizi
nepřepíše. Oprava schváleného měsíce vytváří novou revizi; původní zůstává
dohledatelná.

Výpočet odděluje hotovost zahrnutou do exekučního základu od částek, které se
nesrážejí, například správně klasifikovaných cestovních náhrad. Vypočtená
srážka sníží částku k výplatě, ale neměnný výsledek a ledger
**sraženo / deponováno** vzniknou až společně se schválením. Neúplné důkazy,
více plátců bez ověřeného rozdělení nebo jiný stav vyžadující posouzení
schválení zablokují.

Schválení zároveň automaticky vytvoří výplatní pásku každé zpracované osoby
a v podvojném účetnictví rozdílový mzdový deník. Použijí se předkontace
zmrazené při uzamknutí vstupů, takže pozdější změna nastavení nezmění již
zkontrolovanou revizi. Je-li účetní období uzamčené, datum deníku se posune na
první otevřený den. Schválení, zákonné kumulace, účetní zápis i pásky tvoří
jeden celek: selže-li některý krok, běh zůstane ve stavu **Zkontrolováno**
a nevznikne částečně schválená mzda.

> [!WARNING]
> Produkční schválení vyžaduje odborně schválený a aktivní legislativní
> ruleset pro příslušné období. Neaktivní nebo neúplný ruleset výpočet označí
> pro ruční kontrolu a schválení zablokuje; aplikace chybějící zákonné údaje
> neodhaduje.

## 56a.12 Srážky, exekuce a oddlužení

V **Mzdy → Srážky a exekuce** založíš zaměstnanci zákonnou srážku, exekuci
nebo dohodu o srážkách. Případ začíná ve stavu **Přijato — čeká na ověření**.
Nejdříve doplň pohledávky, jejich kategorii, den pořadí a potvrď ověření
právního titulu, doručení a přednosti. Aktivace srážení vyžaduje počáteční
rozhodnutí z agendy **Dokumenty**. Do ověření příjemce aplikace částky jen
deponuje. Odesílání musí uživatel povolit samostatnou akcí a doložit
odpovídajícím rozhodnutím. Aplikace ověří firmu i oprávnění uživatele k
dokumentu, uloží jeho otisk a dokument od té chvíle chrání jako právní důkaz.
Také odklad, obnovení deponování či odesílání a zastavení vyžadují vybraný
dokument; u odkladu a zastavení je navíc povinný důvod.

Výpočet používá celé haléře a uchovává neměnný měsíční vstup, použitou verzi
pravidel, mezikroky zaokrouhlení, přidělení částek pohledávkám a pohyby
**sraženo / deponováno**. Budoucí platební krok přidá samostatný pohyb
**odesláno**, aby výpočet nemohl předstírat skutečnou úhradu. Kontroluje zejména nezabavitelnou částku,
třetiny, plně zabavitelný zbytek, pořadí přednostních pohledávek, běžné a dlužné
výživné, více exekučních příkazů, více plátců, oddlužení a paušální náhradu
nákladů zaměstnavatele. Chybějící měsíční podklady nezastoupí odhadem — výsledek
označí pro ruční kontrolu.

Číslo řízení, bankovní účet příjemce ani právní dokument se do polí případu
nepřepisují. Patří do zabezpečených dokumentů; agenda srážek pracuje pouze
s interním identifikátorem a ověřenými skutečnostmi. Odklad a zastavení vyžadují
ověřené rozhodnutí a důvod. Ukončený případ nelze zkratkou znovu otevřít.
Označení případu za uhrazený se zpřístupní až po zavedení skutečné platby
a nulovém zůstatku; na této obrazovce se zatím nenabízí.

## 56a.13 Dokumenty a měsíční balíček

V **Mzdy → Dokumenty a výstupy** vyber období. Seznam zobrazuje dokumenty
uložené ke schválené revizi mzdového běhu, zaměstnance, mzdovou účtárnu,
číslo revize, čas vytvoření a velikost. Na telefonu se tabulka mění na karty.

Záložka **Roční dokumenty** umožňuje zvolit rok a zaměstnance a vytvořit
**mzdový list**. List vzniká pouze z posledních schválených revizí všech
mzdových účtáren v daném roce. Zahrne také více souběžných revizí v jednom
měsíci, například doplatek po skončení vztahu. Pokud chybí schválený výsledek,
historická identifikace nebo jiný povinný podklad, aplikace dokument nevytvoří
a zobrazí konkrétní důvod.

Roční dokument má vlastní neměnnou revizi a není uměle připojený k prosincové
mzdě. Osobní údaje jeho zdrojového snapshotu jsou kontextově šifrované;
manifest obsahuje pouze interní identifikátory a kryptografické otisky.
Pozdější oprava mzdy vytvoří další revizi mzdového listu a původní soubor
zůstane dohledatelný. Roční zúčtování se v mzdovém listu nedopočítává a bez
samostatně schváleného ročního výsledku se označí jako neprovedené.

Stažení nejprve získá krátkodobé jednorázové oprávnění a potom soubor předá
prohlížeči. Původní dokument se při opravě nikdy nepřepisuje. Nový výstup má
vlastní revizi a původní zůstává dohledatelný.

Pro každou poslední schválenou revizi období lze vytvořit měsíční ZIP. Obsahuje
právě ty dokumenty, které už byly k revizi archivovány, a strojově čitelný
manifest s jejich otisky. Doplníš-li později další dokument, vznikne nová
revize balíčku; opakované vytvoření nad stejnou sadou vrátí stejný výsledek.

> [!WARNING]
> Výplatní pásky vznikají automaticky při schválení a roční mzdový list lze
> vytvořit v záložce Roční dokumenty. Daňová potvrzení, výstupní dokumenty
> a podací protokoly se zatím vytvářejí samostatně, takže neúplný balíček
> neznamená, že jsou všechny povinné výstupy hotové.

## 56a.14 Oprávnění a citlivé údaje

Sekci mohou číst pouze interní role s oprávněním `payroll`. Nastavení aktivace
a zaměstnavatele vyžaduje `payroll.settings`. API nového modulu je dostupné
jen z přihlášené webové relace, ne přes běžný bearer token.
Zakládání vztahů, nové verze podmínek, stavové přechody a checklist vyžadují
`payroll.employment.write`; bez něj je detail pouze pro čtení.
Archiv dokumentů vyžaduje `payroll.documents`; bez práva zápisu nelze vytvořit
měsíční balíček.
Agenda srážek má samostatné oprávnění `payroll.enforcement`; právo pro běžné
mzdy ani původní Mzdovou rekapitulaci samo o sobě přístup k těmto údajům
nedává. Změna měsíčního insolvenčního režimu vyžaduje také
`payroll.insolvency` a právní přechod s dokumentem oprávnění `documents`.

Běžný seznam ani detail neposílá rodné číslo, adresu nebo bankovní účet.
Citlivé mzdové identifikátory se ukládají kontextově šifrované pro konkrétní
firmu a osobu; vyhledávací otisk nelze použít ke spojování stejné hodnoty mezi
firmami. Citlivé hodnoty a mzdové částky se redigují z provozních logů.

## 56a.15 Vztah k Mzdové rekapitulaci

Mzdová rekapitulace zůstává součástí základní agendy na adrese
**Účetnictví → Mzdová rekapitulace**. Její formulář, automatické měsíční
zaúčtování a mzdový list fungují dál beze změny a nejsou podmíněné budoucí
licencí úplných mezd.

Nový modul nad společnou kartou pouze doplňuje další údaje. Ochrana období
zabrání tomu, aby stejnou firmu a měsíc uzavřela legacy rekapitulace i nový
mzdový běh.
