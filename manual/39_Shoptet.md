# 39. Shoptet

> Návod, jak napojit e-shop na platformě Shoptet na MyÚčto bez jeho API: načítat
> objednávky, případně doklady, a posílat do Shoptetu stav zásob a ceny. Pro
> provozovatele e-shopu a účetní, kteří z Shoptetu přebírají tržby.

Stránka `Sklad → Shoptet` využívá jen exporty a importy souborů, které Shoptet
nabízí na všech tarifech:

- **import objednávek** ze souboru nebo z trvalého odkazu na export objednávek,
- **import dokladů** (faktury, dobropisy, doklady k přijaté platbě, zálohové
  faktury), pokud doklady vystavuje Shoptet,
- **feed zásob a cen**, který si Shoptet sám stahuje a podle kterého
  aktualizuje sklad.

## 39.1 Kdy to potřebujete

- Spouštíte e-shop na Shoptetu a chcete jeho objednávky vidět v MyÚčtu jako
  prodejní objednávky a fakturovat je odtud.
- Doklady vystavuje Shoptet a vy je potřebujete převzít do účetnictví s původním
  číslem.
- Chcete, aby se skladové zásoby a ceny z MyÚčta samy promítly do Shoptetu.
- Po importu vám objednávka naskočila ke kontrole DPH a nevíte, co s ní.

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Rozhodnout, kdo vystavuje doklady, a nastavit export objednávek | `Sklad → Shoptet`, záložka **Nastavení**, postup v [§ 39.3](#393-krok-za-krokem-prvni-nastaveni) |
| jednou při zavádění | Vygenerovat odkaz na feed a zapnout ho v Shoptetu | záložka **Export pro Shoptet**, postup v [§ 39.7](#397-krok-za-krokem-feed-zasob-a-cen-pro-shoptet) |
| průběžně | Načíst nové objednávky (ručně, nebo to dělá automatické stahování) | záložka **Objednávky**, postup v [§ 39.4](#394-krok-za-krokem-import-objednavek) |
| po každém importu | Projít objednávky ke kontrole DPH a nespárované položky | záložka **Objednávky**, sekce **Objednávky ke kontrole** |
| jen když doklady vystavuje Shoptet | Importovat doklady | záložka **Doklady**, postup v [§ 39.6](#396-krok-za-krokem-import-dokladu-ze-shoptetu) |

## 39.2 Než začnete

1. **Zapnutý skladový modul.** Položka **Shoptet** je v menu `Sklad`, takže
   potřebujete zapnutý sklad (viz [Sklad](37_Sklad.md)).
2. **Oprávnění.** Stránku vidí uživatel s přístupem k e-shopu. Zakládání objednávek
   navíc vyžaduje oprávnění k prodejním objednávkám a import dokladů oprávnění
   vystavovat faktury.
3. **Skladové karty s kódem.** Kód karty v MyÚčtu musí být stejný jako kód
   produktu nebo varianty v Shoptetu. Podle něj se párují objednávky i feed
   (viz [Skladové karty](37_Sklad.md#371225-vazba-na-e-shopovou-kartu)).
4. **Přístup do administrace Shoptetu**, kde nastavíte export a automatický import.
5. **Aktivní měny.** Měna objednávky musí být ve firmě aktivní, jinak se objednávka
   neuloží.

## 39.3 Krok za krokem: první nastavení

### 39.3.1 Zvolte, kdo vystavuje doklady

V jednom e-shopu smí daňové doklady vystavovat právě jeden systém, jinak se tatáž
tržba zaeviduje dvakrát.

1. Otevřete `Sklad → Shoptet` a záložku **Nastavení**.
2. V sekci **Kdo vystavuje daňové doklady** zvolte **MyÚčto (doporučeno)**, nebo
   **Shoptet**.
3. Zvolíte-li MyÚčto, vypněte v Shoptetu automatické vystavování dokladů.
4. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** nastavení se uloží a na záložce **Doklady** se
při volbě MyÚčto zobrazí hláška **Doklady vystavuje MyÚčto** s odkazem
**Otevřít nastavení**.

### 39.3.2 Nastavte export objednávek v Shoptetu

1. V administraci Shoptetu otevřete **Objednávky → Export**.
2. Vytvořte vlastní šablonu ve formátu XML, nebo zkopírujte systémovou šablonu
   **Shoptet - XML**. Pro CSV zapněte export jednotlivých položek objednávky
   a řádek s hlavičkou.
3. Zkontrolujte, že šablona obsahuje:
   - kód objednávky, datum, měnu a kurz a e-mail,
   - fakturační a doručovací adresu s kódem země, IČO a DIČ,
   - celkovou cenu,
   - položky s typem, kódem, EAN, množstvím, cenou s DPH a sazbou DPH.
4. V poli **Zahrnout objednávky** zvolte **Všechny**. Změny od posledního stažení
   si MyÚčto hlídá samo.
5. V **Nastavení → Administrace → Zabezpečení exportů** vytvořte partnera s hashem
   a povolte stahování jen z IP adresy serveru MyÚčta.
6. Zkopírujte trvalý odkaz exportu (má tvar
   `https://…/export/orders.xml?patternId=…&hash=…`).
7. V MyÚčtu otevřete `Sklad → Shoptet`, záložku **Nastavení**, sekci
   **Odkaz na export objednávek**, odkaz vložte do pole **Odkaz na export**
   a uložte.

**Jak poznáte, že je hotovo:** v sekci se objeví **Uložený odkaz** (zkráceně)
a na záložce **Objednávky** funguje tlačítko **Stáhnout z odkazu**.

> [!WARNING]
> Odkaz obsahuje hash, se kterým jde stáhnout údaje zákazníků. MyÚčto ho ukládá
> šifrovaně a zobrazuje jen zkráceně. Stahuje jen přes https a jen z veřejné adresy
> e-shopu. Ve Shoptetu proto povolte stahování jen z IP adresy serveru MyÚčta.

## 39.4 Krok za krokem: import objednávek

1. Otevřete `Sklad → Shoptet`, záložku **Objednávky**.
2. Nahrajte export (XML nebo CSV, nejvýš 20 MB) a klikněte na **Náhled souboru**.
   Nebo klikněte na **Stáhnout z odkazu**, pokud máte uložený odkaz.
3. V náhledu zkontrolujte, co se s každou objednávkou stane: **Založí se**,
   **Aktualizuje se**, **Beze změny**, **Změna se nepřevezme**, nebo **Chyby**.
   Všimněte si upozornění a sloupce **Ke kontrole DPH**. Zatím se nic neuložilo.
4. Klikněte na **Importovat objednávky**. Nechcete-li import dokončit, klikněte
   na **Zahodit náhled**.
5. Objednávky najdete v `Prodej → Prodejní objednávky`. Odkaz
   **Otevřít objednávku** je přímo ve výsledku importu.

**Jak poznáte, že je hotovo:** zobrazí se hláška **Import objednávek je hotový**
a výsledek rozepsaný na **Založeno**, **Aktualizováno**, **Beze změny**,
**Změna nepřevzata**, **Chyby** a **Ke kontrole DPH**.

> [!TIP]
> Chcete-li, aby importované objednávky rovnou rezervovaly zásobu, zapněte
> v záložce **Nastavení** volbu **Importované objednávky rovnou potvrdit a rezervovat
> zásobu**. Když zásoba nestačí, objednávka zůstane rozpracovaná a v reportu je
> upozornění.

### 39.4.1 Vyřešte objednávky ke kontrole DPH

Objednávku označenou **ke kontrole** nejde vyfakturovat, dokud kontrolu nepotvrdíte.

1. Na záložce **Objednávky** otevřete sekci **Objednávky ke kontrole**.
2. Objednávku otevřete a zkontrolujte zařazení DPH (zemi doručení, DIČ, sazby
   a součet řádků).
3. Klikněte na **Kontrola hotová**.

**Jak poznáte, že je hotovo:** zobrazí se **Kontrola potvrzena** a objednávka
zmizí ze seznamu. Čeká-li už jen prázdný seznam, vidíte **Žádná objednávka nečeká
na kontrolu**.

### 39.4.2 Vraťte špatně nahranou dávku

V přehledu **Poslední dávky** klikněte u dávky na **Smazat objednávky dávky**
a potvrďte. Smažou se jen rozpracované nebo stornované objednávky bez faktury,
výdeje a vratky. Ostatní zůstanou a aplikace napíše, kolik jich ponechala.

## 39.5 Krok za krokem: automatické stahování objednávek

1. Otevřete `Sklad → Shoptet`, záložku **Nastavení**. Musí tam být uložený
   **Odkaz na export objednávek** (viz [§ 39.3.2](#3932-nastavte-export-objednavek-v-shoptetu)).
2. Zapněte **Stahovat objednávky automaticky**.
3. V poli **Jak často stahovat** zvolte interval (od 15 minut do jednou denně).
4. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** na záložce **Objednávky** se zobrazí
**Automatické stahování každých N min** a v nastavení **Poslední stažení**
s časem. Případná chyba posledního stažení je u výsledku.

Stahování zajišťuje naplánovaná úloha, kterou nastavuje správce (viz
[Po instalaci](05_Po_instalaci.md)). Nefunguje-li, začněte tam.

## 39.6 Krok za krokem: import dokladů ze Shoptetu

Jen když v nastavení vystavuje doklady **Shoptet**. V režimu MyÚčto je import
odmítnutý, aby nevznikla dvojí fakturace.

1. V Shoptetu otevřete **Nastavení → Objednávky → Doklady → Export dokladů** a zapněte
   **Používat formát ISDOC**.
2. V přehledu **Daňové doklady** klikněte na **Export**, zvolte **XML (ISDOC)**,
   období a měnu. Stejně exportujte **Dobropisy** a **Doklady k přijaté platbě**.
3. **Zálohové faktury** Shoptet v ISDOC nevydává. Exportujte je jako **XML (Pohoda)**.
4. V MyÚčtu otevřete `Sklad → Shoptet`, záložku **Doklady**, nahrajte soubory
   nebo ZIP a klikněte na **Importovat doklady**.
5. Přečtěte si report. Doklady uložené jako koncept a odmítnuté doklady mají
   u sebe vysvětlení (viz [§ 39.9](#399-kdyz-neco-nejde)).

**Jak poznáte, že je hotovo:** zobrazí se shrnutí **Založeno, přeskočeno, chyby,
navázáno na objednávky** a hláška o počtu faktur navázaných na importované
objednávky.

> [!WARNING]
> Faktura, která odečítá zálohy, se uloží jako koncept. Otevřete ji, navažte na
> daňový doklad k záloze, zkontrolujte částky a teprve pak ji vystavte. Jinak by
> se tatáž platba zdanila podruhé.

## 39.7 Krok za krokem: feed zásob a cen pro Shoptet

1. Otevřete `Sklad → Shoptet`, záložku **Export pro Shoptet**.
2. V sekci **Co feed obsahuje** zvolte **Zásoby ze skladu**, **Produkty** a případně
   **Posílat cenu s DPH**.
3. Klikněte na **Vygenerovat odkaz na feed** a odkaz **hned zkopírujte**. Zobrazí se
   jen jednou.
4. V administraci Shoptetu otevřete **Produkty → Import → Automatické importy**
   a přidejte import z URL.
5. Vložte odkaz, zvolte formát **Shoptet XML** a párování podle **kódu produktu**.
   V aktualizačním importu zaškrtněte jen **Sklad**, případně **Cenu**.
6. Na skladových kartách v MyÚčtu zapněte **Exportovat do e-shopu**. Kód karty musí
   být stejný jako kód produktu nebo varianty v Shoptetu.

**Jak poznáte, že je hotovo:** feed je **zapnutý** a vidíte **Shoptet si feed
naposledy stáhl** s časem.

### 39.7.1 Ruční stažení feedu

V sekci **Ruční stažení** klikněte na **Stáhnout XML** (tentýž soubor, který čte Shoptet),
nebo na **Stáhnout CSV** pro ruční import produktů v Shoptetu (**Produkty → Import**).

### 39.7.2 Nový odkaz nebo vypnutí feedu

Odkaz se po zavření nedá znovu zobrazit. Můžete vygenerovat nový (**Vygenerovat nový
odkaz**, starý tím přestane platit a Shoptet bude potřebovat nový), nebo feed vypnout
tlačítkem **Vypnout feed**.

## 39.8 Krok za krokem: kontrola nového e-shopu po prvním importu

1. Na záložce **Objednávky** projděte v náhledu i ve výsledku upozornění u nespárovaných
   položek. Nespárovaná položka se převezme jako textový řádek bez skladu.
2. Opravte kódy karet v MyÚčtu tak, aby seděly s Shoptetem, a import zopakujte.
   Opakovaný import téhož kódu objednávku nezaloží podruhé.
3. Vyřešte objednávky ke kontrole DPH (viz [§ 39.4.1](#3941-vyreste-objednavky-ke-kontrole-dph)).

**Jak poznáte, že je hotovo:** v náhledu nezbývají nespárované položky a v sekci
**Objednávky ke kontrole** je prázdný seznam.

## 39.9 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Hláška, že doklady vystavuje Shoptet, při vystavení faktury z objednávky | V nastavení je zvolený režim **Shoptet** | Doklad převezměte importem na záložce **Doklady**, nebo režim přepněte v záložce **Nastavení** |
| Import dokladů je odmítnutý | V nastavení vystavuje doklady **MyÚčto** | Chcete-li doklady ze Shoptetu, přepněte volbu v záložce **Nastavení** |
| Objednávku nejde vyfakturovat | Je ke kontrole DPH | Sekce **Objednávky ke kontrole**, zkontrolujte a klikněte na **Kontrola hotová** |
| V náhledu je **Nespárované položky** | Kód ani EAN nesedí s žádnou kartou | Sjednoťte kód karty s kódem produktu v Shoptetu a import zopakujte |
| **Změna nepřevzata** | Objednávka je potvrzená, vyfakturovaná, stornovaná nebo uzavřená | Změnu vyfakturované objednávky řešte opravným dokladem |
| Hláška, že některé objednávky se nepodařilo uložit, a příští stažení bere stejné období | Chybí třeba měna | Odstraňte příčinu (aktivujte měnu), chybná objednávka se načte při dalším stažení |
| **Nejdřív uložte odkaz na export objednávek v záložce Nastavení** | Tlačítko **Stáhnout z odkazu** nemá odkaz | Uložte odkaz (viz [§ 39.3.2](#3932-nastavte-export-objednavek-v-shoptetu)) |
| Stažení z odkazu selže | Shoptet povoluje úplný export nejvýš jednou za 15 minut, nebo odkaz není dostupný z IP serveru | Počkejte a zkontrolujte **Zabezpečení exportů** v Shoptetu |
| Faktura z importu je koncept s varováním | Odečítá zálohu, nebo se součet řádků liší o víc než 1 Kč | Otevřete ji, navažte na doklad k záloze, zkontrolujte částky a vystavte |
| Druhá faktura k téže objednávce se neimportovala | Tatáž tržba by se zaevidovala dvakrát | Report uvádí, ke které faktuře objednávka patří |
| Doklad odmítnut, protože dodavatel nemá IČO vaší firmy | Doklad nevystavila vaše firma | Nic se neuloží jako přijatá faktura, zkontrolujte export |
| Zásoba ve Shoptetu je pozadu | Shoptet feed zpracuje jen jednou až 16× denně | Viz omezení v [§ 39.10.7](#39107-omezeni) |
| Hláška o přeskočených položkách u stažení CSV | Varianty, příliš dlouhý kód nebo kód začínající `=`, `+`, `-`, `@` | Tyto produkty přenášejte XML feedem |

## 39.10 Podrobnosti a pravidla

### 39.10.1 Kdo vystavuje doklady

<!-- cols: 24 76 -->
| Volba | Co to znamená |
|---|---|
| **MyÚčto** (doporučeno) | V Shoptetu vypněte automatické vystavování dokladů. Faktury vystavíte z importovaných objednávek v MyÚčtu. DPH, OSS i číselná řada jsou pod kontrolou MyÚčta. Import dokladů ze Shoptetu je vypnutý. |
| **Shoptet** | Doklady vystavuje Shoptet a MyÚčto je převezme importem s původním číslem. Z objednávek ze Shoptetu MyÚčto doklad nevystaví. Pokus o vystavení faktury z takové objednávky skončí hláškou, že doklady vystavuje Shoptet. |

Režim MyÚčto je doporučený: MyÚčto zná vlastní zařazení DPH (OSS, přenesení daňové
povinnosti) a nepřebírá výpočet jiného systému. Shoptet počítá DPH položky jako rozdíl
ceny s DPH a bez DPH, takže se součty mohou lišit o haléře.

### 39.10.2 Co musí obsahovat soubor s objednávkami

MyÚčto čte XML ve tvaru systémové šablony Shoptetu. Kořen je `ORDERS`, každá objednávka
je `ORDER` a obsahuje:

- `CODE`, `DATE`, `STATUS` a `CURRENCY` (`CODE`, `EXCHANGE_RATE`),
- `CUSTOMER` s `EMAIL`, `PHONE`, `BILLING_ADDRESS` a `SHIPPING_ADDRESS`,
- `TOTAL_PRICE` (`WITH_VAT`, `ROUNDING`, `PRICE_TO_PAY`),
- `ORDER_ITEMS/ITEM` s položkami `TYPE`, `CODE`, `EAN`, `NAME`, `AMOUNT`, `UNIT_PRICE`
  a `TOTAL_PRICE`, u cen `WITH_VAT` a `VAT_RATE`.

U CSV MyÚčto pozná oddělovač samo (středník, čárka, tabulátor). Kódování může být UTF-8
i Windows-1250. Sloupce páruje podle názvu: přijímá názvy placeholderů Shoptetu (například
`code`, `billCompanyId`, `orderItemCode`, `orderItemVatRate`) i české popisky.
Neznámé elementy a sloupce se ignorují. Chybí-li objednávce kód, množství, cena nebo sazba
DPH, zobrazí se chyba jen u této objednávky, ostatní se naimportují.

### 39.10.3 Co se z objednávky přenáší

- **Objednávka** dostane kód ze Shoptetu jako číslo. Opakovaný import téhož kódu ji nikdy
  nezaloží podruhé.
- **Ceny jsou s DPH**, stejně jako v Shoptetu. Faktura z objednávky tento údaj převezme.
- **Položky katalogu** se párují podle kódu produktu (kód karty v MyÚčtu), potom podle
  EAN. Spárovaná položka dostane kartu a sklad (výchozí prodejní sklad, nebo sklad zvolený
  v poli **Sklad pro položky z katalogu**). **Nespárovaná položka** se převezme jako
  textový řádek bez skladu a v náhledu je u ní upozornění.
- **Doprava a platba** jsou samostatné řádky. **Slevy a kupóny** jsou záporné řádky
  se svou sazbou DPH.
- **Zákazník** se hledá podle IČO, DIČ a nakonec podle e-mailu. Nový zákazník se založí
  jen tehdy, když ho MyÚčto nenajde.
- **Měna a kurz** se převezmou. Měna musí být ve firmě aktivní.

Když se objednávka v Shoptetu změní a importujete ji znovu:

<!-- cols: 40 60 -->
| Stav objednávky v MyÚčtu | Co se stane |
|---|---|
| rozpracovaná, bez faktury | přepíše se podle Shoptetu |
| potvrzená, vyfakturovaná, stornovaná nebo uzavřená | změna se nepřevezme a objednávka se objeví v přehledu ke kontrole |

Doklad se nikdy nepřepisuje. Změnu vyfakturované objednávky řešte opravným dokladem.

### 39.10.4 Kdy je objednávka ke kontrole DPH

MyÚčto uloží údaje Shoptetu o DPH: zemi doručení, IČO, DIČ, sazby a případně režim DPH.
Porovná je s vlastním zařazením plnění. Objednávku označí **ke kontrole**, když:

- Shoptet účtoval sazbu, která v tuzemsku k datu objednávky neplatí (typicky OSS),
- zásilka míří do jiného státu (bez DIČ jde o OSS nebo místo plnění, s DIČ o přenesení
  daňové povinnosti nebo o dodání do jiného členského státu),
- MyÚčto by plnění zařadilo jinak než Shoptet (OSS proti tuzemské sazbě),
- součet řádků se od celkové ceny Shoptetu liší o víc než 1 Kč.

### 39.10.5 Automatické stahování: pravidla

- První stažení bere celý export. Shoptet ho dovolí nejvýš **jednou za 15 minut**.
- Další stažení žádají jen změny od posledního stažení (parametr `updateTimeFrom`
  s krátkým překryvem, duplicity pohlídá kód objednávky).
- Automatické stahování zapisuje rovnou, bez náhledu. Pravidla jsou stejná jako při ručním
  importu, vyfakturované objednávky se nepřepisují.
- Když se některou objednávku nepodaří uložit (například chybí měna), MyÚčto si
  **nezapamatuje čas stažení**. Příští stažení vezme znovu stejné období, takže se chybná
  objednávka načte, jakmile odstraníte příčinu. Dávka to uvádí u výsledku.
- Výsledek je v přehledu dávek a v nastavení (čas a případná chyba posledního stažení).
- Úlohu obsluhuje `cron-shoptet-orders` (viz [Po instalaci](05_Po_instalaci.md)).

### 39.10.6 Jak se ukládají doklady ze Shoptetu

- jako **vydané faktury** s číslem ze Shoptetu (číslo je zároveň variabilní symbol,
  nepřečíslovává se),
- dobropis jako opravný daňový doklad, doklad k přijaté platbě jako daňový doklad
  k platbě, zálohová faktura jako zálohová faktura,
- doklad k přijaté platbě je rovnou **zaplacený** (ke dni přijetí platby) a nic se na něm
  nedoplácí,
- DPH se eviduje po řádcích podle sazeb dokladu, včetně OSS a cizí měny,
- doklad, jehož dodavatel nemá IČO vaší firmy, se odmítne. Nic se neuloží jako přijatá
  faktura,
- MyÚčto porovná součet řádků s celkovou částkou dokladu a s částkou k úhradě. Menší
  rozdíl než 1 Kč (zaokrouhlení) nahlásí jako poznámku,
- faktura se podle čísla objednávky naváže na importovanou objednávku ze Shoptetu,
- opakovaný import téhož dokladu se přeskočí.

**Doklad uložený jako koncept.** Faktura, která **odečítá zálohy** (v souboru je odpočet
zaplacené nebo zdaněné zálohy), nebo jejíž součet řádků se od dokladu liší o víc než 1 Kč,
se uloží jako **koncept** a v reportu je u ní varování. Koncept nejde do DPH ani do
pohledávek. Odpočet zálohy je v souboru mimo řádky dokladu, a kdyby se faktura převzala
jako vystavená, tatáž platba by se zdanila podruhé (jednou na dokladu k přijaté platbě,
podruhé na faktuře). Fakturu otevřete, navažte ji na daňový doklad k záloze, zkontrolujte
částky a teprve pak ji vystavte. Stejně se chová i běžný import vydaných faktur
(viz [Importy](21_Importy.md)).

**Druhá faktura k téže objednávce se odmítne.** Když má objednávka ze Shoptetu už
navázanou fakturu (nebo je v téže dávce jiná faktura ke stejné objednávce), další faktura
se nenaimportuje a report uvede, ke které faktuře objednávka patří. Tatáž tržba by se
jinak zaevidovala dvakrát. Dobropis a doklad k přijaté platbě k téže objednávce projdou,
opakovaný import navázané faktury se přeskočí jako duplicita.

Report importu je stejný jako u běžného importu faktur a dávku lze zahodit, dokud doklady
nejsou zaúčtované ani uhrazené (viz [Importy](21_Importy.md)).

### 39.10.7 Omezení

- **Zásoby se neaktualizují okamžitě.** Shoptet feed zpracuje podle tarifu 1× (Free,
  Basic), 3× (Business), 6× (Profi) až 16× denně (Enterprise), a to jen při změně.
  U posledních kusů hrozí přeprodej.
- **Nic se nezapisuje zpět do Shoptetu.** Stav objednávky, úhrada ani číslo zásilky se do
  Shoptetu nepropisují. Platby párujte v MyÚčtu (banka, platební brány).
- Objednávky se stahují nejvýš jednou za 15 minut. Úplný export Shoptet dovolí nejvýš
  jednou za 15 minut.
- Přesná struktura exportů Shoptetu není veřejně zdokumentovaná a obchodník si šablonu
  může upravit. MyÚčto je proto tolerantní, ale šablonu je dobré udržovat podle
  [§ 39.3.2](#3932-nastavte-export-objednavek-v-shoptetu).
- Automatické napojení přes API Shoptetu (doplněk nebo Premium) tato stránka nepoužívá.

### 39.10.8 Co feed obsahuje

- jen karty označené pro e-shop, nebo všechny aktivní karty zboží a výrobků (podle
  nastavení),
- **kód**, **EAN**, **cenu s DPH** (z cenotvorby MyÚčta včetně akční ceny, lze vypnout)
  a **množství**,
- množství je fyzický stav ve zvoleném skladu, nebo součet prodejných skladů, minus
  rezervace objednávek z jiných kanálů než Shoptet (vlastní objednávky si Shoptet odečítá
  sám),
- produkt s variantami je jeden produkt s variantami a parametry podle voleb karty,
- název ani popis jednoduchého produktu se neposílají, katalog v Shoptetu zůstává
  nedotčený.

Feed je stabilní, bez časového razítka, a nese hlavičky `ETag` a `Last-Modified`.
Shoptet ho zpracuje jen tehdy, když se od posledního importu změnil. Formát vychází
ze specifikace Shoptet XML (Relax NG `products-supplier-v10.rng`).

MyÚčto si ukládá jen otisk odkazu na feed, proto ho později nezobrazí.

**CSV pro ruční import** má sloupce `code`, `pairCode`, `stock`, `price` a `includingVat`.
Oddělovač je středník, kódování UTF-8. CSV obsahuje jen produkty bez variant, protože
varianty Shoptet páruje přes svůj párovací kód. Ten MyÚčto nezná, varianty proto
přenášejte XML feedem. Vynechá také produkty, jejichž kód začíná znakem `=`, `+`, `-`
nebo `@`: tabulkový procesor by kód četl jako vzorec. Takové produkty přenese XML feed.

Samostatný formát jen pro zásoby Shoptet nemá. Aktualizaci skladu umí jen XML feed
(automaticky) a import produktů v CSV nebo XLSX (ručně).

### 39.10.9 Doporučení

- Používejte režim, ve kterém doklady vystavuje MyÚčto, a v Shoptetu automatické doklady
  vypněte.
- Kód produktu v Shoptetu držte stejný jako kód karty v MyÚčtu.
- U odkazu na export povolte v Shoptetu jen IP adresu serveru MyÚčta.
- Po prvním importu projděte objednávky ke kontrole a nespárované položky.

## 39.11 Související kapitoly

- [Sklad](37_Sklad.md): skladové karty, prodejní objednávky a rezervace.
- [E-shop](38_Eshop.md): číselníky, cenotvorba a integrační centrum.
- [Importy](21_Importy.md): report importu faktur a zahození dávky.
- [Po instalaci](05_Po_instalaci.md): naplánované úlohy včetně stahování objednávek.
