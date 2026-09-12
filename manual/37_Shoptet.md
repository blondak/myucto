# 37. Shoptet

Stránka **E-shop → Shoptet** napojí e-shop na platformě Shoptet bez jeho API. Stačí
exporty a importy souborů, které Shoptet nabízí na všech tarifech:

- **import objednávek** ze souboru nebo z trvalého odkazu na export objednávek,
- **import dokladů** (faktury, dobropisy, doklady k přijaté platbě, zálohové faktury),
  pokud doklady vystavuje Shoptet,
- **feed zásob a cen**, který si Shoptet sám stahuje a podle kterého aktualizuje sklad.

Stránka má čtyři záložky: **Objednávky**, **Doklady**, **Export pro Shoptet**
a **Nastavení**. U každé je krátký návod krok za krokem. Stránka je součástí skladového
modulu a řídí se oprávněním k e-shopu. Zakládání objednávek navíc vyžaduje oprávnění
k prodejním objednávkám a import dokladů oprávnění vystavovat faktury.

## 37.1 Kdo vystavuje doklady

V jednom e-shopu smí daňové doklady vystavovat **právě jeden systém**. Jinak se tatáž
tržba zaeviduje dvakrát. V záložce **Nastavení** proto zvolte:

| Volba | Co to znamená |
|---|---|
| **MyÚčto** (doporučeno) | V Shoptetu vypněte automatické vystavování dokladů. Faktury vystavíte z importovaných objednávek v MyÚčtu. DPH, OSS i číselná řada jsou pod kontrolou MyÚčta. Import dokladů ze Shoptetu je vypnutý. |
| **Shoptet** | Doklady vystavuje Shoptet a MyÚčto je převezme importem s původním číslem. Z objednávek ze Shoptetu MyÚčto doklad nevystaví. Pokus o vystavení faktury z takové objednávky skončí hláškou, že doklady vystavuje Shoptet. |

Režim MyÚčto je doporučený: MyÚčto zná vlastní zařazení DPH (OSS, přenesení daňové
povinnosti) a nepřebírá výpočet jiného systému. Shoptet počítá DPH položky jako rozdíl
ceny s DPH a bez DPH, takže se součty mohou lišit o haléře.

## 37.2 Nastavení exportu objednávek v Shoptetu

1. V administraci Shoptetu otevřete **Objednávky → Export**.
2. Vytvořte vlastní šablonu ve formátu XML nebo zkopírujte systémovou šablonu
   **Shoptet - XML**. Pro CSV zapněte export jednotlivých položek objednávky a řádek
   s hlavičkou.
3. Šablona musí obsahovat:
   - kód objednávky, datum, měnu a kurz a e-mail,
   - fakturační a doručovací adresu s kódem země, IČO a DIČ,
   - celkovou cenu,
   - položky s typem, kódem, EAN, množstvím, cenou s DPH a sazbou DPH.
4. V poli **Zahrnout objednávky** zvolte **Všechny**. Změny od posledního stažení si
   MyÚčto hlídá samo.
5. V **Nastavení → Administrace → Zabezpečení exportů** vytvořte partnera s hashem
   a povolte stahování jen z IP adresy serveru MyÚčta.
6. Zkopírujte trvalý odkaz exportu (tvar `https://…/export/orders.xml?patternId=…&hash=…`)
   a vložte ho v MyÚčtu v záložce **Nastavení → Odkaz na export objednávek**.

Odkaz obsahuje hash, se kterým jde stáhnout údaje zákazníků. MyÚčto ho ukládá šifrovaně
a zobrazuje jen zkráceně. Stahuje jen přes https a jen z veřejné adresy e-shopu.

### Co musí obsahovat soubor

MyÚčto čte XML ve tvaru systémové šablony Shoptetu. Kořen je `ORDERS`, každá objednávka
je `ORDER` a obsahuje:

- `CODE`, `DATE`, `STATUS` a `CURRENCY` (`CODE`, `EXCHANGE_RATE`),
- `CUSTOMER` s `EMAIL`, `PHONE`, `BILLING_ADDRESS` a `SHIPPING_ADDRESS`,
- `TOTAL_PRICE` (`WITH_VAT`, `ROUNDING`, `PRICE_TO_PAY`),
- `ORDER_ITEMS/ITEM` s položkami `TYPE`, `CODE`, `EAN`, `NAME`, `AMOUNT`, `UNIT_PRICE`
  a `TOTAL_PRICE`, u cen `WITH_VAT` a `VAT_RATE`.

U CSV MyÚčto pozná oddělovač sám (středník, čárka, tabulátor). Kódování může být UTF-8
i Windows-1250. Sloupce páruje podle názvu: přijímá názvy placeholderů Shoptetu (například
`code`, `billCompanyId`, `orderItemCode`, `orderItemVatRate`) i české popisky.
Neznámé elementy a sloupce se ignorují. Chybí-li objednávce kód, množství, cena nebo sazba
DPH, zobrazí se chyba jen u této objednávky, ostatní se naimportují.

## 37.3 Import objednávek

V záložce **Objednávky**:

1. Nahrajte export (XML nebo CSV, nejvýš 20 MB) a klikněte na **Náhled souboru**, nebo
   klikněte na **Stáhnout z odkazu**.
2. Náhled ukáže, co se s každou objednávkou stane: založí se, aktualizuje se, zůstane beze
   změny, změna se nepřevezme, nebo chyba. U objednávek jsou i upozornění a důvody ke
   kontrole DPH. **Nic se zatím neuložilo.**
3. Klikněte na **Importovat objednávky**. Objednávky vzniknou v **Prodejní objednávky**.

Co se přenáší:

- **Objednávka** dostane kód ze Shoptetu jako číslo. Opakovaný import téhož kódu ji nikdy
  nezaloží podruhé.
- **Ceny jsou s DPH**, stejně jako v Shoptetu. Faktura z objednávky tento údaj převezme.
- **Položky katalogu** se párují podle kódu produktu (kód karty v MyÚčtu), potom podle
  EAN. Spárovaná položka dostane kartu a sklad (výchozí prodejní sklad, nebo sklad zvolený
  v nastavení). **Nespárovaná položka** se převezme jako textový řádek bez skladu
  a v náhledu je u ní upozornění.
- **Doprava a platba** jsou samostatné řádky. **Slevy a kupóny** jsou záporné řádky
  se svou sazbou DPH.
- **Zákazník** se hledá podle IČO, DIČ a nakonec podle e-mailu. Nový zákazník se založí
  jen tehdy, když ho MyÚčto nenajde.
- **Měna a kurz** se převezmou. Měna musí být ve firmě aktivní.

Když se objednávka v Shoptetu změní a importujete ji znovu:

| Stav objednávky v MyÚčtu | Co se stane |
|---|---|
| rozpracovaná, bez faktury | přepíše se podle Shoptetu |
| potvrzená, vyfakturovaná, stornovaná nebo uzavřená | změna se nepřevezme a objednávka se objeví v přehledu ke kontrole |

Doklad se nikdy nepřepisuje. Změnu vyfakturované objednávky řešte opravným dokladem.

Volba **Importované objednávky rovnou potvrdit a rezervovat zásobu** v nastavení objednávky
rovnou potvrdí. Když zásoba nestačí, objednávka zůstane rozpracovaná a v reportu je
upozornění.

Po importu lze v přehledu **Poslední dávky** smazat objednávky, které dávka založila.
Smažou se jen rozpracované nebo stornované objednávky bez faktury, výdeje a vratky.

## 37.4 Kontrola DPH u objednávek

MyÚčto uloží údaje Shoptetu o DPH: zemi doručení, IČO, DIČ, sazby a případně režim DPH.
Porovná je s vlastním zařazením plnění. Objednávku označí **ke kontrole**, když:

- Shoptet účtoval sazbu, která v tuzemsku k datu objednávky neplatí (typicky OSS),
- zásilka míří do jiného státu (bez DIČ jde o OSS nebo místo plnění, s DIČ o přenesení
  daňové povinnosti nebo o dodání do jiného členského státu),
- MyÚčto by plnění zařadilo jinak než Shoptet (OSS proti tuzemské sazbě),
- součet řádků se od celkové ceny Shoptetu liší o víc než 1 Kč.

Objednávku ke kontrole nejde vyfakturovat. V přehledu **Objednávky ke kontrole** si
objednávku otevřete, zkontrolujte zařazení a klikněte na **Kontrola hotová**.

## 37.5 Automatické stahování objednávek

V nastavení zapněte **Stahovat objednávky automaticky** a zvolte interval (15 minut až
jednou denně). Stahování zajišťuje cron úloha `cron-shoptet-orders` (viz kapitola
[Po instalaci](05_Po_instalaci.md)).

- První stažení bere celý export. Shoptet ho dovolí nejvýš **jednou za 15 minut**.
- Další stažení žádají jen změny od posledního stažení (parametr `updateTimeFrom`
  s krátkým překryvem, duplicity pohlídá kód objednávky).
- Automatické stahování zapisuje rovnou, bez náhledu. Pravidla jsou stejná jako při ručním
  importu, vyfakturované objednávky se nepřepisují.
- Když se některou objednávku nepodaří uložit (například chybí měna), MyÚčto si
  **nezapamatuje čas stažení**. Příští stažení vezme znovu stejné období, takže se chybná
  objednávka načte, jakmile odstraníte příčinu. Dávka to uvádí u výsledku.
- Výsledek je v přehledu dávek a v nastavení (čas a případná chyba posledního stažení).

## 37.6 Import dokladů ze Shoptetu

Jen v režimu **Doklady vystavuje Shoptet**. V režimu MyÚčto je import odmítnutý, aby
nevznikla dvojí fakturace.

1. V Shoptetu otevřete **Nastavení → Objednávky → Doklady → Export dokladů** a zapněte
   **Používat formát ISDOC**.
2. V přehledu **Daňové doklady** klikněte na **Export**, zvolte **XML (ISDOC)**, období
   a měnu. Stejně exportujte **Dobropisy** a **Doklady k přijaté platbě**.
3. **Zálohové faktury** Shoptet ve formátu ISDOC nevydává. Exportujte je jako
   **XML (Pohoda)**.
4. V záložce **Doklady** nahrajte soubory nebo ZIP a klikněte na **Importovat doklady**.

Jak se doklady uloží:

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

## 37.7 Export zásob a cen pro Shoptet

Shoptet umí automaticky importovat produkty z XML feedu na adrese URL. MyÚčto tento feed
vystavuje.

1. V záložce **Export pro Shoptet** klikněte na **Vygenerovat odkaz na feed** a odkaz
   zkopírujte. Zobrazí se **jen jednou**. MyÚčto si ukládá jen jeho otisk, takže ho později
   nezobrazí. Můžete jen vygenerovat nový odkaz (starý tím přestane platit), nebo feed
   vypnout.
2. V administraci Shoptetu otevřete **Produkty → Import → Automatické importy** a přidejte
   import z URL.
3. Vložte odkaz, zvolte formát **Shoptet XML** a párování podle **kódu produktu**.
   V aktualizačním importu zaškrtněte jen **Sklad**, případně **Cenu**.
4. Na kartách zboží v MyÚčtu zapněte **export na e-shop**. Kód karty musí být stejný jako
   kód produktu nebo varianty v Shoptetu.

Co feed obsahuje:

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

**Ruční stažení:** tlačítko **Stáhnout XML** stáhne tentýž feed. **Stáhnout CSV**
připraví soubor pro ruční import produktů v Shoptetu (Produkty → Import) se sloupci
`code`, `pairCode`, `stock`, `price` a `includingVat`. Oddělovač je středník, kódování
UTF-8. CSV obsahuje jen produkty bez variant, protože varianty Shoptet páruje přes svůj
párovací kód. Ten MyÚčto nezná, varianty proto přenášejte XML feedem. Vynechá také
produkty, jejichž kód začíná znakem `=`, `+`, `-` nebo `@`: tabulkový procesor by kód
četl jako vzorec. Takové produkty přenese XML feed.

Samostatný formát jen pro zásoby Shoptet nemá. Aktualizaci skladu umí jen XML feed
(automaticky) a import produktů v CSV nebo XLSX (ručně).

## 37.8 Omezení

- **Zásoby se neaktualizují okamžitě.** Shoptet feed zpracuje podle tarifu 1× (Free,
  Basic), 3× (Business), 6× (Profi) až 16× denně (Enterprise), a to jen při změně.
  U posledních kusů hrozí přeprodej.
- **Nic se nezapisuje zpět do Shoptetu.** Stav objednávky, úhrada ani číslo zásilky se do
  Shoptetu nepropisují. Platby párujte v MyÚčtu (banka, platební brány).
- Objednávky se stahují nejvýš jednou za 15 minut. Úplný export Shoptet dovolí nejvýš
  jednou za 15 minut.
- Přesná struktura exportů Shoptetu není veřejně zdokumentovaná a obchodník si šablonu
  může upravit. MyÚčto je proto tolerantní, ale šablonu je dobré udržovat podle bodu 34a.2.
- Automatické napojení přes API Shoptetu (doplněk nebo Premium) tato stránka nepoužívá.

## 37.9 Doporučení

- Používejte režim **Doklady vystavuje MyÚčto** a v Shoptetu automatické doklady vypněte.
- Kód produktu v Shoptetu držte stejný jako kód karty v MyÚčtu.
- U odkazu na export povolte v Shoptetu jen IP adresu serveru MyÚčta.
- Po prvním importu projděte objednávky ke kontrole a nespárované položky.
