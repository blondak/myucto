# 69. Nástroje

**Cesta: `Nástroje → Účetní nastavení`**. Sekce **Nástroje** následuje v
hlavním menu bezprostředně po **Účetnictví** v horním i levém rozložení.

Nástroje jsou jedna položka menu s několika záložkami. Viditelnost závisí na
účetním režimu a roli:

| Záložka | Dostupnost |
|---|---|
| Střediska | Podvojné účetnictví |
| Předkontace | Podvojné účetnictví |
| Kurzový režim | Podvojné účetnictví |
| Repo sazba ČNB | Podvojné účetnictví |
| Archiv účetnictví | Podvojné účetnictví, jen administrátor |

Účetní období a průvodce uzávěrkou jsou samostatný bod menu
[Uzávěrka](68_Uzaverka.md), nikoli záložka Nástrojů.

## 69.1 Hromadný export

**Cesta: `Daně → Hromadný export`**. Hromadný export je samostatná stránka,
nikoli záložka Nástrojů.

Export vytvoří ZIP podkladů za vybraný měsíc nebo kvartál. Náhled nejprve
spočítá dostupné soubory a dovolí vybrat:

- vydané faktury v PDF a ISDOC,
- přijaté faktury v PDF a ISDOC,
- bankovní výpisy v PDF a GPC,
- knihu DPH.

### 69.1.1 Zařazení do období

Export používá stejné rozhodné datum jako daňová vrstva:

- vydané faktury podle **DUZP** (`effective_tax_date`),
- přijaté faktury podle společného výrazu data nároku na odpočet z
  `VatLedgerService`, včetně data doručení a reverse-charge větví,
- bankovní výpisy podle data výpisu a výhradně podle vlastnictví účtu aktuální
  firmy,
- knihu DPH po jednotlivých měsících zvoleného období.

Tím se podklady přijatých faktur zařadí do stejného měsíce jako kniha DPH.
Změna data doručení nebo daňové klasifikace proto může změnit výsledek náhledu.

### 69.1.2 Background job

Po spuštění vznikne úloha `queued → running → completed`, případně `failed`
nebo `cancelled`. Worker vytváří soubory postupně, ukládá aktuální krok a počet
hotových položek. Aktivní může být jen jeden export firmy.

Hotový ZIP zůstává v historii ke stažení. Smazání historie odstraní i výsledný
soubor. Zrušení je kooperativní: worker požadavek kontroluje mezi položkami.
Chybějící zdrojové PDF nebo chyba rendereru se objeví v chybě úlohy; export
nepovažuj za úplný jen podle toho, že ZIP existuje.

Čtení a náhled vyžadují `reports.export`; spuštění, zrušení a smazání jeho
zápisovou variantu.

## 69.2 Střediska

Středisko rozlišuje odpovědnost nebo část firmy na řádcích účetního zápisu.
Číselník obsahuje neměnný unikátní **kód**, název a aktivní stav.

Kód se po založení nemění, aby se historické řádky a šablony nerozešly.
Nepoužité středisko lze smazat. Pokud je použité řádkem deníku nebo šablonou,
server je místo fyzického smazání deaktivuje. Neaktivní středisko zůstane v
historii, ale není nabízeno pro nový zápis.

Středisko samo nic nezaúčtuje a nemění účetní výkazy podle účtů. Je analytickým
rozměrem pro filtrování, export a manažerské vyhodnocení. Čtení vyžaduje
`accounting`, změny zápisové účetní oprávnění.

## 69.3 Předkontace

Předkontace jsou efektivní mapa systémových účetních operací na výchozí účty.
Server spojí globální pravidla s firemním override stejného klíče.

| Sloupec | Význam |
|---|---|
| Klíč | Stabilní typ operace, například `invoice.services.issued` |
| Popis | Lidský význam operace |
| MD účet | Výchozí účet strany Má dáti |
| Dal účet | Výchozí účet strany Dal |
| Původ | Globální nebo firemní |

Prázdná strana může být záměrná: konkrétní protiúčet doplní služba podle
dokladu, například u kurzového rozdílu. DPH na 343 také není součástí základní
mapy; dopočítává ji daňová služba z položek.

### 69.3.1 Firemní override

Akce **Upravit** mění jen MD/Dal účet. Alespoň jedna strana musí být vyplněná
a každý kód musí existovat a být aktivní v osnově firmy. Uložením vznikne
firemní override; globální seed se nemění.

Zaúčtování si účet ověří znovu. Deaktivuje-li se později, operace skončí
chybou, nepoužije jiný účet potichu. Stejně se hlídají závěrkové účty,
podrozvaha, otevřené období, zámek a vyrovnanost.

### 69.3.2 Import a export předkontací

Export obsahuje efektivní pohled se sloupci `klic`, `popis`, `md_ucet`,
`d_ucet`, `aktivni`, `priorita` a `zdroj`. Import přijímá XLSX/CSV do 2 MB a
má dry-run před potvrzením.

- Klíč musí existovat v globální šabloně; nový druh operace import nevytvoří.
- Účty musí být aktivní.
- `priorita` a `zdroj` jsou při importu informativní.
- Řádek shodný s efektivní hodnotou se přeskočí, aby nevznikal zbytečný override.
- Import nemaže a reportuje každý založený, změněný, přeskočený a chybný řádek.

Čtení a export používají účetní oprávnění. Web zobrazí editaci a import jen s
`accounting.templates:write`; server při zápisu navíc vyžaduje zápisové
oprávnění k účetnictví.

## 69.4 Kurzový režim

Firma může zvolit:

- **denní kurz** — kurz ČNB podle rozhodného dne,
- **pevný měsíční kurz**,
- **pevný roční kurz**.

U pevného režimu se zadává měna, rok, u měsíčního i měsíc, a kurz. Tlačítko
předvyplnění načte kurz ČNB k prvnímu dni období; uživatel jej před uložením
může změnit. Roční řádek má měsíc `0`.

`FixedExchangeRateService` při vzniku dokladu vyhledá přesný firemní kurz pro
měnu a období. Chybějící pevný kurz je chyba — server nesmí potichu přejít na
denní kurz. Použitý kurz se uloží do hlavičky dokladu jako historický snapshot.
Pozdější změna režimu nebo číselníku již uložený doklad nepřepočítá.

V denním režimu může kontrola upozornit na odchylku od ČNB. V pevném režimu je
tato odchylková kontrola záměrně vypnutá, protože odlišný kurz je zvolená
účetní metoda, ne chyba.

Pevné kurzy jsou tenantové, měna se normalizuje na třípísmenný kód a kurz musí
být kladný. Změna režimu i sazby se auditují.

## 69.5 Repo sazba ČNB

Číselník uchovává 2T repo sazbu, datum platnosti a poznámku. Řádek se stejným
datem se aktualizuje, nikoli duplikuje.

Sazbu používá výpočet zákonného úroku z prodlení:

`jistina × (repo sazba k počátku prodlení + 8) / 100 × dny / 365 nebo 366`

Rozhodná je sazba platná k prvnímu dni kalendářního pololetí, ve kterém
prodlení začalo; po dobu jednoho prodlení se změnou sazby uprostřed období
nepřepíná. Chybí-li potřebná historická sazba, kalkulátor vrátí chybu a úrok
nevymyslí. Více viz [Upomínky](22_Upominky.md).

Editaci sazeb svěř administrátorovi nebo účetnímu, který doloží zdroj ČNB.
Smazání používané historické sazby může znemožnit reprodukovat starší výpočet.

## 69.6 Archiv účetnictví

Archiv je per-firemní technický ZIP pro uschování a forenzní obnovu účetní
stopy. Je dostupný jen administrátorovi v podvojném účetnictví.

Export obsahuje JSON Lines data firmy, mimo jiné:

- účetní období, osnovu, deník, předkontace a střediska,
- dlouhodobý majetek a odpisy,
- vydané a přijaté faktury, položky a částečné úhrady,
- banku a pokladnu,
- přílohy deníku včetně binárních souborů,
- daň z příjmů a u skladové firmy skladovou evidenci.

Manifest uvádí verzi schématu, počty řádků a SHA-256 každé datové části i
přílohy. Hesla, API klíče a jiné provozní tajné hodnoty se neexportují. PDF
faktur nejsou součástí technického archivu; pro ně slouží samostatný export.

Tabulka ukazuje datum vytvoření, název, velikost a kontrolní součet. **Smazat**
odstraní metadata i ZIP. Zdrojová účetní data v databázi tím nezmizí.

### 69.6.1 Obnova ze serveru

Obnova není dostupná ve webu. Administrátor serveru použije:

```text
php api/bin/archive-restore.php --file=<cesta.zip> --dry-run
php api/bin/archive-restore.php --file=<cesta.zip> --restore
```

Dry-run ověří hash a počet každé části, přílohy, verzi migrací a vazby bez
zápisu. Ostrá obnova vždy založí **novou firmu**, přemapuje interní ID a vše
provede v jedné databázové transakci. Existující firma se nepřepisuje.

Po importu se znovu ověří podvojnost po obdobích a vypíše rozdílový report.
Přílohy se uloží do datového adresáře nové firmy. Hesla a klíče je nutné
nastavit znovu. Automatický round-trip test hlídá základní počty a součty, ale
archiv stále nenahrazuje celoinstanční zálohu databáze.

## 69.7 Retence a právní zadržení na backendu

Aktuální web Nástrojů nemá samostatnou záložku **Retence**, backend však
retenční pravidla vynucuje při mazání účetních a daňových dokladů a poskytuje
API `/api/accounting/retention`.

| Kategorie | Lhůta od konce období |
|---|---:|
| Účetní závěrka a výroční zpráva | 10 let |
| Účetní doklady, knihy, odpisové plány a inventury | 5 let |
| Daňové doklady | 10 let |

U dokladu s DPH vyhrává delší desetiletá lhůta. Poslední den lhůty je stále
chráněný; systém nic nemaže automaticky ani po jejím uplynutí.

Administrátor může při výslovně potvrzeném mazání retenční ochranu přehlasovat.
Takový zásah se zapíše do auditu s vypočteným datem uchování. Běžný uživatel
ochranu obejít nemůže.

Probíhající daňová kontrola nebo spor se eviduje jako **legal hold** podle § 32.
Zadržení může platit pro období nebo celou firmu a uchovává důvod a spisovou
značku. Aktivní hold blokuje smazání i po uplynutí běžné lhůty. Uvolnění je
ruční a auditované; historický záznam nezmizí.

## 69.8 Oprávnění a řešení problémů

- Záložky podvojného účetnictví vyžadují `accounting`; jejich změny zápisové
  oprávnění příslušného modulu.
- Měsíční export používá `reports.export`.
- Předkontace používají `accounting.templates`.
- Archiv a právní zadržení jsou administrátorské.
- Všechny soubory a záznamy jsou omezené aktuální firmou.

Při chybě background úlohy nejprve otevři její poslední krok a hlášení. Úlohu
nespouštěj opakovaně naslepo, pokud chyba vznikla chybějícím zdrojovým souborem,
neplatnou předkontací nebo nedostatkem místa. U kurzů a repo sazeb oprav
chybějící období v číselníku; již uložené doklady se samy zpětně nezmění.

> [!IMPORTANT]
> Archiv účetnictví je přenosný účetní export, ne jediná záloha. Pravidelně
> ověřuj také obnovitelnost celé databáze a datového adresáře.
