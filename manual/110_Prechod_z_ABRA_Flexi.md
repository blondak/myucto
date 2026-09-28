# 110. Přechod z ABRA Flexi

**Cesta: `Systém → Přechod z jiných účetních systémů → ABRA Flexi`**

Ve **Systém → Přechod z jiných účetních systémů** (`/imports`) vyberte ABRA Flexi
a cílovou firmu. Stránka vyžaduje oprávnění pro zápis importů.

Zadejte HTTPS adresu zdrojové firmy v ABRA Flexi, uživatelské jméno a heslo
uživatele s právem číst její data. Tlačítko **Ověřit a uložit připojení** ověří
přístup a uloží připojení pro vybranou firmu. Přístupové údaje se po odeslání
vymažou z formuláře a uložené hodnoty se znovu nezobrazují.

Před prvním převodem vyberte účetní roky. Předvolené jsou aktuální a předchozí
rok, pokud jsou ve zdroji dostupné. Seznam lze obnovit tlačítkem **Načíst
dostupné roky**. Poté spusťte převod.
U účetního období bez roku v kódu se pro výběr používá rok jeho počátku;
to platí i pro období, které končí v následujícím kalendářním roce.

Účetní import čte také časově platné nastavení OSS ve zdrojové firmě. Pokud je
pro převáděný rok aktivní režim EU, zapne jej u cílové firmy. Nastavení se znovu
ověří při každém načtení nových dat. Ostatní režimy OSS vyžadují ruční kontrolu.
Upozornění na více účetních období se zobrazuje jen pro skutečně převáděné roky.

Faktury zaúčtované v ABRA Flexi se převedou jako vystavené nebo zaúčtované
doklady a propojí se s převzatým deníkem. Totéž platí pro prodejky a závazky,
které ABRA vede mimo evidence vydaných a přijatých faktur. Import jejich účetní
zápisy nevytváří podruhé. Zahraniční DPH zůstává v částce faktury, ale nevstupuje
do české daně v přiznání. Doklad s rozporným nebo neznámým členěním DPH,
například s tuzemským nárokem na odpočet a nulovou daní, zůstane k ověření jako
koncept; důvod se zobrazí v protokolu převodu.
Číslo vydané faktury se přebírá z čísla dokladu v ABRA Flexi, včetně lomítek;
variabilní symbol platby se ukládá zvlášť. Pokud je zdrojové číslo příliš dlouhé
nebo koliduje s jiným dokladem, protokol vyžádá kontrolu před podáním
kontrolního hlášení.

Převod čte také aktuální podklady DPH ve vybraných obdobích. U jednoznačně
spárovaných položek z nich převezme vypočtenou daň v Kč odděleně od částky
faktury, včetně nulového samovyměření. U opravných dokladů zachová zdrojové
daňové znaménko a stornované doklady vyřadí z evidence DPH. Nejednoznačné
podklady nepřepisuje automaticky a uvede upozornění ke kontrole.

Převod běží na pozadí. Stránka ukazuje průběh a počty vytvořených,
přeskočených a chybných záznamů. Tlačítkem pro zrušení požádáte o zastavení;
nedokončený převod se vrátí zpět. Výsledek a upozornění zkontrolujte
i po dokončení. Při potížích s načítáním průběhu použijte **Obnovit stav**.

Po prvním převodu se výběr let skryje a tlačítko **Načíst nová data** spustí
další načtení ve vybraných letech. Připojení lze smazat pouze před převodem
jakýchkoli dat. Změna cílové firmy vymaže rozpracovaný formulář a načte stav
připojení této firmy.

Úhrada dokladu z jiného účetního roku se převede až při načtení roku bankovního
nebo pokladního pohybu. Stornované pohyby se nepřebírají. Pokud ABRA stornuje
již převedený pohyb, synchronizace jej označí ke kontrole a nepřepíše původní
záznam bez zásahu účetní.

Pokud není zapnuté Changes API, nové vazby úhrad se načítají podle jejich ID.
Změny nebo smazání starších vazeb nelze tímto způsobem spolehlivě zjistit;
v takovém případě je nutná ruční kontrola úhrad.

Když ABRA změní počáteční stavy vybraného roku, synchronizace ověří původní
zápisy a dorovná rozdíly novými účetními zápisy. Původní zápisy zůstanou
dohledatelné. Bankovní pohyby bez odpovídajícího zápisu ve zdrojovém deníku
zůstanou označené k ruční kontrole a nevstoupí do automatického účtování.
Po úspěšné kontrole účetnictví se aktivace firmy označí jako dokončená.

Převod čte data postupně po větších dávkách. Požadavky všech převodů na stejný
server se započítávají do místního rozpočtu 1 000 požadavků za den. Tento rozpočet
nezahrnuje ostatní aplikace připojené k Flexi a není údajem o zbývající licenční
kvótě. Správce může po ověření sjednaného limitu nastavit proměnnou prostředí
`MYINVOICE_ABRA_DAILY_REQUEST_LIMIT` pro rozsáhlejší převod (1 až 50 000
požadavků za den); výchozí hodnota zůstává 1 000. Limit se sdílí mezi převody
na stejném serveru. Při omezení požadavků serverem se úloha zastaví a další
spuštění musí počkat.
Dokončené bloky se ukládají odděleně pro firmu a verzi připojení. Při opakování
převodu aplikace znovu ověří jejich identity a časy změny a plné záznamy načte
jen tam, kde uložený blok chybí nebo se změnil. Zrušení nebo chyba nevytvoří
částečně převedené účetnictví; dokončené čtené bloky mohou posloužit dalšímu běhu.

Převzaté faktury s jednoznačným tuzemským členěním DPH se uloží jako vystavené
nebo zaúčtované podle zdroje. Převod přitom nespouští automatické účtování ani
skladový výdej; původní zápisy se přenášejí samostatně v deníku a v detailu
faktury na ně vede odkaz. Doklady s nevyjasněným daňovým členěním zůstávají
koncepty ke kontrole a nevstupují do evidence DPH.
Nulové řádky zdrojového deníku nemají účetní obrat a převod je vynechá; jejich
počet uvede v protokolu. Účetní pohyby se kontrolují proti původním součtům.
Změny již převzatých záznamů se automaticky nepřepisují a vyžadují kontrolu.
Úhrada se propojí pouze při shodě měny vazby, dokladu a peněžního pohybu.
Pokladní pohyb rozdělený jen částečně na doklad zůstane ke kontrole, aby se
celá částka chybně nevydávala za úhradu. Stornovaný cílový doklad synchronizace
znovu neoznačí jako uhrazený.
Pokud je uhrazený doklad z předchozího roku převzat kvůli letošní platbě,
zůstane zachován jeho celkový uhrazený stav ze zdroje. Platby z vybraného roku
se připojí samostatně a nezapočítají se podruhé.
Pokladní doklad se zdrojovou DPH převod zastaví, protože jeho daňový rozpad
zatím nelze bezpečně zachovat.
Úprava počátečních stavů při synchronizaci se neprovede v uzavřeném účetním
období ani v období s uzamčeným datem.
Pokud zdrojové pohyby patří do více pokladen, převod se zastaví před jejich
sloučením do jedné cílové pokladny.
U cizoměnových kontací banky a pokladny se vedle korunové částky uchovává také
původní měna, částka a kurz pro pozdější kurzovou uzávěrku. Nejednoznačný převod
mezi dvěma peněžními účty vyžaduje ruční kontrolu. Pokud cizoměnový počáteční
stav nemá samostatný korunový protějšek, korunová předvaha zůstává zachovaná,
ale tento cizoměnový stav se do kurzové uzávěrky automaticky nedoplní.

Zdrojové bankovní účty se evidují také v záložce **Banka → Kontace**. Analytiky
221 se přebírají ze zdroje a původní zápisy deníku na nich zůstávají. Účty bez
použitelného bankovního čísla, například virtuální platební účty, mají vlastní
analytiku a vazbu na účet v měnách, ale nelze je vydávat za český bankovní účet.
Pokud zdroj používá jinou syntetiku než 221, průvodce upozorní na nutnost
kontroly historického zaúčtování. Rekonstruovaný soubor GPC lze stáhnout jen
u výpisu s ověřenými stavy a platným číslem účtu i kódem banky. Kód banky se
čte také ze zdrojového pole `smerKod`. U virtuálních účtů a neúplné bankovní
identifikace se GPC nenabízí. Hodnoty přesahující pevnou šířku polí GPC nelze
zkrátit bez ztráty informace; jejich export se odmítne.

Blok **Rozsah převodu** ukazuje účetní část a samostatný doplněk pro produkty a
aktuální stav skladu. Po dokončení účetního převodu lze načíst produkty,
jejich prodejní ceny v CZK, EUR, GBP a USD a kladné skladové zůstatky včetně ocenění. Nulové zůstatky
nevytvářejí příjemky. Záporné množství nebo ocenění se nepřevede a vyžaduje
kontrolu. Opakované načtení již převzaté skladové karty neduplikuje; změny
zdrojového stavu se automaticky nepřepisují. Historické skladové pohyby se
zatím nepřevádějí.
Přepočet cizoměnových cen bez DPH se řídí typem ceny v ceníku ABRA Flexi,
nikoli měnou. Pokud typ ceny chybí, cizoměnová cena zůstane ke kontrole.
Před vytvořením skladových příjemek se ověří, že zdroj obsahuje jediný sklad;
více skladů nelze bezpečně sloučit do jednoho cílového skladu.
Převod před každou příjemkou porovná cílový stav s již převedenými kartami.
Pokud sklad obsahuje další zásobu, příjemku nevytvoří, aby stav nezdvojnásobil.
