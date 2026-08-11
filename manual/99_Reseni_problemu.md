# 99. Řešení problémů (FAQ)

## 99.1 Přihlášení

### Zapomenuté heslo

Klik **Zapomenuté heslo?** na login → zadej e-mail → klik na odkaz v e-mailu
(platnost 1 h).

Pokud e-mail nedorazí:

- Zkontroluj spam.
- Ověř s adminem, že máš nakonfigurované SMTP (`cfg.php → smtp.*`).
- Krajní řešení: admin spustí `php api/bin/set-password.php tvuj@email.cz`.

### „Origin nesedí s app URL"

CSRF check selhal. Příčiny:

- **`cfg.php → app.url`** nesedí s URL, na kterou chodíš. Příklad: chodíš na
  `http://localhost:8080`, ale v cfg je `https://dev.example.com`. Oprav v cfg.
- Reverse proxy / IIS bez správně nastaveného Host headeru. Zkontroluj,
  že server vidí původní hostname.
- **Docker setup z jiného hostu než `localhost`** (např. LAN IP serveru
  `http://10.0.0.8:8080`). First-run setup je z libovolného hostu povolen
  a `app.url` se uloží automaticky podle URL, kterou v setup wizardu použiješ.
  Alternativa: spusť kontejner s `-e MYINVOICE_APP_URL=http://10.0.0.8:8080`,
  nebo si po `docker run` uprav `cfg.php` přímo v kontejneru.

### „Aplikace ještě není inicializována" (HTTP 423)

Setup wizard ještě neproběhl. Otevři `/setup` v prohlížeči.

Pokud setup wizard nefunguje (špatně nakonfigurovaná DB):

```bash
php api/bin/migrate.php --status     # zkontroluj, že DB má migrace
php api/bin/setup.php                # interaktivní fallback z CLI
```

### Lockout po brute-force

Po 10 neúspěšných pokusech / 15 min jsi zablokovaný na 15 min. Po 30 / hod
na 24 h. Počkej, nebo požádej admina o reset z DB:
`DELETE FROM login_attempts WHERE bucket_key LIKE '%tvuj_email%';`

### Passkey nefunguje nebo se nezobrazuje systémový dialog

Zkontroluj:

- aplikaci otevíráš přes přesný hostname z `cfg.php → app.url`,
- v produkci používáš důvěryhodné HTTPS; pro lokální vývoj je povolené pouze
  `http://localhost`,
- prohlížeč a zařízení podporují WebAuthn a mají nastavený zámek obrazovky,
- dialog spouštíš explicitním tlačítkem na viditelné stránce.

Když se po kliknutí nic neděje a stránka vypadá zatuhle, čeká se na systémový
dialog, který se nemusel zobrazit — otevřel se za oknem prohlížeče, na jiném
monitoru, nebo si volání převzal správce hesel (Keeper, 1Password, Bitwarden)
a jeho okno se nevykreslilo. Po několika sekundách se dole objeví panel
**Čekám na potvrzení bezpečnostního dialogu** s tlačítkem *Zrušit čekání*;
tím se akce ukončí hned a jde ji zopakovat nebo přepnout na TOTP. I bez zásahu
se čekání samo ukončí po ~2 minutách chybovou hláškou. Když panel hlásí, že
WebAuthn obsluhuje rozšíření, zkus ho pro tuto doménu vypnout.

Passkey registrovaná na starém hostname nebude po změně domény fungovat.
Přihlas se pomocí TOTP nebo jiné passkey dostupné pro původní origin a
zaregistruj nový klíč. Pokud žádná recovery cesta nezůstala, použij CLI rescue
níže.

Přihlášený správce uvidí neplatnou WebAuthn konfiguraci také jako provozní
upozornění na stránce **Administrace → Aktualizace**. Běžný login heslem a TOTP
zůstává dostupný, dokud se `app.url` neopraví.

### Odemčení PWA selže nebo je zařízení offline

Odemčení vyžaduje spojení se serverem pro vydání a ověření jednorázové
challenge. Zrušení dialogu, neplatná passkey nebo offline stav ponechá session
zamčenou. Zkus připojení a akci opakuj. Případně zvol **Přihlásit se znovu**;
aplikace nejprve bezpečně ukončí zamčenou session a pak provede celý login.

Rozpracovaný formulář zůstane zachovaný jen dokud stránka zůstává v paměti.
Pokud Android stránku ukončil, neuložená data nelze ze zámku obnovit.

### Ztratil jsem passkey nebo TOTP zařízení

Použij jinou passkey, TOTP nebo jeden z dříve uložených **záložních kódů**.
Každý záložní kód funguje jen jednou; po přihlášení zkontroluj zbývající počet
a s funkčním silným faktorem si v profilu případně vygeneruj novou sadu.

Pokud není dostupný žádný silný faktor ani záložní kód, správce spustí CLI
obnovu:

```bash
php api/bin/reset-mfa.php tvuj@email.cz
```

Reset vypne TOTP, odvolá passkeys, smaže důvěryhodná zařízení, čekající
ověřovací procesy i záložní kódy a invaliduje všechny session. Detail včetně
Docker příkazů je v [§ 76.2.4](76_Bezpecnost.md#7624-obnova-pristupu). Neupravuj
jen sloupce TOTP ručně v databázi: ponechal bys aktivní další faktory a session.

### Varování `secret_encryption_key` (špatná délka klíče)

Backend vrací v `GET /api/health` pole `warnings[]` a admin vidí
upozornění i v UI (**Systém → Aktualizace**), pokud je problém s
`app.secret_encryption_key` (typicky omyl: 24B místo 32B).

Oprav konfiguraci v `cfg.php` / `cfg.docker.php`:

```bash
openssl rand -base64 32
```

Vygenerovanou hodnotu ulož do `app.secret_encryption_key`. Klíč musí být
base64, který po dekódování dává přesně 32 bajtů.

### Varování `mfa_methods_configuration`

V `auth.allowed_mfa_methods` (nebo `MYINVOICE_AUTH_MFA_METHODS`) je neznámá
hodnota. Podporované jsou pouze `passkey` a `totp`; e-mailové OTP sem nepatří —
zapíná se přes `auth.email_otp.enabled`. Aplikace kvůli tomu nespadne, jen
dočasně jede na výchozím seznamu `['passkey', 'totp']`. Oprav seznam v `cfg.php`.

### Varování `session_lock_without_unlock_method`

`session.lock_after_minutes` je kladné, ale někteří aktivní uživatelé nemají
passkey. Zamčenou session jde odemknout **jen passkey**, takže se z ní dostanou
pouze odhlášením (a přijdou o rozepsaný formulář). Buď jim registruj passkey
(**Profil → Přístupové klíče**), nebo nastav `session.lock_after_minutes = 0`
a nech volbu intervalu na jednotlivých uživatelích.

### Varování `session_lock_configuration`

`session.lock_after_minutes` není celé číslo 0–1440. Výchozí automatický zámek
je proto vypnutý; osobní intervaly uživatelů platí dál.

## 99.2 Faktury

### Nemůžu editovat vystavenou fakturu

Schválně. Vystavená faktura je **immutable** (snapshot dodavatele, klienta,
banky). Pokud potřebuješ změnu:

- **Drobná chyba (překlep, špatná částka)** → admin: detail faktury → klik
  **Editovat (force)**, vyžaduje admin roli, zaloguje se v activity logu.
- **Klient ji ještě nedostal** → udělej **Storno** (interní) + nová.
- **Klient ji už dostal** → udělej **Dobropis** (oficiální oprava) + nová.

### Klonování / „Vystavit znovu" inkrementuje měsíc špatně

Inkrement funguje pro popisy obsahující vzor `M/YYYY` (např. „Konzultace
3/2026" → „Konzultace 4/2026"). Pokud máš vzor jiný (např. „březen 2026"),
musíš ručně.

### QR platba se na PDF nezobrazuje

Bankovní účet musí projít **mod-11 kontrolou** (CZ účty) nebo **IBAN
checksum** (EUR). Zkontroluj v **Systém → Číselníky → Měny**, jestli máš
platný účet. Příklad platného CZ testovacího účtu: `1000000005 / 0100`.

### Faktura má v PDF špatné údaje dodavatele

Vystavená faktura má snapshot v `supplier_snapshot` (JSON). Pokud jsi po
vystavení změnil údaje dodavatele (logo, adresa, …), faktura zůstává
s původními. **Toto je zamýšlené** — vystavený doklad nelze měnit.

Pokud potřebuješ regenerovat PDF s novými údaji (např. opravil jsi překlep
v názvu firmy), použij **Editovat (force)** s admin rolí.

## 99.3 E-maily

### Faktura odešla, ale klient ji nedostal

1. Zkontroluj v **Systém → Activity log** záznam `invoice.sent` — měl by být
   s adresou klienta.
2. Zkontroluj log SMTP serveru (mailhog / SMTP relay).
3. Klient: zkontroluje spam.
4. Pošli **Test odeslání** na svůj e-mail — pokud nedorazí, problém je v SMTP
   konfiguraci.

### „Test odeslání" funguje, ale klientovi nic nechodí

- E-mail klienta v MyÚčtu je špatný (typo) → uprav v detailu klienta.
- Klient má restriktivní spam filtr → zkontroluj, jestli máš správně
  nastavený SPF + DKIM + DMARC pro doménu, ze které posíláš.

### DKIM podpis se nedaří aktivovat

1. Vygeneruj klíče: viz [§ 76.8](76_Bezpecnost.md#768-dkim-podpis-e-mailu).
2. Publikuj DNS TXT — počkej 5–60 minut na propagaci.
3. Ověř DKIM přes [mxtoolbox.com](https://mxtoolbox.com/dkim.aspx).
4. Až DNS funguje, zapni v `cfg.php → smtp.dkim.enabled => true`.

## 99.4 Banka

### GPC výpis se nenahraje („tento výpis už byl importovaný")

SHA-256 hash souboru se shoduje s nějakým dříve importovaným. Buď:

- Skutečně už je naimportovaný (zkontroluj **Peníze → Bankovní účty**, záložka Bankovní výpisy)
- Stáhl jsi stejný výpis 2× → použij jiný (nebo si vyžádej z banky export
  s jiným časovým rozsahem)

### PDF výpis se nenahraje nebo nesedí zůstatek

PDF import je deterministický a podporuje aktuální rozvržení výpisů **Banky
CREDITAS, ČSOB a KB**. Naskenovaný obrázek bez textové vrstvy, PDF jiné banky
nebo nové neznámé rozvržení se neodhaduje pomocí AI a import se odmítne.

- Ověř, že jde o originální PDF stažené z bankovnictví, ne tisk do PDF nebo scan.
- Zkontroluj, zda hlavička obsahuje číslo účtu, období, počáteční a konečný
  zůstatek.
- Pokud součet transakcí nesedí na zůstatky na haléř, systém výpis neuloží.
  Nestvrzuj chybějící pohyby ručně; stáhni úplný výpis za stejné období.
- U neznámé varianty rozložení přilož k hlášení anonymizovaný vzor bez citlivých
  údajů nebo přesný popis banky a rozložení. Originál s čísly účtů neposílej do veřejného issue.

### Auto-matching nefunguje

- Otevři **Všechny pohyby** nebo detail výpisu a rozbal důvody skórovaného
  návrhu. Bez VS může pomoci číslo faktury ve zprávě, zbývající částka, název,
  datum nebo dříve potvrzený účet protistrany.
- Automatická shoda vyžaduje nejméně 85 %, deterministický signál a náskok 15
  procentních bodů. Kandidát od 35 % se jen nabízí; slabší se nezobrazuje.
- Nový účet protistrany se stane důvěryhodným až po třech bezchybných ručních
  shodách. Chybné párování zruš — tím se účet znovu nepoužije naslepo.
- Částka neodpovídá (částečná platba, přeplatek, kurz nebo bankovní poplatek) →
  potvrď ruční/rozdělené párování a zkontroluj vzniklou alokaci.
- Faktura je v jiné měně než platba (klient pošle EUR na CZK fakturu) →
  manuálně, doúčtuj kurzový rozdíl

Překlep ve VS, přeplatek, poplatek, rozdílná měna a zálohová faktura se nikdy
nepotvrdí automaticky, i kdyby ostatní signály byly silné.

### Pohyb není v „K zaúčtování"

Záložka **K zaúčtování** je pracovní fronta, ne úplný archiv. Pohyb najdeš v
top-level záložce **Všechny pohyby**, která zahrnuje i zaúčtované a ignorované
transakce napříč výpisy. Pokud pro nezaúčtovaný pohyb nevznikl vůbec žádný
návrh, objeví se také v **Účetnictví → K doúčtování** s důvodem „bez pravidla“
nebo „nepodporovaná cizí měna“.

### Vlastní převod se nespároval nebo nezaúčtoval přes 261

- Oba účty musí být v nastavení banky evidované jako vlastní účty stejné firmy.
- Automaticky se zpracují jen převody ve stejné měně. Převod mezi CZK a EUR je
  kvůli kurzu a kurzovému rozdílu ruční.
- V nastavení automatiky musí být povoleny jak **Převody mezi vlastními účty**,
  tak **Rozpoznávání vlastních převodů**.
- Druhá noha může přijít v jiném výpisu nebo období. Do té doby je zůstatek 261
  legitimně „na cestě“; nevytvářej duplicitní ruční zápis.

### Odvod finančnímu úřadu nebo pojišťovně čeká na potvrzení

Rozpoznání účtu u ČNB/0710 samo nestačí. Automatické zaúčtování odvodu je
povolené jen proti existujícímu zaúčtovanému předpisu a nejvýše do jeho
kreditního zůstatku. Nejdříve zaúčtuj předpis daně, sociálního či zdravotního
pojištění. Nejasný VS, neznámé předčíslí nebo nedostatečný zůstatek ponechá
položku v Automatu k ruční kontrole.

### Bankovní účet z výpisu „nepatří aktuálnímu dodavateli"

Multi-supplier ochrana — výpis musí být z účtu, který je v **Systém →
Číselníky → Měny** aktuálního dodavatele. Pokud chceš nahrát výpis pro jiného
dodavatele, **přepni na něj** přes přepínač v horní liště.

## 99.5 Exporty

### ISDOC import do Pohody hodí chybu

ISDOC je univerzální standard, ale Pohoda má vlastní quirks. Doporučujeme
spíš **Pohoda XML export** (nativní formát), pro kterého je import
spolehlivější.

### Pohoda XML import vyžaduje kódy

Před exportem nastav v **Systém → Číselníky → Dodavatelé → [tvůj] → záložka Pohoda**:
číselnou řadu, středisko, činnost, předkontace. Bez toho Pohoda hlásí varování
při importu.

### Měsíční PDF ZIP je velký (>100 MB)

Normální při ~100 fakturách/měsíc s 2. stranou výkazu. Pokud chceš menší ZIP,
exportuj jen menší rozsah období (1 týden místo měsíce).

## 99.6 Cron / automatika

### Cron upomínek odeslal víc upomínek za den

Buď cron je spuštěný 2× (zkontroluj `crontab -l` / Task Scheduler), nebo
`--cooldown` je moc krátký. Default 14 dní by neměl pouštět více než 1 upomínku
na fakturu / 14 dní.

### Bank scan cron neimportuje nové výpisy

1. Zkontroluj, že soubory v `private/bank-incoming/` mají správný formát
   (ABO/GPC nebo podporované PDF; jiné XML ani scan PDF se neimportují).
2. Zkontroluj práva služby nad `private/bank-incoming/` a
   `private/bank-archive/`. Na Windows ověř identitu IIS/Task Scheduleru, na
   Linuxu vlastníka a oprávnění adresářů.
3. Spusť ručně `php api/bin/cron-bank-scan.php` a zkontroluj konkrétní chybu.

### „K doúčtování“ není prázdné, ale Automat ano

To je očekávané. **Automat** zobrazuje návrhy a blokace automatizačního motoru.
**K doúčtování** navíc inventarizuje bankovní pohyby bez jakéhokoli návrhu,
nezaúčtované vydané/přijaté doklady a otevřené žádosti o dokument. Otevři akci
na řádku; společná fronta je read-only a sama zápis nevytváří.

### V reportu Úplnost dokladů chybí nebo přebývá položka

Report vychází z aktuálních vazeb. Bankovní pohyb zmizí po doložení a párování
nebo po vzniku aktivního bankovního zápisu; stornovaný zápis se za aktivní
nepočítá. Zkontroluj nastavený práh dnů a směr příchozí/odchozí. Druhá část
reportu vychází ze saldokonta 311/321 a ukazuje jen doklady po splatnosti s
nenulovým zůstatkem, nikoli všechny faktury ve stavu „nezaplaceno“.

### Valutová pokladna nenabízí úhradu faktury nebo převod

Není to chyba oprávnění. Valutová pokladna podporuje PPD Prodej/Ostatní a VPD
Nákup/Ostatní s kurzem a CZK protihodnotou. Úhrada cizoměnové faktury přes
311/321 a valutový převod přes 261 nejsou podporované a systém je
záměrně blokuje. Proveď doložený ruční zápis v deníku. V daňové evidenci je
pokladna pouze korunová.

### AI kontace nic nenavrhla

Ověř zapnutí AI asistence pro daný typ, přihlašovací údaje poskytovatele,
potvrzenou DPA, rezidenční politiku a denní limit. Nepoužitelná odpověď levného
modelu může být jednou zopakována silnějším modelem; pokud ani ta neprojde,
položka zůstane ruční. AI nikdy nezaúčtuje položku sama. Pokus a jeho výsledek
jsou uložené v auditní stopě návrhu.

### Úplné mzdy zastavily výpočet v ruční kontrole

Úplné mzdy jsou testovací alfa. Stav **Ruční kontrola** je bezpečnostní výsledek,
ne technická porucha: pro rozhodné datum může chybět účinný a odborně schválený
ruleset, úplný personální podklad nebo podporovaný scénář. V **Mzdy →
Legislativní pravidla** ověř období účinnosti a stav všech dotčených oblastí;
v detailu revize potom projdi konkrétní blokery. Chybějící rok se nesmí nahradit
nejbližší sadou pravidel. Výsledek neopravuj ručním přepsáním vypočtených částek
a nepoužívej jej jako jediný podklad pro výplatu nebo podání.

### Odkaz do EPO po otevření zmizel

To je očekávané. Handoff URL je jednorázová a portál ji spotřebuje prvním
otevřením; MyÚčto ji proto podruhé nenabídne. Pokud jsi podání v otevřeném okně
nedokončil, v detailu snapshotu vytvoř **nový odkaz EPO**. Nový odkaz sám nic
neodesílá. Úspěšné otevření formuláře také není důkaz podání — rozhoduje až
potvrzení podatelny nahrané nebo převzaté do archivu.

## 99.7 Výkon

### Dashboard se otevírá pomalu

Stats cache možná chybí. Spusť `php api/bin/recompute-stats.php` — přepočítá
`project_revenue_cache` + `client_revenue_cache`.

### Aplikace pomalu reaguje pod zátěží

- Zapni Redis (`cfg.php → redis.enabled => true`) — rate limiting, brute-force
  ochrana a aplikační cache pak používají paměť místo DB
- Zkontroluj `cfg.php → app.debug => false` v produkci (debug logs jsou
  drahé)
- Sledování v `log/app-YYYY-MM-DD.log` (pomalé queries = `slow_query` v DB)

## 99.8 Multi-supplier

### Po přepnutí dodavatele vidím prázdný seznam klientů

Klienti jsou per-dodavatel izolovaní. Buď přepneš zpět na původního, nebo si
v aktuálním dodavateli vytvoř klienty znovu (nelze migrovat klienta mezi
dodavateli — záměrně).

### Faktura mi nešla vystavit, hlásí „klient nepatří aktuálnímu dodavateli"

Multi-supplier guard. Buď přepni na dodavatele klienta, nebo si v aktuálním
vytvoř toho samého klienta (oddělená data).

## 99.9 Hlášení chyb

Pokud problém nevyřeší tato kapitola, kontaktuj:

- **GitHub Issues** repo MyÚčto.cz
- E-mail vývojáře — viz `cfg.php → smtp.from`
- IT administrátor tvé organizace

Užitečné pro hlášení:

- Označení běžícího sestavení (zobrazí `/api/health`)
- Browser / OS
- Krok-po-kroku, jak chybu reprodukovat
- Screenshot
- Excerpt z `log/app-YYYY-MM-DD.log` v okolí chyby
