# FR: Přenositelná záloha a obnova firmy

> Typ: Feature request
>
> Stav: návrh
>
> Dotčený projekt: MyÚčto (backend, webové UI, databázové migrace,
> dokumentace a OpenAPI)
>
> Tento návrh přerámovává původní issue
> https://github.com/radekhulan/myucto/issues/24. Přenos firmy mezi
> instancemi zůstává podporovaným scénářem, ale není samostatným
> server-to-server protokolem.

## Shrnutí

Přidat uživatelsky ovládanou přenositelnou zálohu jedné firmy a její bezpečnou
obnovu. Správce vytvoří v MyÚčtu jeden heslem chráněný balíček, stáhne jej na
vlastní disk a může jej později nahrát do stejné nebo novější verze MyÚčta.
Obnova v první verzi vždy založí novou firmu. Nikdy nepřepisuje, neslučuje ani
nevrací v čase existující firmu.

Balíček má dvě vrstvy:

1. strojově obnovitelný úplný snapshot registrovaných dat firmy, souborů
   a povinných chráněných hodnot,
2. čitelnou vrstvu v běžných formátech, zejména PDF, XLSX, XML a README,
   použitelnou i bez běžícího MyÚčta.

Přenos mezi instancemi je potom běžný postup:

    instance A
        → vytvořit a stáhnout zálohu firmy
        → nahrát balíček do instance B
        → preflight a mapování
        → obnovit jako novou firmu

Odpadá zdrojový transfer grant, capabilities endpoint, přímé spojení serverů,
SSRF plocha, požadavek na shodný build a přenosový protokol závislý na
dostupnosti původní instance.

## Motivace

Přenos jedné firmy mezi dvěma živými instancemi je vzácný. Zálohu a obnovu
potřebuje každý provoz:

- pro vlastní kopii dat mimo hosting nebo původní server,
- po havárii nebo ztrátě instalace,
- po chybné hromadné operaci, kdy lze data obnovit vedle poškozené firmy
  a bezpečně je porovnat,
- pro testovací instanci,
- pro přechod mezi dvěma instalacemi MyÚčta,
- jako čitelný podklad pro dlouhodobou úschovu účetních a daňových záznamů.

Záloha má hodnotu jen tehdy, když ji lze obnovit později. Přesná shoda verze
aplikace, build revision, checksumů migračních souborů a databázového schématu
je proto pro zálohu chybný kontrakt. Hlavním scénářem je obnova starší zálohy
do novější, již domigrované verze aplikace.

Čitelná vrstva podporuje dlouhodobou dostupnost záznamů bez závislosti na
MyÚčtu. Sama o sobě není právním posudkem ani automatickou garancí splnění
všech povinností konkrétní účetní jednotky.

## Současný stav

Repozitář už obsahuje většinu potřebných stavebních bloků:

- api/src/Service/Export/Instance/InstanceExportService.php vytváří jeden
  ZIP s JSONL daty, doklady, přílohami, DPH podklady a uzávěrkovými balíčky,
- api/src/Service/Export/Instance/InstanceExportArchive.php řeší průběžný
  zápis, kvóty, SHA-256 a volitelné AES-256 šifrování,
- web/src/pages/admin/InstanceExport.vue poskytuje UI pro vytvoření a stažení,
- api/src/Service/Export/Instance/CompleteInstanceRestoreService.php a
  api/bin/archive-restore.php umějí obnovu do prázdné databáze a prázdného
  datového adresáře,
- api/src/Service/Accounting/Archive/ArchiveRestoreService.php umí u užšího
  účetního archivu založit novou firmu a přemapovat kolidující ID,
- api/bin/cron-backup.php a související skripty řeší provozní zálohu databáze,
- ClosingPackageService a MonthlyExportService vytvářejí čitelné sestavy.

Současný stav ale ještě není přenositelná uživatelská záloha:

- obnova není dostupná v UI,
- úplná obnova vyžaduje prázdnou databázi a zachovává původní ID,
- import do používané instance neumí úplný rozsah firmy,
- kompatibilita se starší verzí není řízena explicitními adaptéry;
  schema_version je převážně diagnostická informace,
- průnik sloupců při obnově může tiše zahodit neznámou hodnotu,
- některá kontextově šifrovaná data vyžadují původní aplikační klíč,
- heslo archivu pochází z konfigurace serveru a může být prázdné,
- strojová data jsou označena jako non-atomic snapshot,
- seznamy tabulek, souborů, referencí a secrets žijí ve více službách.

Tento FR rozšiřuje a sjednocuje existující implementaci. Nevytváří čtvrtý
nezávislý exportní formát nad dalším soukromým seznamem tabulek.

## Hranice vůči ostatním mechanismům

| Mechanismus | Vlastník a účel | Rozsah | Patří do tohoto FR |
|---|---|---|---|
| Přenositelná záloha firmy | uživatel, obnova a přenos | jedna firma, čitelná i strojová vrstva | ano |
| Provozní DB a storage backup | správce serveru nebo hosting, disaster recovery | celá instance včetně globálního stavu | ne |
| Účetní archiv | účetní uzávěrka a vymezené účetní agendy | užší profil firmy | sdílí registr a validátory |
| In-place upgrade MyInvoice → MyÚčto | změna produktu nad původní instalací | celá instalace | ne |
| Aktualizační rollback | návrat souborů přepsaných aktualizací | aplikační soubory | ne |

Přenositelná záloha firmy nenahrazuje pravidelný dump celé databáze a kopii
datového adresáře. Neobsahuje instanční konfiguraci, přihlašovací stav všech
uživatelů ani systémová tajemství potřebná k přesné obnově celého serveru.

## Cíle

- Vytvořit úplnou přenositelnou zálohu aktuální firmy v UI.
- Vyžadovat pro každý balíček samostatné uživatelské heslo.
- Umožnit validaci a obnovu balíčku v UI bez přístupu k databázi nebo CLI.
- Obnovit firmu do používané instance vždy pod novým supplier_id.
- Přemapovat všechna interní ID, skutečné FK, polymorfní a soft reference.
- Zachovat registrované PDF, XML, přílohy, výpisy, dodejky a další soubory.
- Mapovat globální reference podle stabilních přirozených klíčů bez
  přepisování globálních tabulek.
- Mapovat historické odkazy na uživatele na existující cílové uživatele podle
  explicitního rozhodnutí správce.
- Obnovit starší zálohu do novější verze pomocí verzovaného formátu,
  bezpečně tolerantních pravidel a explicitních upcasterů.
- Přešifrovat povinná chráněná data přes heslo zálohy bez potřeby původního
  app.secret_encryption_key.
- Nabídnout přenositelné credentials a certifikáty pouze jako výslovný opt-in.
- Všechny importované integrace a automatizace ponechat deaktivované.
- Provést úplný preflight bez zápisu do business tabulek.
- Umožnit bezpečně navázat přerušené stažení i upload velkého balíčku.
- Zajistit idempotentní upload, preflight i commit.
- Při chybě nezanechat viditelnou částečnou firmu ani změnit jinou firmu.
- Fungovat na Windows, Linuxu a v Dockeru.

## Mimo rozsah první verze

- Přepsání nebo merge do existující firmy.
- Automatický in-place rollback firmy k vybranému okamžiku.
- Obnova balíčku z novější aplikace do starší aplikace.
- Přímý export ze starého MyInvoice; zdroj se nejprve povýší in-place.
- Server-to-server přenos, zdrojové URL, transfer grant a capabilities API.
- Průběžná synchronizace nebo replikace mezi instancemi.
- Výběr libovolných SQL tabulek a expertní režim tables.
- Částečná obnova vybraného období nebo agendy do business databáze.
- Kopie licence, instančních rolí, globální konfigurace nebo runtime tokenů.
- Automatické smazání či deaktivace původní firmy po obnově.
- Automatická aktivace e-mailů, IMAP skenerů, bankovních integrací,
  recurring úloh, externích importů, podání nebo podpisových profilů.
- Přehrání historie přes běžné CRUD endpointy.
- Nahrazení provozních DB a storage záloh.
- Inkrementální nebo plánované uživatelské zálohy; formát jim nesmí bránit,
  ale nejsou podmínkou první implementace.

## Zásadní produktová rozhodnutí

### Obnova vždy založí novou firmu

Importní služba nepřijímá target_supplier_id. Vždy založí nový supplier a
všechna tenantová ID přidělí znovu. Existující firmu nelze vybrat ani skrytým
parametrem.

Po chybné hromadné operaci se záloha obnoví vedle původní firmy. Správce obě
porovná a teprve potom původní firmu ručně archivuje nebo odstraní podle
standardních retenčních pravidel.

### Strojová vrstva je vždy celá firma

Uživatel nevybírá SQL tabulky ani účetní moduly. Obnovitelná část vždy obsahuje
úplný profil registrované firmy. Časový rozsah může omezit pouze čitelnou
vrstvu, například PDF doklady a sestavy. Nikdy nesmí zkrátit data určená pro
obnovu.

Velký archiv je jeden logický balíček. Resumable upload jej přenáší po blocích.
Případné rozdělení do více fyzických svazků je pozdější transportní optimalizace,
nikoli částečná obnova po účetních obdobích.

### Přenos je postup nad zálohou

Přenos mezi instancemi nevyžaduje jejich současnou dostupnost ani vzájemné
síťové spojení. Zdroj vytvoří zálohu, uživatel ji bezpečně uloží a cíl ji
obnoví. Stejný balíček slouží pro havárii, test i migraci.

### Starší do novější, nikdy novější do starší

Cíl smí načíst zálohu ze stejné nebo starší podporované verze. Zálohu vytvořenou
novější aplikací starší cíl odmítne ještě před preflightem business dat.

Build revision, hostname, hash SQL souborů a přesný fingerprint schématu nejsou
kompatibilitní brána. Mohou zůstat v diagnostice, ale nesmějí samy zablokovat
jinak podporovanou obnovu.

### Žádné tiché zahazování historických dat

Přebývající sloupec nebo tabulka s řádky se nesmí obecně ignorovat. Ignorovat
hodnotu lze jen tehdy, když ji explicitní upcaster nebo deklarace registru
označí jako zrušenou a bezpečně odvoditelnou či bez významu.

Chybějící nový sloupec lze doplnit databázovým defaultem pouze tehdy, pokud je
to součást deklarovaného kompatibilitního pravidla. Chybějící nullable sloupec
se smí doplnit null jen tam, kde tím nevznikne účetní, daňová nebo bezpečnostní
změna významu.

### Bezpečný stav po obnově

Všechny integrace, plánované akce a podpisové profily se obnoví jako neaktivní.
Původní aktivní stav se zobrazí jen ve výsledném reportu. Správce musí každou
externí vazbu ověřit a zapnout ručně.

## Navržený formát balíčku

Nový kontrakt má vlastní formát, například myucto-company-backup verze 1.
Již vydané myucto-instance-export verze 3 až 5 zůstávají legacy vstupem přes
samostatný adaptér. Nesmějí se zpětně vydávat za nový kontrakt.

Legacy adaptér zachovává pouze prokazatelně obnovitelné chování starého
formátu. Starý archiv s kontextově šifrovanými hodnotami se bez původního
aplikačního klíče nestane dodatečně přenositelným. Preflight tuto hranici
výslovně oznámí; legacy obnova může zůstat CLI-only a vyžadovat původní klíč,
nebo bezpečně vynechat jen hodnoty, které stará politika dovolovala
rekonfigurovat. Záruka obnovy bez zdrojového klíče začíná novým formátem.

Navržená struktura:

    myucto-zaloha-YYYYMMDD-HHMM-{short-id}.zip
    ├── manifest.json
    ├── CHECKSUMS.txt
    ├── CTI-MNE.txt
    ├── data/
    │   └── {logical-object}.jsonl
    ├── files/
    │   └── {stable-reference}
    ├── readable/
    │   ├── doklady/
    │   ├── sestavy/
    │   └── ciselniky/
    └── secrets/
        └── tenant.sealed

Celý ZIP je chráněn AES-256 heslem zadaným uživatelem. Prázdné heslo ani
nešifrovaný fallback nejsou pro přenositelnou zálohu povolené. Běžné položky
balíčku musí jít po zadání hesla otevřít standardním nástrojem, například
7-Zipem, bez MyÚčta.

Protože standardní ZIP nešifruje názvy položek v centrálním adresáři, názvy
uvnitř archivu nesmějí obsahovat jména klientů, e-maily, rodná čísla ani jiné
citlivé hodnoty. Čitelný index uvnitř zašifrovaného archivu mapuje stabilní
reference na lidské názvy.

Manifest obsahuje nejméně:

- product a identifikátor formátu,
- major a minor verzi formátu,
- verzi zdrojové aplikace a logickou schema revision,
- bezpečný identifikátor zálohy,
- identitu a profil firmy,
- čas konzistentního snapshotu a čas dokončení čitelné vrstvy,
- kanonický snapshot úplného zdrojového registru včetně verze, profilu,
  definic objektů a jejich ověřitelného fingerprintu,
- zapnuté moduly a použité formátové capabilities,
- tabulky a logické objekty s počtem řádků, pořadím a SHA-256,
- soubory s velikostí, SHA-256, vlastnickou vazbou a cílovou oblastí,
- použité upcaster požadavky,
- počty secret položek podle politiky, nikdy jejich plaintext,
- bezpečně vynechané objekty a důvod,
- varování a známé chybějící historické soubory,
- post-import invarianty, které musí cíl ověřit.

Stabilní kompatibilitní obálka manifestu v1 má tento tvar; další sekce se mohou
rozšiřovat podle minor verze a capabilities:

```json
{
  "product": "myucto",
  "format": "myucto-company-backup",
  "format_version": {"major": 1, "minor": 0},
  "backup_id": "0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1",
  "source": {
    "app_version": "5.28.1",
    "schema_revision": "company-backup.schema.v1"
  },
  "capabilities": {
    "required": [],
    "optional": []
  }
}
```

Writer ukládá manifest jako kanonický JSON: klíče objektů jsou řazené, množiny
capabilities jsou seřazené a jedna logická hodnota má jedinou bajtovou podobu.
Parser nekanonickou variantu odmítne, aby se různé JSON parsery nemohly rozejít
na hashi manifestu nebo duplicitních klíčích.

Plný manifest vedle této časně čitelné obálky povinně nese objekt `registry`
ve formátu `myucto-tenant-data-registry`. Obsahuje verzi registru, profil
`company_backup`, seřazené úplné definice a SHA-256 fingerprint z jejich
kanonické podoby. Cíl fingerprint přepočítá, takže nelze pod stejným hashem
podstrčit jinou klasifikaci. Přesná shoda se současným cílovým fingerprintem
není kompatibilitní brána: starší snapshot se před zápisem porovná a případně
upcastuje podle explicitních pravidel.

CHECKSUMS.txt pokrývá každou datovou a souborovou položku. Hash celého archivu
je uložen mimo archiv v jobu a posílá se také jako hlavička při stažení.

Čitelná vrstva není vstupem obnovy. Importér používá pouze manifest, data,
registrované soubory a secret envelope.

## Konzistence snapshotu

Obnovitelná vrstva nesmí být označena jako non-atomic a současně prezentována
jako úplná záloha.

Databázová část musí vzniknout nad jedním logicky konzistentním pohledem.
Implementace může použít například:

- read-only repeatable-read snapshot omezený na dobu exportu strojových dat,
- nebo krátkou materializaci registrovaného tenantového grafu pod tenantovým
  write lockem.

Pomalé renderování PDF a sestav nesmí zbytečně prodlužovat databázový snapshot.
Strojová vrstva se uzavře jako první. Čitelná vrstva nese vlastní čas vytvoření
a může být generována následně.

Seznam souborů vzniká z konzistentního datového pohledu. Každá souborová oblast
má v registru politiku:

- required: chybějící nebo během čtení změněný soubor backup zastaví,
- historical_optional: již chybějící zdroj se uvede v manifestu a reportu,
- derived: soubor se znovu vytvoří nebo se bezpečně vynechá,
- unsupported: backup se zastaví.

Manifest musí odlišit soubor, který v tenantovi nebyl, od souboru, který měl
existovat, ale na disku chyběl.

Po vytvoření strojové vrstvy se nad ní ještě před zveřejněním archivu spustí
referenční, účetní a daňové kontroly. Neúplný nebo vnitřně rozporný balíček
nesmí dostat stav completed.

## TenantDataRegistry jako SSOT

Rozsah zálohy se nesmí odvozovat pouze z existence supplier_id. Některé řádky
jsou vlastněné nepřímo, některé tabulky jsou globální a některé soubory nemají
přímou databázovou vazbu.

Veřejně volatelný TenantDataRegistry je jediným zdrojem pravdy pro:

- úplnou zálohu firmy,
- obnovu jako nové firmy,
- užší profil účetního archivu,
- coverage a schema guardy,
- klasifikaci secret hodnot,
- post-import invarianty.

Každý databázový objekt, reference a souborová oblast má právě jednu politiku:

| Politika | Význam |
|---|---|
| tenant_root | kořen vybrané firmy |
| tenant_owned | přenést řádky a přemapovat ID |
| tenant_owned_indirect | vybrat přes deklarovaný vlastnický graf |
| tenant_relation | vytvořit z rozhodnutí obnovy, nevkládat raw |
| global_reference | mapovat na cílový záznam podle přirozeného klíče |
| instance_owned | nikdy nekopírovat jako tenantová data |
| protected_domain_secret | povinně přešifrovat přes secret envelope |
| optional_credential | přenést pouze po výslovném opt-in |
| personal_secret_attachment | jednotlivě, s vlastníkem a souhlasem |
| runtime_derived | vynechat a regenerovat nebo resetovat |
| unsupported | zastavit export i obnovu |

Deklarace popisuje také:

- vlastnický selektor a stabilní pořadí,
- primární klíč a strategii remapu,
- skutečné FK, polymorfní a soft reference,
- globální přirozené klíče,
- generované a odvoditelné sloupce,
- actor reference a jejich požadované mapování,
- secret politiku jednotlivých sloupců,
- souborové oblasti přes RuntimePaths,
- bezpečný stav po importu,
- invarianty konkrétní agendy.

Architekturní test porovná registr se skutečným schématem. Nová tenantová
tabulka, nový secret sloupec, neznámá reference nebo nová storage oblast musí
test i runtime preflight zastavit, dokud nedostane explicitní klasifikaci.

Default není exportovat. Default je odmítnout neznámý objekt.

## Kompatibilita napříč verzemi

### Formát a schema revision

Formát balíčku a databázové schéma jsou dvě různé osy:

- format major určuje, zda cíl umí balíček vůbec přečíst,
- format minor přidává zpětně kompatibilní metadata,
- schema revision popisuje logický tvar exportovaných dat,
- app version je diagnostika a pomáhá zvolit podporovanou cestu.

Vyšší format major, než cíl zná, se odmítne. Vyšší minor známého majoru se smí
přijmout jen tehdy, pokud neobsahuje neznámou povinnou capability.

### Bezpečně tolerantní změny

Bez samostatného upcasteru lze přijmout jen změny, které registr označí jako
bezpečné:

- nový nullable sloupec bez změny významu,
- nový sloupec s autoritativním cílovým defaultem,
- nová prázdná nebo odvoditelná tabulka,
- nový index nebo FK bez změny payloadu,
- sloupec deklarovaně nahrazený ekvivalentním cílovým defaultem.

Neznámá neprázdná tabulka, neznámý povinný sloupec, změna jednotek, rozdělení
jedné hodnoty do více tabulek nebo změna účetního významu vyžaduje upcaster.

### Explicitní upcastery

BackupUpcasterRegistry obsahuje pojmenované, směrové transformace mezi
logickými schema revisions. Upcaster:

- transformuje streamovaný logický payload, ne živé cílové schéma,
- nikdy nespouští migrate.php na používané databázi,
- je omezen na deklarovaný zdroj a cíl,
- popisuje ztrátovost a varování,
- má samostatný fixture a regresní test,
- nesmí mít obecný fallback.

Preflight sestaví celý řetězec upcasterů před zápisem. Chybějící článek nebo
ztrátová transformace bez výslovně podporované politiky obnovu zastaví.

Schema fingerprint může zůstat v manifestu pro diagnostiku. Nesmí nahrazovat
verzovaný formát, registry ani testovanou transformační cestu.

### Dlouhodobý kontrakt

Každý vydaný major formátu má v repozitáři minimálně jeden syntetický golden
fixture. CI obnovuje staré fixture do aktuální verze a ověřuje invarianty.
Current-to-current round-trip zůstává povinný, ale sám o sobě není důkazem
obnovitelnosti rok staré zálohy.

Změna majoru musí dodat buď čtečku předchozího majoru, nebo samostatný
podporovaný převodník. Čitelná vrstva zůstává dostupná standardními nástroji
nezávisle na životním cyklu strojového importéru.

## Heslo, šifrování a secrets

### Heslo konkrétní zálohy

Uživatel při vytvoření zadá a potvrdí heslo zálohy. UI jasně upozorní, že bez
hesla nelze balíček obnovit a MyÚčto jej neumí dodatečně zjistit.

Heslo:

- není součástí názvu, URL, logu, auditu ani manifestu,
- nesmí se předat workeru jako argument procesu,
- smí být pro background job krátkodobě uloženo pouze zašifrované aplikačním
  klíčem a musí se smazat po dokončení, zrušení nebo expiraci,
- v plaintextu existuje jen v paměti procesu, který jej právě používá,
- podléhá minimální síle a rate limitu při pokusech o odemčení.

Konfigurační cron.backup.password zůstává pro provozní cron zálohy. Není heslem
uživatelské přenositelné zálohy.

### Vnitřní secret envelope

Samotné šifrování ZIPu nestačí pro secrets. Po rozbalení do karantény by jinak
vznikly plaintext credentials.

Secret hodnoty jsou proto uloženy v samostatném autentizovaném envelope,
například s Argon2id a XChaCha20-Poly1305. Kryptografická sada, KDF parametry
a salt jsou verzované v bezpečné části manifestu.

Tok hodnoty je:

    ciphertext zdrojové instance
        → dešifrování zdrojovým klíčem pouze v paměti
        → zašifrování klíčem odvozeným z hesla zálohy
        → odemčení při obnově heslem zálohy pouze v paměti
        → zašifrování klíčem a kontextem cílové instance

Zdrojový app.secret_encryption_key, previous keys ani app.pepper se do balíčku
nikdy neukládají. Správně vytvořená záloha je při obnově nepotřebuje.

### Tři skupiny secrets

1. Chráněná doménová data

   Data šifrovaná kvůli ochraně at-rest, bez kterých by účetní, mzdový nebo
   provozní záznam nebyl úplný. Přenášejí se povinně a přešifrují přes envelope.
   Pokud je zdroj neumí dešifrovat, úplná záloha se nevytvoří.

2. Přenositelné credentials a firemní privátní klíče

   SMTP/IMAP hesla, bankovní mailbox, externí API klíče, TSA heslo a firemní
   podpisový PFX. Jsou volitelné a výchozí stav je nepřenášet. UI je vybírá po
   bezpečných skupinách nebo jednotlivě. Po obnově zůstávají příslušné
   integrace neaktivní.

3. Instanční a runtime tajemství

   Aplikační klíče, pepper, session a reset tokeny, OAuth cache, uživatelské
   TOTP a passkeys, licence, transfer granty a dočasné worker tokeny. Nikdy se
   nezálohují do přenositelného balíčku.

### Osobní certifikáty

Osobní P12/PFX jsou zvláštní volitelná příloha:

- výchozí stav je nepřenášet,
- vybírají se jednotlivě, nikdy celý trezor,
- musí být skutečně navázané na zálohovanou firmu,
- zdrojový vlastník udělí souhlas svázaný s konkrétní zálohou,
- při obnově se mapují na existujícího cílového vlastníka,
- cílový vlastník přijetí potvrdí vlastní step-up autentizací,
- bez obou rozhodnutí se credential přeskočí, ale čistá obnova firmy může
  pokračovat,
- reuse existujícího cílového credential nesmí přepsat jeho PFX, passphrase,
  label ani vazby k jiným firmám.

Osobní certifikáty mohou být samostatnou pozdější etapou za feature flagem.
Jádro formátu a registr ale musí jejich bezpečnou politiku znát od začátku.

## Uživatelský tok: vytvoření zálohy

Správce otevře Administrace → Záloha a obnova. Export musí mít vlastní
oprávnění omezené na aktuální firmu. Výchozí role jej udělí pouze správci
instalace a výslovně určenému správci firmy.

Průvodce:

1. ukáže firmu, rozsah strojové vrstvy a odhad velikosti,
2. ověří úplnost TenantDataRegistry, stav migrací, dostupnost souborů,
   dešifrovatelnost povinných secrets, kvótu a volné místo,
3. zobrazí známé historické chybějící soubory a bezpečná vynechání,
4. vyžádá step-up autentizaci a podle politiky MFA,
5. vyžádá heslo zálohy a jeho potvrzení,
6. nabídne čitelné části a časový rozsah pouze pro ně,
7. nabídne optional credentials a jednotlivé osobní certifikáty,
8. spustí background job a zobrazuje jeho průběh,
9. po dokončení nabídne ZIP, SHA-256 a datum automatického smazání serverové
   kopie.

Stažením se serverová kopie automaticky nemaže. Uživatel ji může odstranit
ručně; jinak se uklidí podle krátké konfigurovatelné retence. Heslo jobu je
vždy odstraněno už po vytvoření archivu.

Hotový archiv je po dobu retence neměnný. Download endpoint podporuje HTTP
Range a stabilní ETag odvozený z SHA-256, aby šlo několikagigabajtové stažení
bezpečně obnovit bez vytvoření nového exportu.

Browser nikdy nedostane plaintext secret hodnoty ani privátní klíče. Dostane
jen hotový zašifrovaný soubor.

## Uživatelský tok: obnova

Obnovu může spustit pouze superadmin cílové instance. Před vytvořením firmy se
ověří také licenční limit počtu firem a dostupné úložiště.

### 1. Upload

Uživatel vybere balíček. Velké soubory se nahrávají po blocích:

- každý blok má index, velikost a SHA-256,
- opakovaný blok je idempotentní,
- po reloadu se pokračuje prvním chybějícím blokem,
- server průběžně vynucuje limit komprimované i očekávané rozbalené velikosti,
- data leží pouze v karanténě přes RuntimePaths.

### 2. Odemčení a technická validace

Uživatel zadá heslo. Server ověří:

- formát a podporovanou verzi,
- integritu položek a celého uploadu,
- bezpečné cesty, počet položek, kompresní poměr a kvóty,
- product myucto,
- že cílová aplikace není starší než zdrojový kontrakt,
- dostupný řetězec upcasterů,
- úplnost registru a všechny povinné capabilities.

Chybné heslo má stabilní obecnou chybu bez oracle detailů a je rate-limitované.
Po dokončení technické validace server zahodí heslo i odvozené klíče; restore
job je neuchovává. Pro finální commit uživatel heslo zadá znovu.

### 3. Preflight bez business zápisu

Preflight načte a transformuje logický manifest, ale nevloží žádný řádek
obnovené firmy. Zobrazí:

- identitu firmy a čas snapshotu,
- zdrojovou a cílovou verzi,
- upcastery a jejich varování,
- počty dat a souborů,
- chybějící historické soubory,
- globální reference a jejich shody,
- actor reference vyžadující mapování,
- optional credentials a stav souhlasů,
- integrace, které zůstanou deaktivované,
- účetní, daňové, mzdové a skladové kontroly,
- odhad cílové velikosti.

Nová chyba nebo nové rozhodnutí po změně mapování zneplatní předchozí potvrzení.
Rozhodnutí jsou svázána s backup_id, hashem manifestu a cílovou instancí.

### 4. Mapování uživatelů a globálních referencí

Uživatelské účty, hesla, MFA ani passkeys se do používané cílové instance
nekopírují.

Zdrojová identita může nést bezpečná metadata pro mapování. Cíl může navrhnout
shodu podle ověřeného e-mailu, ale správce ji potvrdí. Povolená rozhodnutí jsou:

- mapovat na existujícího cílového uživatele,
- u nullable historické reference použít uživatele obnovy nebo null,
- u povinné reference obnovu zastavit, dokud není mapování doplněné.

Automatické vytvoření aktivního uživatelského účtu není dovoleno. Původní
identita a provedené mapování zůstanou v reportu obnovy.

Globální číselníky se mapují podle deklarovaného přirozeného klíče. Import je
nesmí přepisovat. Chybějící nebo nejednoznačná povinná shoda preflight zastaví,
pokud registr výslovně nepovoluje bezpečné vytvoření cílového záznamu.

### 5. Potvrzení a commit

Commit vyžaduje nový step-up, pokud od posledního ověření uplynul bezpečnostní
limit, a znovu vyžádá heslo zálohy. Uživatel potvrzuje:

- vznik nové firmy,
- deaktivované automatizace,
- všechna podporovaná vynechání,
- mapování uživatelů a globálních referencí,
- vybrané optional credentials.

Import:

1. vytvoří staging souborů mimo živé cesty,
2. v jedné databázové transakci založí nového supplier a přemapuje tenantový
   graf,
3. přešifruje secrets cílovým klíčem,
4. vynutí bezpečné post-import hodnoty,
5. ověří FK, soft reference a doménové invarianty,
6. přesune soubory do cílových cest bezpečně i na Windows,
7. zpřístupní firmu teprve po úspěchu všech kroků,
8. uloží neměnný report a audit.

Při chybě se databáze vrátí, staging se uklidí a nová firma se nezobrazí.
Opakovaný commit stejného restore jobu nevytvoří druhou firmu.

### 6. Dokončení

Výsledný report obsahuje:

- nové supplier_id,
- počty a hashe obnovených objektů,
- použitý řetězec upcasterů,
- mapování actorů a globálních referencí,
- vynechané soubory a credentials,
- seznam deaktivovaných integrací,
- výsledky účetních, daňových a souborových kontrol,
- checklist ručního ověření před zahájením provozu.

Zdrojová firma ani zdrojový soubor se nikdy automaticky nemažou.

## Obnova do čisté instalace a CLI

Stávající serverová obnova do prázdné databáze zůstává podporovaná pro správce
serveru. Parser manifestu, kontrola integrity, upcastery, secret envelope a
doménové invarianty ale musí sdílet s UI obnovou.

Čistá disaster-recovery obnova může zachovat původní ID a obnovit uživatele
zablokované, jak to dělá současný CompleteInstanceRestoreService. Obnova do
používané instance vždy používá remap a mapování na existující uživatele.

Rozdílné commit strategie nesmějí vytvořit dvě různé interpretace formátu.
Společná služba nejprve vytvoří kanonický importní plán a teprve potom zvolí:

- clean_instance_preserve_ids,
- existing_instance_remap_ids.

## Navržené komponenty

1. TenantDataRegistry
   - úplná klasifikace tabulek, souborů, referencí a secrets,
   - profily company_backup a accounting_archive,
   - coverage validátory.

2. CompanyBackupFormat
   - parser a validátor manifestu,
   - kanonický JSON a checksumy,
   - capability a version pravidla,
   - legacy adaptér pro současný instance export.

3. CompanyBackupExporter
   - konzistentní strojový snapshot,
   - čitelná vrstva nad stávajícími exportními službami,
   - password a secret envelope,
   - background job a retence.

4. BackupUpcasterRegistry
   - explicitní směrové transformace,
   - streamované řádkové adaptéry,
   - golden fixtures.

5. CompanyRestorePlanner
   - preflight bez business zápisu,
   - globální a actor mapování,
   - post-import rozhodnutí,
   - autoritativní importní plán.

6. CompanyRestoreImporter
   - remap ID a referencí,
   - staging souborů,
   - cílové přešifrování,
   - atomický commit a invarianty.

7. BackupUploadStore
   - resumable bloky,
   - kvóty a karanténa přes RuntimePaths,
   - idempotence a bezpečný úklid.

8. Webové UI
   - vytvoření, historie, download a smazání záloh,
   - upload, preflight, mapování, commit a report obnovy,
   - i18n v cs i en.

Doménová logika nesmí žít v API actions ani ve Vue komponentách. Action pouze
autorizuje, validuje transportní vstup a volá sdílenou službu.

## API a stavový model

Přesné cesty lze před implementací upravit. Lokální endpointy používají browser
session, CSRF, RBAC a step-up. Veřejný API token se odmítá.

Navržené skupiny:

- /api/admin/company-backups pro vytvoření, seznam, stav, download, cancel
  a delete,
- /api/admin/company-restores pro založení uploadu, stav, cancel a delete,
- /api/admin/company-restores/{id}/chunks/{index} pro resumable upload,
- /api/admin/company-restores/{id}/unlock pro odemčení a technickou validaci,
- /api/admin/company-restores/{id}/preflight pro plán bez business zápisu,
- /api/admin/company-restores/{id}/decisions pro mapování a opt-in,
- /api/admin/company-restores/{id}/commit pro atomickou obnovu,
- vlastnické consent endpointy pro jednotlivé osobní certifikáty.

Každá route a schéma se synchronizuje s api/openapi.yaml. Po změně se ověří
striktní parsování YAML, duplicitní klíče a dangling ref.

Stavy backup jobu:

    queued
        → checking
        → snapshotting
        → packaging
        → completed

    libovolný nedokončený stav
        → failed | cancelled | expired

Stavy restore jobu:

    created
        → uploading
        → uploaded
        → unlocking
        → preflight_required
        → decisions_required
        → ready
        → importing
        → succeeded

    libovolný nedokončený stav
        → failed | cancelled | expired

Chyby mají stabilní kód a bezpečná strukturovaná data. Nikdy neobsahují
plaintext secret, DB connection string, fyzickou cestu mimo bezpečný kontext
ani obsah osobního dokumentu.

## Oprávnění a audit

- Export vyžaduje samostatné high-risk oprávnění scoped na aktuální firmu.
- Restore a vytvoření nové firmy vyžaduje superadmina.
- Export, unlock, změna secret voleb a commit vyžadují účelově vázaný step-up.
- Osobní certifikát potvrzuje jeho skutečný vlastník, ne superadmin jeho jménem.
- Stahování je omezené na backup job aktuální firmy.
- Restore job není dostupný jinému uživateli bez příslušného instančního
  oprávnění.
- Citlivé operace mají rate limit.

Audit zaznamená nejméně:

- vytvoření, dokončení, stažení, zrušení a smazání zálohy,
- vytvoření uploadu, technickou validaci a preflight,
- změny mapování a opt-in rozhodnutí,
- commit, výsledek a nové supplier_id,
- udělení a odvolání souhlasu osobního credential.

Audit ukládá hashe a identifikátory, ne heslo ani secret hodnoty.

## Bezpečnostní požadavky

- Původní ani cílový aplikační klíč a pepper neopustí instalaci.
- Plaintext secrets nejsou v browseru, logu, progress API ani na karanténním
  disku.
- Chybné heslo nemá plaintext nebo nešifrovaný fallback.
- Import přijímá jen objekty deklarované v TenantDataRegistry.
- Všechny názvy tabulek a sloupců pro SQL procházejí allowlistem registru.
- ZIP validace odmítá absolutní cesty, traversal, nebezpečný casing, symlinky,
  device paths, duplicitní normalizované názvy a kompresní bomby.
- Path guardy porovnávají realpath case-insensitive.
- Limity pokrývají velikost uploadu, rozbalenou velikost, počet položek,
  jednotlivý soubor, řádky tabulek a dobu zpracování.
- Importovaný obsah nesmí změnit jinou firmu ani při záměrně kolidujících ID.
- Globální tabulky lze měnit jen přes explicitně povolené mapovací operace.
- Reuse credential je append-only vůči vazbě nové firmy.
- Dočasná data, odvozené klíče a zašifrované heslo jobu se uklidí po úspěchu,
  zrušení i expiraci.
- Backup a restore nesmí spouštět externí komunikaci ani automatizaci.
- Stažený soubor má Content-Disposition, no-store a X-Content-Type-Options.

## Post-import invarianty

Obnova nekončí posledním INSERTem. Před zpřístupněním firmy musí projít
minimálně:

- všechny databázové FK a registrované soft reference,
- tenantová izolace každého obnoveného objektu,
- počty a kontrolní součty proti manifestu,
- existence a SHA-256 povinných souborů,
- shoda součtů dokladů a jejich položek,
- zachování prices_include_vat,
- symetrie typů dokladů v DPH a daňových filtrech,
- správná období DPH a vazby oprav,
- nezdvojené zálohy a náklady,
- vyváženost účetního deníku po obdobích, tedy suma MD = suma D,
- vazby plateb, zápočtů, bankovních pohybů a pokladny,
- mzdové snapshoty, povinné dokumenty a připnuté legislativní podklady,
- skladové množství a referenční integrita pohybů,
- neaktivní stav všech externích automatizací,
- nezměněný snapshot ostatních firem a instančních dat mimo povolený audit,
  nový membership a schválené credential vazby.

Každá agenda přidává své invarianty přes volatelný registr. Nesmí vzniknout
jeden centrální switch, který se při každém novém modulu ručně kopíruje.

## Testovací plán

### Unit a architektura

- TenantDataRegistry pokrývá skutečné schéma, souborové oblasti, reference
  a secret sloupce.
- Neznámá tenantová tabulka, secret, soft reference nebo file area test rozbije.
- Manifest je kanonický a stabilní.
- Upcaster registry odmítne mezeru, obrácený směr a neznámou capability.
- Bezpečné additive změny a každý breaking upcaster mají vlastní test.
- Secret policy nedovolí přesun hodnoty mezi povinnou, volitelnou a zakázanou
  třídou bez explicitní změny fixture.
- Path, ZIP, kvóty, KDF, autentizace bloků a rate limit mají adversariální testy.

### Cross-version fixtures

- V repozitáři je syntetický balíček každého podporovaného majoru a významné
  schema revision.
- Aktuální aplikace každý fixture odemkne, upcastuje, obnoví a ověří.
- Záloha z novější verze do starší se odmítne.
- Neznámý neprázdný sloupec nebo tabulka se nezahodí tiše.
- Přidaný nullable nebo defaultovaný sloupec projde jen podle deklarované
  politiky.

### Integrace obnovy

- Použít dvě MariaDB, kolidující ID a nejméně dvě existující cílové firmy.
- Porovnat stav cizích tenantů a instančních tabulek před a po commitu.
- Ověřit obnovu stejného balíčku po selhání v každé hlavní fázi.
- Ověřit restart workeru, resume bloků, opakovaný blok a opakovaný commit.
- Ověřit rollback DB i souborů při chybě.
- Ověřit globální přirozené klíče, actor mapping a povinné unresolved odkazy.
- Ověřit stejné a rozdílné source a target app secret keys.
- U nového formátu nesmí být po obnově potřeba původní zdrojový aplikační klíč.
- Povinný protected secret projde round-tripem; poškozený blok obnovu zastaví.
- Nevybraný optional credential nevytvoří cílovou hodnotu.
- Osobní credential bez obou souhlasů nevytvoří vault řádek ani supplier vazbu.
- Integrace zůstanou deaktivované.

### Účetní a daňová správnost

- Ověřit sumu MD = suma D po období.
- Porovnat počty a součty vydaných i přijatých dokladů z položek.
- Ověřit prices_include_vat na všech cestách dokladů.
- Ověřit období DPH, dobropisy, § 46, § 74b, zálohy a finální doklady.
- Ověřit, že spárovaná či zaplacená nákupní záloha nevytvoří dvojí náklad.
- Ověřit guard amount_to_pay u pohledávek z finálních dokladů po proformě.
- Každý nalezený účetní či daňový problém má regresní test, u kterého bylo
  ověřeno selhání bez opravy.
- Spustit cmd/audit-gate.sh i odpovídající Windows variantu.

### UI a multiplatformnost

- Backup wizard, heslo, step-up, progress, download a retence.
- Resumable upload, odemčení, preflight, mapování, commit a report.
- Reload stránky a restart workeru v průběhu obou jobů.
- Mobilní layout, flex-wrap toolbarů a sémantické ikony a barvy akcí.
- České i anglické texty.
- Windows, Linux a Docker včetně rozdílného casingu cest.
- Velký syntetický archiv, přerušený resumable download i upload, pomalé
  spojení a nedostatek místa.

## Dokumentace po implementaci

Aktualizovat zejména:

- manual/88_Ucetni_nastroje.md,
- manual/91_Multi_supplier.md,
- manual/92_Nastaveni.md,
- manual/95_Elektronicke_podpisy.md,
- manual/97_Bezpecnost.md,
- manual/98_Aktualizace.md pro jasné odlišení provozního backupu,
- manual/999_Reseni_problemu.md,
- api/openapi.yaml.

Dokumentace musí popsat:

- rozdíl mezi zálohou firmy a celoinstančním disaster recovery,
- že obnova vždy vytváří novou firmu,
- požadavek na heslo a nemožnost jeho obnovy,
- podporovaný směr verzí,
- optional credentials a deaktivované integrace,
- postup kontroly SHA-256 a otevření čitelné vrstvy bez MyÚčta.

Po změně manuálu regenerovat pouze HTML. Po změně web/src doplnit cs i en
locale a spustit produkční frontend build.

## Vztah k PR #32

Z draftu https://github.com/radekhulan/myucto/pull/32 zachovat a přenést nad
aktuální master:

- TenantDataRegistry a coverage validátory,
- katalogy tabulek, referencí, souborů a secrets,
- zobecněný MfaProtectedOperationService,
- AccountingArchiveCatalog a sdílení archivního profilu,
- rozšířený ArchiveRestoreRoundTripTest,
- kanonizaci manifestových dat, pokud zůstane obecná.

Zaparkovat:

- TenantTransferGrant a jeho migraci,
- TenantTransferGrantMiddleware,
- capabilities endpoint,
- CompatibilityProfileRegistry postavený na identickém buildu,
- BuildRevisionProvider a build revision wiring,
- MigrationSetFingerprint jako blokující podmínku,
- inter-instance exportní API a SSRF řešení,
- transferová oprávnění a OpenAPI cesty bez využití v záloze.

Implementaci založit na aktuálním masteru. Celý starý transferový PR
nemergovat jako základ; vybrané části přenést po logických celcích a přejmenovat
z namespace TenantTransfer do obecné backup a restore vrstvy.

## Navržené implementační etapy

1. Registr a kontrakt
   - přenést a aktualizovat TenantDataRegistry,
   - sjednotit exportní a archivní katalogy,
   - zavést nový manifest a legacy adaptér,
   - doplnit coverage gate.

2. Konzistentní export
   - oddělit strojový snapshot od čitelného renderování,
   - přidat uživatelské heslo, KDF a secret envelope,
   - upravit stávající exportní UI a job.

3. Importní jádro
   - společný parser, preflight a BackupUpcasterRegistry,
   - úplný remap do nové firmy,
   - globální a actor mapování,
   - staging souborů, idempotence a rollback.

4. Restore UI
   - resumable upload,
   - odemčení, preflight, rozhodnutí, commit a report,
   - step-up, oprávnění, audit, kvóty a retence.

5. Optional credentials
   - přenositelné tenantové credentials,
   - firemní PFX,
   - jednotlivé osobní certifikáty s oběma souhlasy.

6. Kompatibilita a dokončení
   - golden fixtures starších verzí,
   - multiplatformní E2E,
   - účetní a daňové invarianty,
   - OpenAPI, manuál, i18n a observabilita.

Funkce zůstane za feature flagem, dokud úplný registrovaný tenant neprojde
current round-tripem, cross-version fixturem a testem obnovy do používané
instance s kolidujícími ID.

## Akceptační kritéria

- [ ] Správce vytvoří a stáhne zálohu jedné firmy kompletně v UI.
- [ ] Přenositelná záloha nikdy nevznikne bez uživatelského hesla.
- [ ] Čitelnou vrstvu lze po zadání hesla otevřít bez MyÚčta.
- [ ] Strojová vrstva vždy obsahuje úplný registrovaný tenant a konzistentní
      snapshot.
- [ ] Neznámý objekt, reference, secret nebo povinný soubor export zastaví.
- [ ] Záloha nového formátu ze stejné nebo starší podporované verze projde do
      aktuální verze.
- [ ] Záloha z novější verze do starší se odmítne před business zápisem.
- [ ] Žádná neprázdná tabulka nebo významný sloupec se tiše nezahodí.
- [ ] Obnova v UI vždy vytvoří novou firmu a přemapuje kolidující ID.
- [ ] Preflight nezapisuje do business tabulek.
- [ ] Jiná firma ani nepovolená instanční tabulka se při obnově nezmění.
- [ ] Povinná chráněná data nového formátu se obnoví mezi rozdílnými
      aplikačními klíči bez původního zdrojového klíče.
- [ ] Přenositelné credentials jsou defaultně vypnuté.
- [ ] Osobní credential se přenese jen jednotlivě po platných souhlasech.
- [ ] Všechny externí integrace a automatizace zůstanou deaktivované.
- [ ] Download a upload lze bezpečně navázat; preflight a commit jsou
      idempotentní a bezpečné po restartu.
- [ ] Chyba nezanechá viditelnou částečnou firmu ani osiřelé živé soubory.
- [ ] Účetní, daňové, mzdové, skladové a souborové invarianty projdou.
- [ ] Řešení je ověřené na Windows, Linuxu a v Dockeru.
- [ ] OpenAPI, obě locale a manuál odpovídají implementaci.

## Hlavní rizika

| Riziko | Mitigace |
|---|---|
| Tichá ztráta nové agendy | explicitní registr, default deny, CI coverage a runtime preflight |
| Starší záloha přestane fungovat | golden fixtures a explicitní upcaster chain |
| Dlouhý nekonzistentní export | oddělený konzistentní strojový snapshot a následná čitelná vrstva |
| Únik secretu | povinné heslo, vnitřní envelope, plaintext pouze v paměti |
| Závislost na původním klíči | dešifrování při exportu a cílové přešifrování při obnově |
| Poškození jiné firmy | nový supplier, remap, allowlist změn a snapshot před a po |
| Částečný import souborů | karanténa, staging, transakce, atomické zveřejnění a cleanup |
| Několikagigabajtový balíček | streaming, kvóty, resumable upload a kontrola volného místa |
| Duplicitní automatizace | vynucený disabled stav a ruční checklist |
| Zneužití osobního certifikátu | per-item výběr, source consent, target acceptance a owner mapping |

## Výchozí rozhodnutí pro první verzi

- Nový sémantický formát je myucto-company-backup v1.
- Stávající myucto-instance-export v3 až v5 je legacy vstup, ne nový writer.
- UI obnova vždy vytváří novou firmu.
- Strojová vrstva je úplná a bez časového filtru.
- Čitelná vrstva může být omezena obdobím.
- Heslo zálohy je povinné a není odvoditelné z konfigurace serveru.
- Starší → novější je podporovaný směr; novější → starší je zakázaný.
- Přebývající neprázdná data se bez explicitního upcasteru nezahazují.
- Protected domain secrets jsou povinné, credentials opt-in, runtime secrets
  zakázané.
- Integrace se vždy obnovují deaktivované.
- Transport je upload souboru; server-to-server přenos se neimplementuje.
- Jeden logický balíček se přenáší resumable bloky; multi-volume není podmínkou
  první verze.
