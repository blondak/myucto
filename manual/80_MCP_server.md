# 80. MCP server (napojení AI asistenta)

MCP server propojí **AI asistenta** — Claude, ChatGPT přes Codex, Gemini,
Copilota — s daty tvé firmy. Po zprovoznění se ptáš běžnou češtinou
(„kolik zaplatíme na DPH“, „kdo nám dluží“, „jaký byl loni zisk“) a asistent
si sám vybere správný nástroj a zavolá ho přes [REST API](78_API.md).

Nastavení najdeš v aplikaci: **Firma → MCP server**. Ta stránka ukazuje adresu
API konkrétně tvojí instance a hotovou konfiguraci pro vybraného asistenta.

## 80.1 Co je MCP

**Model Context Protocol** je otevřený standard pro připojení nástrojů k AI
modelům. Server je malý program, který běží u tebe na počítači, mluví s aplikací
přes REST API a asistentovi nabízí sadu pojmenovaných **nástrojů**
(`list_unpaid_invoices`, `vat_return_preview`, `trial_balance`, …).

Podstatné vlastnosti:

- **MCP server nevytváří vlastní kopii dat.** Běží lokálně a volá přímo tvoji
  instanci. Výsledek nástroje ale dostane připojený AI asistent a může jej podle
  svého provozního modelu odeslat poskytovateli AI. Citlivost dotazu proto
  posuzuj stejně jako při ručním vložení údajů do daného asistenta.
- **Asistent má jen to, co má token.** Rozsah, vazba na firmu, omezení podle IP
  i oprávnění role uživatele platí beze změny.
- **Všechno je vidět v logu.** Každé volání se zapíše včetně názvu nástroje.

## 80.2 Rozsah — co asistent umí

| Oblast | Rozsah |
|---|---|
| Fakturace | čtení, vystavování, odesílání, evidence úhrad, upomínky |
| Odběratelé | vyhledání, založení a úprava karty, dotažení údajů z ARES |
| Výkazy práce a materiálu | přidání a odebrání řádků u konceptu faktury, automatická hodinová sazba |
| Pohledávky a závazky | zaplacené / nezaplacené / po splatnosti, stáří pohledávek |
| Daně | odhad DPH za měsíc i kvartál, kontrolní a souhrnné hlášení, daň z příjmů, daňový kalendář — **jen čtení** |
| Účetnictví | obratovka, rozvaha, výsledovka, hlavní kniha, saldo, deník — **jen čtení** |
| Statistika | tržby, zisk, trendy, top odběratelé a dodavatelé, cash flow, platební morálka, koncentrace, riziko odchodu |
| E-shop a sklad | zboží, kategorie, výrobci, ceny, zásoby, dostupnost, ocenění |
| Hledání | globální vyhledávání napříč odběrateli a doklady |

> [!IMPORTANT]
> **Do účetnictví a daní asistent nezapisuje.** Zaúčtovat doklad, uzavřít období,
> zaevidovat opravu podle § 46 / § 74b ani odeslat podání na EPO nemůže. Je to
> agenda s daňovou odpovědností, kde chyba znamená opravné podání — dělá ji člověk
> v aplikaci. Zákaz vynucuje server, ne jen MCP: i token s právem zápisu dostane
> na takovou operaci `403 token_write_forbidden` (viz [kapitola 78.6](78_API.md#786-scopes)).

## 80.3 Zprovoznění

### Krok 1 — API token

V **Firma → API tokeny** vytvoř nový token. Zobrazí se **jen jednou**, hned si
ho zkopíruj.

- Pro zkoušení volič rozsah **čtení**. Rozsah **čtení a zápis** dávej až tehdy,
  když má asistent opravdu vystavovat doklady nebo měnit ceny.
- Token rovnou **omez na svou IP adresu** (sloupec *IP omezení* u tokenu).

### Krok 2 — příprava serveru

Server je součástí projektu ve složce `MCP/` a vyžaduje **Node 20 nebo novější**.
Máš dvě možnosti.

**A) Jeden soubor (doporučeno).** Sestav si jednosouborovou verzi:

```bash
pwsh -File cmd/build-mcp.ps1      # Windows
./cmd/build-mcp.sh                # Linux / macOS
```

Vznikne `MCP/dist/myucto-mcp.mjs` — jediný soubor bez externích balíčků, který můžeš
zkopírovat kamkoliv, třeba na jiný počítač. **Ve vydaných balíčcích je hotový
už přiložený**, takže tenhle krok obvykle přeskočíš; v artefaktech vydání může
být ke stažení také samostatný soubor MCP serveru.

**B) Přímo ze zdrojáků.** Hodí se, když si chceš nástroje upravovat:

```bash
cd MCP
npm install
```

Server pak běží z `MCP/src/index.mjs` a potřebuje vedle sebe `node_modules`.

> [!NOTE]
> **Sestavení neodstraňuje potřebu Node.** Výsledek je pořád JavaScript, jen bez
> externích závislostí — Node musí být nainstalovaný v obou případech. Odpadá
> jen `npm install` a adresář `node_modules`.

### Krok 3 — registrace u asistenta

Na stránce **Firma → MCP server** vyber v kroku 3 svého asistenta; zobrazí se
hotová konfigurace i s adresou tvojí instance, kterou stačí zkopírovat.

| Asistent | Kam konfigurace patří |
|---|---|
| **Claude Code** (CLI i desktop) | příkaz `claude mcp add` |
| **Claude Desktop** | `claude_desktop_config.json` (Settings → Developer → Edit Config) |
| **ChatGPT přes Codex CLI** | `~/.codex/config.toml` |
| **Gemini CLI** | `~/.gemini/settings.json` |
| **VS Code (Copilot)** | `.vscode/mcp.json` |
| **Cursor** | `.cursor/mcp.json` |

Například pro Claude Code:

```bash
claude mcp add myucto \
  --env MYUCTO_API_URL=https://tvoje-instance.cz/api/v1 \
  --env MYUCTO_API_TOKEN=mi_pat_tvuj_token \
  -- node /cesta/k/myucto-mcp.mjs
```

Na stránce v aplikaci se dá přepnout, jestli má konfigurace ukazovat na
jednosouborový build, nebo na `MCP/src/index.mjs` — cesta se změní ve všech
ukázkách naráz.

> [!NOTE]
> **Webový ani desktopový ChatGPT tenhle server připojit neumí** — pracuje jen
> se vzdálenými MCP servery přes HTTP, zatímco tenhle běží lokálně. Pro práci
> s daty MyÚčta v prostředí OpenAI použij **Codex CLI**.

### Krok 4 — ověření

Napiš asistentovi „ověř připojení k MyÚčtu“. Zavolá nástroj `whoami` a vrátí
uživatele, roli a firmu. Volání se hned objeví v logu na stránce MCP serveru.

## 80.4 Nastavení

Server se konfiguruje proměnnými prostředí:

| Proměnná | Výchozí | Význam |
|---|---|---|
| `MYUCTO_API_URL` | — | **Povinné.** Adresa API, musí končit `/api/v1`. |
| `MYUCTO_API_TOKEN` | — | **Povinné.** Token `mi_pat_…`. |
| `MYUCTO_SUPPLIER_ID` | — | Firma, se kterou pracovat. Jen u tokenů nevázaných na jednu firmu. |
| `MYUCTO_READ_ONLY` | `0` | `1` = zápisové nástroje se asistentovi vůbec nenabídnou. |
| `MYUCTO_MAX_RPS` | `8` | Nejvýš tolik požadavků za sekundu. |
| `MYUCTO_MAX_CONCURRENT` | `3` | Nejvýš tolik souběžných volání. |
| `MYUCTO_TIMEOUT_MS` | `30000` | Timeout jednoho požadavku. |
| `MYUCTO_SYSTEM_CA` | `1` | Načíst certifikační autority z operačního systému. `0` = nenačítat. |
| `MYUCTO_INSECURE_TLS` | `0` | `1` = vůbec neověřovat HTTPS certifikát. **Jen pro vývojovou instanci.** |

`MYUCTO_READ_ONLY=1` je užitečná pojistka i u tokenu, který právo zápisu má —
zápisové nástroje se v takovém režimu asistentovi ani nezobrazí, takže si
nenaplánuje postup, který by stejně nedokončil.

Stropy `MAX_RPS` a `MAX_CONCURRENT` nejsou kosmetika: API sdílí PHP procesy
s běžícím webem, takže asistent bez omezení zpomalí i běžné uživatele.
Přebytečná volání čekají ve frontě. Nezávisle na nich platí serverový
[rate limit](78_API.md) tokenu.

## 80.5 Příklady dotazů

**Fakturace a pohledávky**

- „Které faktury jsou po splatnosti a kdo nám dluží nejvíc?“
- „Najdi fakturu pro ACME z června a ukaž, jestli je zaplacená.“
- „Vystav fakturu firmě ACME na 10 hodin konzultací po 1 500 Kč.“ *(token čtení a zápis)*

**Odběratelé**

- „Založ klienta podle IČO 45274649.“
- „Najdi v ARES firmu s IČO 12345678 a ukaž mi její adresu.“
- „Uprav Prazdroji telefon na +420 123 456 789.“
- „Přenačti údaje ACME z ARES, přestěhovali se.“

**Výkazy práce a materiálu**

- „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“
- „Kolik hodin je zatím ve výkazu na téhle faktuře?“
- „Přidej do výkazu 5 metrů kabeláže po 120 Kč.“
- „Smaž poslední řádek z výkazu, zadal jsem ho omylem.“

Podrobnosti v [§ 80.7](#807-vykazy-prace-a-materialu).

**Daně**

- „Kolik letos v červenci zaplatíme na DPH?“
- „Jak vychází DPH za tenhle kvartál a co se ještě změní z konceptů?“
- „Z jakých dokladů se skládá DPH za červen?“
- „Kolik letos odvedeme na dani z příjmů a jak jsme na tom se zálohami?“

**Účetnictví**

- „Ukaž obratovou předvahu za letošní období.“
- „Jak se zaúčtovala faktura číslo 2026001?“
- „Co visí v saldu — komu jsme nespárovali platby?“

**Statistika**

- „Ukaž trend obratu a zisku po měsících za poslední rok.“
- „Kde nám utíkají peníze — rozpad nákladů podle kategorií.“
- „Jak moc jsme závislí na největších zákaznících?“
- „Co bych měl dneska řešit?“

**E-shop a sklad**

- „Které zboží je pod minimální zásobou a mělo by se doobjednat?“
- „Kolik máme uloženo ve skladu k dnešnímu dni?“

## 80.6 Odběratelé a ARES

Nového odběratele stačí zadat IČEM:

> „Založ klienta podle IČO 45274649.“

Asistent si vytáhne z **ARES** název, adresu, DIČ i registraci k DPH a kartu
založí. Cokoli řekneš navíc („…a e-mail fakturace@firma.cz“) má přednost před
tím, co vrátí rejstřík — může jít o změnu, která se do ARES ještě nepropsala.

Bez IČO je potřeba název, ulice, město a PSČ; asistent si o ně řekne.

### Ochrana proti duplicitám

Před založením se kontroluje, jestli odběratel se stejným **IČO nebo DIČ** už
neexistuje. Pokud ano, **nic se nezaloží** a asistent ukáže stávající kartu.
Druhou kartu téže firmy lze vytvořit jen vědomě, na výslovné potvrzení.

### Úprava

Stačí říct, co se má změnit — zbytek karty zůstane. Asistent si ji načte,
změnu do ní vloží a uloží celou zpět, takže se nic nevynuluje.

Když se firma přestěhuje nebo přejmenuje, jde údaje přenačíst z rejstříku:

> „Přenačti údaje ACME z ARES.“

Když je ARES nedostupný, u úpravy se **nic nemění** (raději nic než půlka
starých a půlka nových údajů). U zakládání se použijí údaje ze zadání, pokud
stačí — asistent do odpovědi napíše, odkud data vzal.

## 80.7 Výkazy práce a materiálu

Výkaz je navázaný na **koncept faktury** — přesně jako v aplikaci. Stačí tedy říct:

> „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“

Asistent zakázku dohledá, najde její koncept faktury a řádek přidá. Existující
řádky zůstanou beze změny.

### Jak se určí hodinová sazba

Sazbu zadávat nemusíš. Doplní se v tomhle pořadí a první nenulová vyhraje:

1. **poslední řádek výkazu** — když už se výkaz jednou vyplnil, nová hodina má
   sedět s ním, ne s ceníkem;
2. **hodinová sazba zakázky**;
3. **hodinová sazba odběratele**;
4. **výchozí hodinová sazba firmy** (Nastavení firmy).

Když sazbu nemá nikdo, asistent to řekne a požádá o ni — netipuje. Vlastní sazbu
lze samozřejmě určit („…3 hodiny po 1 800 Kč“).

### Který doklad se použije

- Pokud řekneš číslo faktury, použije se ta.
- Pokud jmenuješ jen zakázku nebo odběratele, hledá se jeho **koncept** faktury.
- **Je-li konceptů víc, asistent nehádá** — vypíše je a nechá tě vybrat. Zapsat
  hodiny na cizí doklad by bylo horší než se doptat.
- Vystavená faktura je uzamčená; do jejího výkazu se zapsat nedá.

### Materiál

Řádky materiálu fungují stejně (množství, jednotka, cena za jednotku). Jediný
rozdíl: **sazbu DPH materiálu si asistent nevymýšlí.** Převezme ji z už
existujícího výkazu, jinak si o ni řekne — špatná sazba by se propsala do
přiznání k DPH.

## 80.8 Log volání

Stránka **Firma → MCP server** má dole **Log volání** — každé volání tvých API
tokenů včetně zamítnutých. U volání z MCP serveru je vidět i **název nástroje**,
takže poznáš, co asistent dělal, ne jen jaké URL zavolal.

Filtruje se podle tokenu, metody, cesty, zdroje a na samotné chyby. Podrobnosti
jsou v [§ 78.8](78_API.md#788-log-volani-api).

## 80.9 Bezpečnost

- Token se ukládá jen jako **SHA-256 hash**; plaintext se zobrazí jednou.
- **Omez token na IP** — uniklý token je pak mimo tvou síť k ničemu.
- **Rozsah `čtení`** stačí na drtivou většinu dotazů; zápis dávej vědomě.
- Bearer token má přístup **jen k veřejnému API**. Správa uživatelů, rolí,
  citlivá nastavení a podpisové profily jsou pro něj nedostupné bez ohledu
  na roli uživatele, který token vydal.
- Token **nedávej do souborů, které commituješ** do gitu (týká se hlavně
  `.vscode/mcp.json` a `.cursor/mcp.json` v projektu).
- Nepoužívaný token **zruš**. Historie volání v logu zůstane.

## 80.10 Řešení problémů

| Projev | Příčina a náprava |
|---|---|
| Server nenaběhne, hlásí chybnou konfiguraci | `MYUCTO_API_URL` musí končit `/api/v1` a token začínat `mi_pat_`. |
| Asistent hlásí, že **server neodpovídá** | Častou příčinou je nedůvěryhodný HTTPS certifikát — viz [§ 80.11](#8011-vlastni-https-certifikat); současně ověř dostupnost API. |
| `401 invalid_token` | Token je zrušený nebo expirovaný — vygeneruj nový. |
| `403 token_ip_forbidden` | Token má omezení podle IP a tahle adresa mezi nimi není. |
| `403 insufficient_scope` | Token má jen rozsah čtení, operace vyžaduje zápis. |
| `403 token_write_forbidden` | Zápis do účetnictví nebo daní — přes API nikdy, viz [§ 78.6](78_API.md#786-scopes). |
| `403 stock_disabled` | Skladový a e-shopový modul není pro firmu zapnutý. |
| `429` | Překročen limit — sniž `MYUCTO_MAX_RPS`. |
| Asistent nástroje nevidí | Restartuj aplikaci asistenta; u Gemini CLI ověř příkazem `/mcp`. |
| V logu nejsou žádná volání | Server se nespustil — zkontroluj cestu k `index.mjs` a že proběhlo `npm install`. |

## 80.11 Vlastní HTTPS certifikát

Instance s certifikátem od firemní nebo vlastní autority (typicky testovací
prostředí) je zvláštní případ: **Node má vlastní seznam kořenových autorit
a úložiště operačního systému ve výchozím stavu nečte.** Adresa, která
v prohlížeči funguje bez varování, tedy asistentovi spadne — a protože `fetch`
takovou chybu hlásí jako obyčejné selhání spojení, vypadá to, jako by server
neběžel. Přesně tohle je za hláškou *„server momentálně neodpovídá“*.

Server proto **při startu autority ze systému načte sám**. Nainstalovaný root
certifikát tak stačí a nic dalšího nastavovat nemusíš. Co načetl, vypíše na svůj
chybový výstup:

```
MyÚčto MCP připojen — nástroje načteny, API https://…/api/v1; TLS: systémové certifikáty načteny
```

Když spojení i tak selže na certifikát, dostaneš konkrétní hlášku s postupem.
Nejčastější zbylé příčiny:

- **Neúplný řetěz certifikátů.** Server neposílá mezilehlý certifikát —
  projeví se jako `unable to verify the first certificate`. Náprava je na straně
  webserveru, ne klienta.
- **Node starší než 22.15**, který runtime načtení autorit neumí. Přidej do
  konfigurace asistenta `NODE_OPTIONS=--use-system-ca`, případně
  `NODE_EXTRA_CA_CERTS=/cesta/k/ca.pem`.
- **Certifikát není vydaný nainstalovanou autoritou** (jiný self-signed).

Jako poslední možnost — a **výhradně proti vývojové instanci** — jde ověřování
vypnout přes `MYUCTO_INSECURE_TLS=1`. Server na to při startu hlasitě upozorní.
Na produkci to nepoužívej: bez ověření certifikátu jde spojení odposlechnout
i podvrhnout, a token v hlavičce je to první, co útočník získá.
