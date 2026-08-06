# 9. Klientský portál

Klientský portál je samostatná domovská obrazovka pro roli **client** — účet, který
účetní firma zřídí svému klientovi (podnikateli, majiteli firmy), aby mohl bez
zprostředkování účetní pracovat s vlastními doklady a zároveň měl po ruce rychlý
přehled hospodaření. Portál **není** oddělená aplikace ani zjednodušené UI — klient
se přihlašuje do stejného MyÚčto.cz, jen s výrazně užší nabídkou menu a s doklady,
které se po zaúčtování uzamknou proti dalším úpravám.

> [!NOTE]
> Role **client** je jiná kategorie než **readonly** (viz [§ 72.2.2 Role](72_Nastaveni.md#7222-role-a-opravneni)).
> Readonly je interní pracovník firmy, který smí jen číst a exportovat cokoliv.
> Client je naopak externí osoba (majitel firmy, na kterou účetní vede agendu),
> která **smí vystavovat a upravovat vlastní doklady**, ale nevidí nic z účetnictví,
> banky, reportů ani nastavení systému a nemůže sáhnout na doklad, který už účetní
> zaúčtovala.

## 9.1 Kdo roli client dostane a jak

Uživatele s klientskou rolí zakládá výhradně **superadmin** v **Systém →
Uživatelé** (stejný formulář jako u ostatních rolí, viz [§ 72.2 Uživatelé](72_Nastaveni.md#722-uzivatele)):

1. V poli **Role** se zvolí aktivní role typu **client**.
2. V sekci přiřazení firem se našeptávačem přidají firmy, ke kterým má klient
   mít přístup — typicky jedna, u víceoborových klientů i více. U každé firmy
   lze ponechat výchozí klientskou roli nebo vybrat jinou aktivní roli typu
   **client**; interní roli typu **staff** backend odmítne.
3. Bez uloženého přiřazení k žádné firmě se klient po přihlášení dostane na portál,
   ale uvidí jen prázdný stav s výzvou kontaktovat účetní (viz [§ 9.3.6](#936-prazdny-stav-bez-firmy)) —
   přístup je **fail-closed**: žádná firma v systému mu není vidět, dokud ji superadmin
   explicitně nepřiřadí.
4. U klienta s víc firmami funguje běžný přepínač dodavatele ve spodní liště —
   portál i všechny stránky se přepnou na aktuálně zvolenou firmu.

> [!TIP]
> Heslo, 2FA a **Můj profil** fungují pro klienta stejně jako pro ostatní role
> (viz [§ 72.3](72_Nastaveni.md#723-muj-profil)) — klient si po prvním přihlášení
> může nastavit vlastní 2FA, pokud to instalace vyžaduje.

## 9.2 Menu klienta — jen zlomek aplikace

Po přihlášení nabídne menu klientovi jen pět sekcí: **Přehled**
(portál, domovská stránka), **Prodej** (vydané faktury + pravidelná fakturace),
**Nákup** (přijaté faktury), **Kontakty** (klienti/dodavatelé) a **Dokumenty**
s položkou **Chybějící doklady** (viz [§ 9.8](#98-vyzadane-doklady-od-klienta)).
Na desktopu jsou sekce v horní liště s popup položkami, na mobilu v nabídce
**☰**. Nápovědu otevírá kontextová ikona **?** v horní liště.
Cokoliv jiného v aplikaci existuje — účetnictví, banka, sklad, e-shop, reporty,
Grafy, kniha jízd, DMS dokumenty, nastavení, administrace uživatelů — klient v menu
vůbec nevidí a při pokusu dostat se tam přímo přes adresu URL ho systém přesměruje
zpátky na portál. Toto omezení je vynucené na dvou místech zároveň (frontend
i API), takže ho nejde obejít ani ruční úpravou adresy v prohlížeči.

Uvnitř povolených sekcí ale klient **není v režimu jen pro čtení** — smí zakládat
i upravovat vlastní doklady stejně jako účetní, pokud doklad ještě nebyl zaúčtovaný
(viz [§ 9.5](#95-zamek-zauctovanych-dokladu)):

- **Vydané faktury** — založení, editace, vystavení, odeslání e-mailem, přijaté
  platby, přílohy, storno, klonování, výkaz práce k faktuře.
- **Přijaté faktury** — založení, nahrání PDF/ISDOC, editace položek, přechod
  mezi stavy `přijatá ⇄ zaplacená`.
- **Pravidelná fakturace** — správa šablon (šablony samy o sobě nejsou účetní
  doklad, takže se nikdy nezamykají).
- **Kontakty** — zákazníci i dodavatelé, včetně vyhledání firmy přes ARES/VIES.

Naopak klientovi zůstávají nedostupné i uvnitř těchto sekcí citlivější podsekce —
například **platební příkazy** (bankovní ABO/KPC soubory a ověřování účtů), **sken
e-mailové schránky účetní**, **zakázky** ani **DMS dokumenty** k přijatým fakturám.
Sklad a e-shop, daňová evidence, účetní deník a bankovní výpisy jsou pro klienta
zavřené úplně.

## 9.3 Co portál (Přehled) zobrazuje

Domovská stránka klienta (`/portal`) je agregovaný přehled hospodaření aktuálně
zvolené firmy — čistě souhrnná čísla, **žádná jména konkrétních zákazníků/dodavatelů
ani čísla dokladů** se v přehledu neobjevují (to je záměrné bezpečnostní omezení,
platí i pro náhled účetní/admina). V záhlaví je název firmy, rozsah období
(od 1. 1. do dneška) a poznámka „Orientační přehled hospodaření — není účetní
závěrka."

### 9.3.1 KPI dlaždice

Čtveřice/pětice karet ukazuje **fakturováno / náklady / rozdíl** za pět období —
**tento měsíc**, **minulý měsíc**, **letos (YTD)**, **loni do dneška** a
**posledních 12 měsíců**. Pokud firma pracuje ve víc měnách, každá karta zobrazí
řádek za každou měnu zvlášť (částky se nesčítají napříč měnami).

### 9.3.2 Měsíční graf

Sloupcový graf fakturace vs. nákladů za posledních 12 měsíců. Pokud firma používá
víc měn, nad grafem je přepínač měny (výchozí CZK, jinak první dostupná).

### 9.3.3 Cashflow — pohledávky, závazky, výhled

Tři karty vedle sebe:

- **Pohledávky** — neuhrazené vydané faktury rozdělené do bucketů „nesplatné",
  „po splatnosti 1–30", „31–60", „61–90" a „90+ dní", s počtem dokladů a součtem
  za měnu.
- **Závazky** — totéž pro nezaplacené přijaté faktury.
- **Výhled 4 týdnů** — týdenní čistý cashflow (očekávané příjmy minus výdaje) na
  4 týdny dopředu + součet za celé období, červeně/zeleně podle znaménka.

### 9.3.4 DPH a daňové termíny

U plátců DPH karta **DPH** ukáže aktuální období, daň na výstupu, daň na vstupu,
výslednou daňovou povinnost (nebo nadměrný odpočet) a termín podání. U neplátců
se místo toho zobrazí informace „Firma není plátce DPH." Vedle karta **Daňové
termíny** vypisuje blížící se termíny v okně 35 dní dopředu (barevně podle
závažnosti) — souvisí s [§ 35 Výkazy DPH](36_Vykazy_DPH.md).

### 9.3.5 Pruh „Účetní čeká na doklady"

Pokud má klient otevřené (nevyřízené) [vyžádání dokladů](#98-vyzadane-doklady-od-klienta),
zobrazí se hned pod záhlavím výrazný pruh s počtem a odkazem na stránku **Chybějící
doklady** — po termínu je pruh červený, jinak žlutý. Pruh zmizí, jakmile klient
všechny otevřené požadavky vyřídí (nahraje doklad nebo je účetní uzavře jinak).

### 9.3.6 Prázdný stav bez firmy

Klient bez přiřazené firmy (viz [§ 9.1](#91-kdo-roli-client-dostane-a-jak))
uvidí místo přehledu jednoduchou zprávu, že jeho účet zatím není propojen se
žádnou firmou, a výzvu kontaktovat účetní. Nová firma bez jakýchkoliv dokladů
zobrazí analogický „zatím tu nejsou žádná data" stav.

## 9.4 Rychlé akce

Pod přehledem jsou (jen pokud má přihlášený uživatel právo zápisu) tři dlaždice
pro rychlý skok mimo portál: **Vystavit fakturu**, **Nahrát přijatou fakturu** a
**Přidat kontakt** — vedou přímo na formulář nové faktury / přijaté faktury /
klienta. Samotná stránka portálu žádný formulář neobsahuje — je to čistě
přehledová obrazovka, zakládání a editace dokladů se vždy odehrává na
příslušných stránkách [Faktury](14_Faktury.md), [Přijaté faktury](23_Prijate_faktury.md)
a [Klienti](18_Klienti.md).

## 9.5 Zámek zaúčtovaných dokladů

Jádrem bezpečnosti role client je jednotný **zámek zaúčtovaných dokladů** — jakmile
účetní doklad zaúčtuje (nebo pro něj vznikne aktivní zápis v účetním deníku, nebo
spadá do uzavřeného účetního období), stává se pro klienta **needitovatelným**.
Doklad zamyká kterákoli z těchto podmínek:

- doklad má vyplněné **datum zaúčtování** (u vydaných faktur ho nastaví buď
  zaúčtování do deníku, nebo účetní ručně; u přijatých fakturu odpovídá stavu
  **Zaúčtovaná**),
- existuje aktivní zápis v účetním deníku vázaný na tento doklad,
- firma vede podvojné účetnictví a datum dokladu spadá do **uzavřeného** nebo
  **schváleného** účetního období,
- probíhá závěrka období (**closing**) — to zamyká **jen klienta**, účetní musí
  v této fázi s doklady dál pracovat.

Zamčený doklad zobrazuje badge **„Zaúčtováno"** s vysvětlením „Doklad je
zaúčtovaný — změny a storno vyřídí vaše účetní." (u uzavřeného období obdobný
text). Akce jako úprava, storno, smazání, přidání/zrušení platby nebo napojení
zálohy z menu detailu prostě zmizí — nejsou jen zašedlé, ale skryté, protože
konečné rozhodnutí stejně vynucuje server. Naopak zůstávají dostupné akce, které
doklad **nemění** (zobrazení, PDF, odeslání e-mailem, přidání přílohy) nebo
vytvářejí **nový** doklad (klonování, daňový doklad k platbě).

U přijatých faktur má klient navíc speciální pravidlo: přechod do stavu
**Zaplaceno** nebo zpět je povolený i bez zaúčtování (tenhle přechod sám o sobě
doklad nezamyká — přeznačení „zaplaceno" není účetní úkon), ale přechod do stavu
**Zaúčtováno** klient nikdy neudělá — to je vždy vyhrazené účetní/adminovi.

> [!WARNING]
> Zámek je čistě serverová záležitost — frontend badge je jen informativní.
> I kdyby se klient pokusil odeslat požadavek na úpravu zamčeného dokladu mimo
> běžné UI, API ho odmítne. Naopak jakmile účetní zaúčtování stornuje (zápis
> zruší), doklad se klientovi znovu odemkne.

Pro účetní a admina funguje zámek jinak — otevřené období smí upravovat vždy,
u uzavřeného období dostanou informativní chybu místo tichého zamítnutí a admin
si může úpravu vynutit (s automatickým záznamem do historie akcí). Detaily
vynucené editace řeší [§ 55 Bezpečnost — RBAC](75_Bezpecnost.md).

## 9.6 Náhled portálu pro účetní a admina

Portál není určený jen klientovi — v menu **Grafy → Přehled firmy** ho najde i admin
a účetní, u aktuálně zvolené firmy vidí přesně to samé, co by viděl klient.
Slouží to jako rychlá kontrola „co vidí klient" i jako samostatný přehled
hospodaření bez nutnosti procházet jednotlivé reporty. Náhled je čistě informativní
a nijak neomezuje, co účetní/admin může jinde v aplikaci dělat — nemá vlastní
zámek ani jiná omezení navíc.

## 9.7 Omezení a tipy

- Portál agreguje data **živě** při každém načtení stránky — nejde o statický
  export ani cache, čísla jsou vždy aktuální k okamžiku otevření.
- Přepínání firmy v horní liště (u víceoborového klienta) portál i všechny
  povolené stránky okamžitě přenačte na nově zvolenou firmu.
- Klient nemá přístup k **osobním přístupovým tokenům (API)**, ani kdyby uměl
  najít odkaz na stránku — endpoint mu API vrátí zamítnuto.
- Klient může mít u konkrétní firmy jinou klientskou roli; přepis musí být
  aktivní a stejného typu **client** jako jeho výchozí role.

> [!TIP]
> Než klientovi předáte přístup, zkontrolujte v **Grafy → Přehled firmy** na jeho
> firmě, jestli přehled dává smysl (zaúčtované doklady, aktuální DPH stav) —
> ušetří to zbytečné dotazy „proč mi tohle nejde upravit", protože klient uvidí
> přesně to, co vy v náhledu.

## 9.8 Vyžádané doklady od klienta

Nejčastější zdržení měsíční uzávěrky je čekání na doklady od klienta — dřív se
urgovalo e-mailem mimo systém a nikdo neviděl, co už dorazilo. **Vyžádané
doklady** řeší tenhle koloběh přímo v aplikaci: účetní založí požadavek, klient
ho vidí v portálu a doklad rovnou nahraje.

### 9.8.1 Účetní strana — Dokumenty → Chybějící doklady

Stránka **Dokumenty → Chybějící doklady** (`/document-requests`) nabízí:

- **Nový požadavek** — formulář s popisem (co chybí), volitelnou částkou,
  kontextovým datem a termínem.
- **Vyžádat doklad jedním klikem z nespárované platby** — v detailu bankovního
  výpisu (**Banka → detail výpisu**) má každá nespárovaná transakce v řádkových
  akcích tlačítko **Vyžádat doklad**; popis se předvyplní z částky a data platby
  (např. „Chybí doklad k platbě 4 520 Kč z 12. 6.") a požadavek se rovnou naváže
  na danou transakci.
- **Filtr stavu** — Vyžádáno / Nahráno — čeká na kontrolu / Vyřízeno.
- **Proklik na doklad** — jakmile klient nahraje soubor, sloupec **Doklad** vede
  přímo na vzniklou přijatou fakturu.
- **Uzavřít** — potvrdí, že je požadavek vyřízený (i bez uploadu, např. když
  doklad dorazil jinou cestou) — přechod na stav Vyřízeno. **Znovu otevřít**
  vrátí omylem uzavřený požadavek zpátky na Vyžádáno.

### 9.8.2 Klientská strana — nahrání dokladu

Klient vidí otevřené požadavky na stránce **Chybějící doklady** v portálu
(`/portal/document-requests`) — u každého popis, kontextovou částku/datum a
termín (po termínu červeně). Tlačítko **Nahrát doklad** otevře výběr souboru
(PDF nebo foto/scan) — po nahrání systém doklad automaticky zpracuje stejnou AI
extrakcí jako import přijatých faktur účetní (vytěží dodavatele, položky, DPH),
založí koncept přijaté faktury a požadavek přepne na stav **Nahráno — čeká na
kontrolu**. Vyřízené požadavky zůstávají na stránce v sekci **Vyřízené** jako
historie.

> [!NOTE]
> Nahrání dokladu jen **navrhne** — koncept přijaté faktury pak zkontroluje
> a dokončí účetní stejně jako u ostatních AI importů (viz
> [§ 25 AI extrakce přijatých faktur](25_AI_extrakce.md)). Požadavek se do stavu
> **Vyřízeno** nepřepne automaticky — o tom rozhoduje účetní po kontrole.

### 9.8.3 Notifikace na obou stranách

Dashboard účetní i domovská stránka portálu klienta zobrazí počet otevřených
požadavků jako barevnou dlaždici/pruh (červeně, pokud je aspoň jeden po
termínu) — proklik vede rovnou na příslušnou stránku. Pokud klient na požadavek
nereaguje, denní úloha (`cron-document-request-reminders.php`) po výchozích 3
dnech pošle e-mailovou urgenci (šablona **E-mail šablony → Chybí doklad**,
upravitelná stejně jako ostatní šablony) a opakuje ji nejdřív po 7 dnech
(cooldown) — obojí lze při spuštění úlohy přenastavit parametry `--days`
a `--cooldown`.

### 9.8.4 Tenant izolace

Klient vidí vždy jen požadavky **vlastní aktuálně zvolené firmy** — stejný
fail-closed princip jako zbytek portálu (viz [§ 9.1](#91-kdo-roli-client-dostane-a-jak)).
Cizí požadavek (jiné firmy) vrátí 404, ne 403, aby se neprozrazovala ani jeho
existence.
