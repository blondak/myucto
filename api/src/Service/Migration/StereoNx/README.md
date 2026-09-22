# Stereo NX — příprava převodu daňové evidence

Zdroj čte `blondak/nx1-reader` připnutý v Composer lockfile. Firemní zálohy ani dekódované obchodní řádky nepatří do repozitáře.
Výchozí heslo formátu je na výslovný požadavek provozovatele součástí
serverového adaptéru `StereoNxBackupPassword` v XOR podobě; obfuskace jej
nechrání před čtenářem zdrojového kódu. Do prohlížeče se neposílá. Čtení archivu je
přímé, bez extrakce na disk. Testovací ZIPy obsahují pouze syntetická data.

## Implementováno

- `StereoNxManifest`: seznam firem z `ObsahBck.txt`, UTF-8 / Windows-1250,
  explicitní vazba číselného indexu na `Firma_<index>/`. Poslední pole záznamu
  se uchovává jako neprůhledný identifikátor, není považováno za IČO.
- `StereoNxBackup`: kontrola limitů ZIPu, cest, duplicit bez rozlišení velikosti
  písmen a výběru firmy. Inventura čte každý řádek každé firemní tabulky a
  porovnává počet s deklarovaným počtem. Chyba není prázdná tabulka.
- `StereoNxPaymentReconciliation`: domácí úhrady přes explicitní dvojici
  `DoklSRada` + `DoklSCislo`; kontrola osiřelých a neúplných vazeb, směru a
  částek. Cizoměnové vazby se označují jako neověřené. Nepoužívá heuristiku
  podle variabilního symbolu ani data splatnosti.
- `StereoNxCompanyMetadata`: strukturální čtení ověřeného formátu `firma.bin`
  s hlavičkou TPF0 a verzí 251. Vrací pouze IČO, DIČ, název a plátcovství;
  žádné hledání identifikátorů v osobních údajích nebo volném textu.
- `StereoNxVat`: převádí řádky přiznání z `Lsdph` přes čistý veřejný
  klasifikátor `PremierVat`; názvy a zkratky členění nejsou daňovou autoritou.
- `StereoNxPurchaseRecap`: plán náhradních přijatých položek po sazbách,
  s původním textem, režimem cen, dodavatelskou daní, samovyměřením a explicitním
  zaokrouhlením. Neurčené příznaky mají `requires_draft: true`; samovyměření
  nemění závazek vůči dodavateli. Výchozí sazby se nedosazují.
- `api/bin/stereo-nx-inspect.php`: strojově čitelný přehled schématu, počtů
  a kontroly úhrad. Volba `--purchases` přidá souhrn plánování přijatých
  rekapitulací bez řádkových firemních hodnot. Neotevírá spojení s aplikační
  databází.

- `StereoNxSourcePlan` a `StereoNxIssuedDocuments`: úplný plán podporovaných
  agend, historické údaje protistran, kontrola saldokonta a peněžního deníku,
  roční složené klíče a důvody konceptů. Naplněné neověřené agendy převod blokují.
- `StereoNxImporter`: transakční zápis do existujícího plátce DPH v režimu
  `tax_evidence`, shoda IČO, kontrola uzávěrek a naplněných období. Zkouška
  nanečisto provede stejný zápis a rollback. Trvalá mapa hlídá opakování a změny
  zdroje. Platby mají fyzickou bankovní/pokladní vazbu; koncepty zůstávají mimo DPH.
- `StereoNxMigrationAction`, `StereoNxUploads` a průvodce v `web/src/pages/imports`:
  upload po částech, výběr firmy, kontrolní běh a převod. Úspěšná zkouška je
  vázaná na archiv, firmu a výklad prázdné země. Nahrané archivy jsou omezené
  na tenanta i uživatele a mají časově omezenou retenci.

CLI inspektor je stále pouze kontrola zdroje: `ready_for_import: false` a
`mode: source_inspection`. Databázovou zkoušku provádí průvodce v aplikaci.

## Zdrojové tabulky a ověřované vazby

| Oblast | Tabulky | Vazba / poznámka |
|---|---|---|
| Firmy | `ObsahBck.txt`, `LFirma`, `LFirmaUc` | `LFirma` obsahuje i přístupové údaje; nikdy nedumpovat celé řádky do reportů |
| Adresář | `LAdresy`, `LAdruct` | kandidát identity `Firma`; snapshoty dokladů ponechat historické |
| Vydané doklady | `Svfh`, `Svfp` | hlavička a položky: `DoklSRada`, `DoklSCislo` |
| Přijaté doklady | `SPFH`, `Spfp` | tabulka položek může být prázdná i při existujících hlavičkách |
| Pohledávky/závazky | `Cpz`, `CPZZ` | `Cpz.Uhrazeno`, nikoli samotné `UhrazenoVse`, pro kontrolu úhrad |
| Banka | `CBanka`, `CBankap` | výpis/položky `DoklRada`, `DoklCislo`; vazba platby na doklad přes `DoklSRada`, `DoklSCislo` |
| Pokladna | `CPokl`, `CPoklSD` | rozlišit samotný peněžní pohyb a rozpis složeného dokladu |
| Peněžní deník | `Cdenik` | obsahuje projekci banky/pokladny; nepřidávat jako další fyzické platby |
| Druhy a sloupce | `Ldruhy`, `LSloupce` | `Sloupec`, `Typ`; význam kategorií potvrdit před překladem do MyÚčta |
| Členění DPH | `Lsdph`, `ZAZPVDPH` | číselník a zdrojová kontrolní evidence; cílové DPH přes `VatLedgerService` |
| Majetek/mzdy/sklad | `J*`, `M*`, `S*` | prázdná tabulka nepotvrzuje správnost budoucího mapování naplněné tabulky |

Kontrola úhrad porovnává součet `CBankap.Castka` a `CPokl.Castka` navázaných
na doklad s `Cpz.Uhrazeno`. Směr musí odpovídat, měna dokladu musí být CZK
(`Kč` nebo `CZK`) s jednotkovým kurzem. Převody cizích měn ani úhrady z jiných
agend tato kontrola nedopočítává. Neshoda se nesmí obejít dosazením nuly.

## Hranice převodu

Import podporuje domácí doklady CZK v daňové evidenci plátce. Podvojné
účetnictví, majetek, mzdy, sklad, zálohy a cizí měny nejsou automaticky
převáděny; naplněná nepodporovaná agenda nebo neověřený druh dokladu zastaví
celý běh. Vlastní řádky `Spfp` potřebují samostatné mapování; existující
položky se nesmějí nahrazovat rekapitulací.

Výklad prázdné země jako ČR je explicitní volbou průvodce. Označení EU bez
konkrétního státu zůstává neurčené. Kvůli povinnému cílovému `country_id`
mají takové protistrany technický zástupný stát CZ, poznámku a všechny
jejich doklady stav koncept; stát musí uživatel před potvrzením opravit.
Neurčené příznaky DPH/cen, chybějící údaje a neshody zdrojových evidencí se
zachovávají jako důvody ruční kontroly, nikoli jako ověřené daňové údaje.

Dokladové DPH zpracovává stávající `VatLedgerService`; import nevytváří
vlastní vedlejší evidenci. `Cdenik` kontroluje banku a pokladnu, nepřidává
virtuální úhrady. Nulový počáteční záznam pokladny se eviduje v mapě převodu
bez vytvoření nepovoleného nulového pokladního dokladu.

## Ověření

Syntetická fixture `SyntheticNx1Archive` skládá skutečný NX!2/DICT/NXDH
formát a šifrovaný ZIP. Integrační test `StereoNxImportTest` prochází čtením,
HTTP akcemi a zápisem do izolované DB, zkouškou nanečisto, opakováním,
kontrolou DPH a peněžního deníku. Reálná záloha do testů nepatří.
