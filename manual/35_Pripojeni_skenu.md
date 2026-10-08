# 35. Připojení skenů k dokladům

> Návod, jak hromadu naskenovaných dokladů připojit k dokladům, které už v MyÚčtu
> jsou, a jak zkontrolovat, že údaje dokladu sedí se skenem. Pro účetní po převodu dat
> z jiného programu i pro kanceláře, které dostanou od klienta krabici skenů
> k zaúčtovaným dokladům.

## 35.1 Kdy to potřebujete

Kapitolu otevřete, když:

- jste převedli data z jiného účetního programu, doklady v aplikaci máte, ale jejich
  papírové originály jsou jen jako skeny ve složce,
- vám klient poslal krabici naskenovaných účtenek k už zaúčtovaným dokladům,
- chcete zjistit, kterým dokladům sken chybí,
- chcete ověřit, že datum plnění, částka nebo variabilní symbol na dokladu odpovídají
  příloze.

Nový doklad se tu nezakládá. Přijatou fakturu ze skenu založíte přes [AI extrakci](25_AI_extrakce.md).

## 35.2 Než začnete

- **Oprávnění.** Potřebujete oprávnění nahrávat dokumenty a k tomu oprávnění upravovat
  doklady typu, ke kterému skeny připojujete. Typy, na které nemáte právo, se v nabídce
  neobjeví.
- **AI.** Vytěžení obsahu skenů používá AI poskytovatele nastaveného pro firmu (viz
  [AI extrakce](25_AI_extrakce.md)). Bez AI se páruje jen podle čárového kódu a čísla
  dokladu v názvu souboru.
- **Soubory.** PDF a obrázky (JPG, PNG, WebP, HEIC, TIFF), nebo jeden ZIP se skeny.
  Adresářová struktura v ZIP nevadí, systémové soubory (`__MACOSX`, `Thumbs.db`) se přeskočí.
- **Limity.** Jeden soubor nejvýš 32 MB, dávka nejvýš 20 000 souborů a dohromady 2 GB.
  Firma může mít najednou nejvýš tři rozpracované dávky.

## 35.3 Krok za krokem: nahrát dávku skenů

1. Otevřete `Dokumenty → Skeny k dokladům`.
2. Klikněte na **Nahrát skeny**.
3. Přetáhněte do pole skeny, nebo na něj klikněte a vyberte soubory.
4. V poli **Připojovat k** zvolte typy dokladů: **Přijaté faktury**, **Vydané faktury**,
   **Pokladní doklady**.
5. Volitelně omezte doklady datem vystavení (**Doklady vystavené od** a **Do**). Menší
   rozsah znamená přesnější párování a kratší seznam dokladů bez skenu.
6. Nastavte volby:
   - **Vytěžit obsah skenů pomocí AI** (výchozí). Bez vytěžení se páruje jen podle
     čárového kódu a čísla dokladu v názvu souboru.
   - **Pravděpodobné shody připojit bez potvrzení**. Jinak čekají v záložce **Ke kontrole**.
   - **Číslu dokladu v názvu souboru věřit i bez potvrzení obsahem**. Zapněte jen
     tehdy, když složka obsahuje skeny výhradně této firmy.
7. Klikněte na **Nahrát a zpracovat**.

Soubory se nahrávají po částech, takže velikost dávky neomezuje nastavení serveru.
Zpracování pak běží na serveru a stránku můžete zavřít.

**Jak poznáte, že je hotovo:** dávka má stav **Hotovo** (nebo **Hotovo s upozorněním**)
a stránka ukáže souhrn s počtem připojených skenů a skenů k potvrzení.

> [!TIP]
> Čím užší rozsah dat zvolíte, tím méně falešných shod a tím rychlejší kontrola.
> Pro každé období nebo firmu založte raději samostatnou dávku.

## 35.4 Krok za krokem: zkontrolovat výsledek dávky

1. Otevřete dávku v seznamu **Dávky**. Zobrazí se souhrn a záložky.
2. Na záložce **Ke kontrole** projděte návrhy. U každého uvidíte, co se ze skenu
   vyčetlo, a údaje dokladu.
3. Správný návrh potvrďte tlačítkem **Připojit**. Chybný zahoďte tlačítkem **Odmítnout**.
4. Záložku **Doklady bez skenu** použijte jako seznam toho, co ještě chybí.
5. Záložky **Skeny bez dokladu** a **Nerozpoznané** ukážou skeny, které se nepodařilo
   přiřadit. Zjistěte důvod (viz [§ 35.7](#357-kdyz-neco-nejde)).

<!-- cols: 28 72 -->
| Záložka | Co obsahuje |
|---|---|
| **Připojeno** | Skeny připojené k dokladům, s klíčem, podle kterého se spárovaly |
| **Ke kontrole** | Pravděpodobné shody a kandidáti |
| **Doklady bez skenu** | Doklady ve zvoleném rozsahu, ke kterým není připojená žádná příloha |
| **Skeny bez dokladu** | Skeny, které podle obsahu patří firmě, ale žádný doklad jim neodpovídá. Typicky chybějící zaúčtování nebo špatně přečtený údaj. |
| **Nerozpoznané** | Doklady jiné firmy, skeny, u kterých nejde určit komu patří, a soubory, které se nepodařilo vytěžit |
| **Rozpory** | Doklady, ke kterým dávka připojila sken a jejichž údaje se od skenu liší (viz [§ 35.5](#355-krok-za-krokem-vyresit-rozpory-dokladu-s-prilohami)) |

**Jak poznáte, že je hotovo:** záložka **Ke kontrole** je prázdná (**Nic nečeká na potvrzení.**).
Potvrzením jednoho kandidáta se ostatní návrhy pro stejný doklad i stejný sken
automaticky odmítnou.

## 35.5 Krok za krokem: vyřešit rozpory dokladů s přílohami

Aplikace porovná zaúčtovaný doklad s tím, co AI vyčetla z jeho přílohy. Příloha je
buď připojený sken, nebo PDF, ze kterého doklad vznikl [AI extrakcí](25_AI_extrakce.md).

1. Otevřete `Dokumenty → Skeny k dokladům` a sjeďte pod seznam dávek k přehledu
   **Rozpory dokladů s přílohami**. Filtr přepíná **Otevřené**, **Potvrzené** a **Vše**.
   Rozpory konkrétní dávky vidíte na její záložce **Rozpory**.
2. U dokladu klikněte na **Zkontrolovat**. Otevře se porovnání údajů dokladu a přílohy.
3. Rozpor s dopadem na DPH (**Dopad na DPH**) ověřte proti originálu dokladu. Je-li chyba
   na dokladu, opravte ho.
4. Je-li rozdíl oprávněný (například příloha je dodací list a datum odpovídá smlouvě),
   napište do pole **Proč je doklad v pořádku** důvod a klikněte na **Potvrdit, že je v pořádku**.
   Důvod je povinný (aspoň 3 znaky).
5. Po větší opravě klikněte na **Přepočítat**. Tlačítko znovu projde všechny doklady firmy.

Rozpor vidíte i jinde: v detailu přijaté faktury a u pokladního dokladu (v sekci příloh)
je odznak **Sedí s přílohou**, **Rozpor s přílohou** (dopad na DPH), **Rozdíl proti příloze**
(informativní) nebo **Rozdíl potvrzen**. Kliknutím na něj otevřete porovnání. Rozpory hlásí také
[měsíční kontrola](72_Uzaverka.md#7210-krok-za-krokem-mesicni-kontrola) a předběžné kontroly uzávěrky.

**Jak poznáte, že je hotovo:** filtr **Otevřené** ukáže **Žádné otevřené rozpory.**

> [!WARNING]
> Rozdíl DUZP, při kterém datum na příloze padá do jiného kalendářního měsíce, může
> zařadit doklad do nesprávného období DPH i kontrolního hlášení. Ověřte datum proti
> originálu dokladu dřív, než rozpor potvrdíte.

## 35.6 Krok za krokem: spustit dávku znovu nebo ji smazat

1. Otevřete dávku v seznamu **Dávky**.
2. Po výpadku, zrušení zpracování nebo když přibyly doklady, ke kterým by skeny mohly
   patřit, klikněte na **Spustit znovu**.
3. Rozběhnuté zpracování zastavíte tlačítkem **Zrušit zpracování**. Stav obnovíte tlačítkem **Obnovit**.
4. Dávku, kterou už nepotřebujete, odstraníte tlačítkem **Smazat dávku** a potvrzením.

**Jak poznáte, že je hotovo:** dávka má stav **Hotovo**, případně zmizela ze seznamu.

Smazání odstraní jen záznam o dávce. Soubory v Dokumentech i jejich připojení
k dokladům zůstanou.

## 35.7 Když něco nejde

<!-- cols: 30 35 35 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Nemáte oprávnění připojovat přílohy k žádnému typu dokladů.** | Chybí právo upravovat doklady | Požádejte administrátora o oprávnění |
| Typ dokladů je **nedostupné** | K tomuto typu nemáte právo přikládat přílohy | Požádejte administrátora o oprávnění |
| Soubor je mezi chybami dávky | Překročil limit 32 MB nebo má nepodporovaný formát | Soubor zmenšete nebo převeďte na PDF či obrázek a nahrajte znovu |
| Sken je na záložce **Skeny bez dokladu** | Patří firmě, ale doklad v aplikaci neexistuje nebo je špatně přečtený údaj | Doklad zaúčtujte, nebo ho založte přes AI extrakci, a dávku **Spustit znovu** |
| Sken je na záložce **Nerozpoznané** (**Doklad jiné firmy**, **Nelze určit**, **Nevytěženo**) | Sken má jiného odběratele, nebo AI nic nevyčetla | Zkontrolujte, zda jste ve správné firmě, případně zapněte vytěžení pomocí AI |
| Sken se nepřipojil, i když název souboru začíná číslem dokladu | Na straně firmy je na skenu uvedena jiná firma (složka může obsahovat skeny sesterské firmy se stejnou řadou) | Zkontrolujte záložku **Ke kontrole**, kde se sken nabídne k ručnímu rozhodnutí |
| U dokladu jsou víc stejně dobrých skenů nebo jeden sken sedí na víc dokladů | Aplikace nerozhoduje sama | V záložce **Ke kontrole** vyberte správný pár |
| Pravidelná faktura (například roční poplatek) nedostala sken | Doklad už má sken z dřívější dávky a podle obsahu další nedostane | Přidejte další strany ručně, nebo pojmenujte soubor číslem dokladu |
| Nebylo vytěženo, AI není nastavená | Firma nemá nastaveného AI poskytovatele | Nastavte AI v `Firma → AI nastavení`, nebo spoléhejte na čárový kód a číslo v názvu |
| Faktura v uzavřeném období nedostala PDF | V uzavřeném období se PDF faktury nepřidává | Sken je u ní jen jako propojený dokument v sekci Dokumenty |

## 35.8 Podrobnosti a pravidla

### 35.8.1 Jak aplikace skeny páruje

Každý sken se nejdřív uloží do sekce [Dokumenty](34_Dokumenty.md) (složka **Skeny dokladů
→ Dávka N**) a AI z něj přečte dodavatele, odběratele, číslo dokladu, variabilní symbol,
data, částku, číslo z nálepky s čárovým kódem, SPZ vozidla a poslední čtyři číslice
platební karty.

Páruje se podle tří klíčů, od nejjistějšího:

<!-- cols: 22 58 20 -->
| Klíč | Kdy platí | Jistota |
|---|---|---|
| Čárový kód | Číslo na začátku názvu souboru nebo z nálepky na skenu se shoduje s čárovým kódem dokladu převzatým z předchozího systému | jistá |
| Číslo dokladu v názvu | Název souboru začíná číslem dokladu (`PF20260268 foto1.jpg`) | jistá, když ji potvrdí obsah skenu; jinak kandidát |
| Obsah skenu | Firma je na správné straně dokladu, sedí částka a k tomu číslo dokladu nebo VS, případně protistrana s datem ±5 dní | jistá nebo pravděpodobná |

Firma se hledá na té straně dokladu, kam patří: u přijaté faktury a výdajového
pokladního dokladu jako odběratel, u vydané faktury a příjmového dokladu jako dodavatel.
Když účtenka odběratele neuvádí, pozná se firma podle SPZ vozidla z
[knihy jízd](36_Kniha_jizd.md). Když IČO chybí, rozhoduje jméno odběratele. Sken s cizím
odběratelem se nepřipojí.

Jistota se posuzuje u každého souboru zvlášť. Sken, na kterém je na straně firmy
uvedená jiná firma, se nepřipojí ani tehdy, když název souboru začíná číslem dokladu
(složka může obsahovat skeny sesterské firmy se stejnou číselnou řadou). Když k němu
sedí čárový kód, nabídne se v záložce **Ke kontrole**.

Párování probíhá **ve dvou kolech**. Nejdřív se pro všechny doklady použijí čárové kódy
a čísla dokladů, teprve potom obsah, a to jen u skenů, které zatím nikam nepatří.
Pravidelná faktura téhož dodavatele na stejnou částku (například roční poplatek) si tak
nevezme sken, který podle čárového kódu patří faktuře z jiného roku.

Když na jeden doklad sedí dva stejně dobré skeny, nebo jeden sken na dva doklady,
aplikace nerozhoduje sama a nabídne je jako kandidáty k ručnímu výběru.

### 35.8.2 Kam se sken připojí

- Sken je vždy v sekci Dokumenty s vazbou na doklad, takže je vidět v detailu dokladu
  v panelu propojených dokumentů.
- U přijaté faktury se první sken navíc uloží jako PDF faktury, pokud faktura žádné PDF
  nemá. Obrázek se převede na PDF. Faktura v uzavřeném účetním období PDF nedostane
  (stejně jako při ručním nahrání), sken je u ní jen jako propojený dokument.
- Doklad, který už má sken z dřívější dávky, další sken podle obsahu nedostane. Čárový
  kód a číslo dokladu v názvu souboru připojí další strany dál.
- Stejný soubor se neukládá dvakrát. Když firma sken se stejným obsahem v Dokumentech
  už má, dávka použije ten existující.
- Vytěžené údaje se zapíšou jako text dokumentu. Sken bez textové vrstvy tak najdete
  vyhledáváním v Dokumentech podle dodavatele, čísla dokladu nebo částky.
- Vytěžení se ukládá k obsahu souboru. Stejný sken se znovu nevytěžuje ani v další dávce.
- Účtenka za pohonné hmoty připojená k přijaté faktuře nebo pokladnímu dokladu založí
  nebo doplní tankování v [knize jízd](36_Kniha_jizd.md), pokud firma knihu jízd vede.
  Doklad, který tankování už má, se jen doplní.

### 35.8.3 Co se při kontrole rozporů porovnává

<!-- cols: 20 40 40 -->
| Údaj | Kdy je rozdíl | Význam |
|---|---|---|
| DUZP | DUZP na příloze padá do jiného kalendářního měsíce | **Varování** (**Dopad na DPH**): doklad může být v nesprávném období DPH i kontrolního hlášení. Platí u dokladu, který vstupuje do DPH, a jen když je firma k DUZP plátce. |
| DUZP | jiný den téhož měsíce | informativně |
| Částka | celková částka nebo částka k úhradě se liší | informativně |
| IČO protistrany | u přijatého dokladu IČO dodavatele, u vydaného IČO odběratele | informativně |
| Variabilní symbol | VS na příloze je jiný | informativně |

Aby kontrola nehlásila zbytečné rozdíly, nehodnotí se:

- údaj, který AI z přílohy nevyčetla,
- zaokrouhlení dokladu a zaokrouhlení na celé koruny,
- záloha proti skenu konečného dokladu (a naopak) a u zálohové faktury nikdy DUZP,
- znaménko dobropisu,
- příloha v jiné měně než doklad (částka),
- IČO, když AI firmu na příloze posadila na opačnou stranu, než odpovídá dokladu.

Doklad bez vytěžené přílohy se nekontroluje.

Potvrzení „v pořádku" se zapíše do historie aktivit. Potvrzený rozdíl se v kontrolách
nehlásí, dokud se nezmění doklad ani vytěžení přílohy. Jakmile se některý z porovnávaných
údajů změní, rozdíl se ukáže znovu i s původním důvodem.

Porovnání se obnoví samo po připojení skenu, po AI importu faktury a po uložení přijaté
faktury nebo pokladního dokladu. Tlačítko **Přepočítat** projde všechny doklady firmy
znovu. Měsíční kontrola a odznak porovnávají vždy aktuální stav. Přehled za celou firmu
zahrnuje i doklady z AI importu.

### 35.8.4 Opakované spuštění a uchovávání souborů

**Spustit znovu** uložené soubory a vytěžení znovu nezpracovává. Soubor, který se
napoprvé nepodařilo uložit do Dokumentů, se uloží znovu. Připojené, potvrzené i odmítnuté
páry zůstávají, znovu se počítají jen návrhy.

Nahrané soubory dávky aplikace drží, dokud je všechny neuloží do Dokumentů. Po dokončení
se smažou. Když se některý soubor uložit nepodařilo, zůstanou pro **Spustit znovu** ještě
7 dní. Nahrávání, které nikdo nedokončil, se uklidí po 48 hodinách.

Dávky jedné firmy se zpracovávají postupně: když jedna běží, další počká, až doběhne.
Firma může mít najednou nejvýš tři rozpracované dávky (nahrávané nebo čekající na
zpracování).

### 35.8.5 AI a oprávnění

- Vytěžení používá AI poskytovatele nastaveného pro firmu (viz [AI extrakce](25_AI_extrakce.md)).
  Když AI nastavená není, páruje se jen podle čárového kódu a čísla v názvu souboru.
- K nahrání dávky potřebujete oprávnění nahrávat dokumenty a k tomu oprávnění upravovat
  doklady typu, ke kterému skeny připojujete.
- Dávku vidí ten, kdo ji založil, a administrátor firmy.

## 35.9 Související kapitoly

- [Dokumenty](34_Dokumenty.md) - kam se skeny ukládají a jak je hledat
- [AI extrakce](25_AI_extrakce.md) - založení přijaté faktury ze skenu
- [Kniha jízd](36_Kniha_jizd.md) - tankování vzniklé z účtenek
- [Uzávěrka](72_Uzaverka.md) - měsíční kontrola a rozpory příloh
