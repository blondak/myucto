# MyÚčto MCP server

MCP server (Model Context Protocol) nad veřejným REST API MyÚčta. Zpřístupní
AI klientovi — Claude Code, Claude Desktop, IDE rozšíření — **fakturaci,
e-shop se skladem a statistiku**.

Uživatelský návod včetně příkladů dotazů je přímo v aplikaci:
**Nastavení firmy → MCP server**. Tenhle soubor je technická poznámka k repozitáři.

## Co server umí a co záměrně ne

| Oblast | Rozsah |
|---|---|
| Fakturace | čtení, vystavování, odesílání, evidence úhrad, upomínky |
| Výkazy práce a materiálu | přidání a odebrání řádků u konceptu faktury, automatická hodinová sazba |
| Pohledávky a závazky | zaplacené / nezaplacené / po splatnosti, stáří pohledávek |
| Daně | **jen čtení** — odhad DPH (měsíc i kvartál), KH, SH, daň z příjmů, kalendář |
| Účetnictví | **jen čtení** — obratovka, rozvaha, výsledovka, hlavní kniha, saldo, deník |
| Statistika | tržby, zisk, trendy, top odběratelé i dodavatelé, cash flow, platební morálka |
| E-shop a sklad | zboží, kategorie, výrobci, ceny, zásoby, dostupnost, ocenění |
| Hledání | globální vyhledávání napříč odběrateli a doklady |

Účetní a daňová vrstva je **jednosměrná**. Zaúčtování, storno zápisu, uzavření
období, zaevidování opravy podle § 46 / § 74b a odeslání podání na EPO v katalogu
nejsou a nesmí být: jsou to úkony s daňovou odpovědností, kde chyba znamená
opravné podání, a model nemá jak doložit jejich správnost. Vynucuje to i server
(`ApiScopeMiddleware::BEARER_READ_ONLY` → `403 token_write_forbidden`), takže
i kdyby sem někdo takový nástroj přidal, dostane odmítnutí. Zdůvodnění je
v hlavičce `src/tools.mjs` — při rozšiřování katalogu ho neobcházej.

## Instalace

Dvě varianty; obě vyžadují **Node ≥ 20** (server běží na globálním `fetch`).

### A) Jednosouborový build — doporučeno pro uživatele

```bash
pwsh -File cmd/build-mcp.ps1      # Windows
./cmd/build-mcp.sh                # Linux / macOS
```

Vznikne `MCP/dist/myucto-mcp.mjs` — jeden soubor bez závislostí, který lze
zkopírovat kamkoliv (`node myucto-mcp.mjs`). Odpadá `npm install`
i `node_modules`, a s nimi typická chyba „nainstaloval jsem to jinam, než
ukazuje konfigurace asistenta".

> **Build NEODSTRAŇUJE potřebu Node.** Je to pořád JavaScript, jen bez externích
> závislostí. Samostatný spustitelný soubor bez Node by znamenal přibalit celý
> runtime (desítky MB), což pro tenhle nástroj nedává smysl.

Ve vydaných balíčcích je build přiložený (`MCP/dist/`) a k release je navíc
připnutý jako samostatný asset `myucto-mcp-<verze>.mjs` — viz
`.github/workflows/docker-publish.yml`.

### B) Přímo ze zdrojáků — pro vývoj

```bash
cd MCP
npm install
node src/index.mjs
```

## Registrace v Claude Code

```bash
claude mcp add myucto \
  --env MYUCTO_API_URL=https://ucto.firma.cz/api/v1 \
  --env MYUCTO_API_TOKEN=mi_pat_vas_token \
  -- node /cesta/k/myucto.cz/MCP/src/index.mjs
```

Token se generuje v aplikaci: **Nastavení firmy → API tokeny → Nový token**.

## Proměnné prostředí

| Proměnná | Povinná | Výchozí | Význam |
|---|---|---|---|
| `MYUCTO_API_URL` | ano | — | Základ API, musí končit `/api/v1` |
| `MYUCTO_API_TOKEN` | ano | — | Token `mi_pat_…` |
| `MYUCTO_SUPPLIER_ID` | ne | — | Firma pro token nevázaný na jednu firmu |
| `MYUCTO_READ_ONLY` | ne | `0` | `1` = zápisové nástroje se vůbec nenabídnou |
| `MYUCTO_MAX_RPS` | ne | `8` | Strop požadavků za sekundu |
| `MYUCTO_MAX_CONCURRENT` | ne | `3` | Souběžná volání |
| `MYUCTO_TIMEOUT_MS` | ne | `30000` | Timeout jednoho požadavku |
| `MYUCTO_SYSTEM_CA` | ne | `1` | Načíst certifikační autority z OS; `0` = nenačítat |
| `MYUCTO_INSECURE_TLS` | ne | `0` | `1` = neověřovat HTTPS certifikát (JEN vývoj) |

Stropy `MAX_RPS` / `MAX_CONCURRENT` nejsou kosmetika: API sdílí PHP procesy
s běžícím webem, takže agent bez omezení zpomalí i normální uživatele.
Nezávisle na nich platí serverový rate limit tokenu.

## Vlastní / firemní HTTPS certifikát

Node má vlastní seznam kořenových autorit a systémové úložiště ve výchozím stavu
**nečte** — instance s firemním certifikátem tedy v prohlížeči funguje, ale
`fetch` na ni spadne na `unable to verify the first certificate` a chyba přijde
jako obyčejné selhání spojení („server neodpovídá"). Server proto při startu
systémové autority načte sám (`tls.setDefaultCACertificates`, Node 22.15+)
a výsledek vypíše na stderr:

```
MyÚčto MCP v1.0.0 připojen — 62 nástrojů, API https://…/api/v1; TLS: +134 systémových certifikátů
```

Zbylé příčiny selhání: neúplný řetěz (chybí mezilehlý certifikát — oprava patří
na webserver), starší Node (`NODE_OPTIONS=--use-system-ca` / `NODE_EXTRA_CA_CERTS`),
nebo certifikát vydaný jinou než nainstalovanou autoritou. `MYUCTO_INSECURE_TLS=1`
je až poslední možnost a jen pro vývoj.

## Výkazy práce — poznámka k návrhu

`PUT /invoices/{id}/work-report` **nahrazuje celý výkaz**, ne jen přidává řádek.
Nástroje `add_work_report_entry` / `add_work_report_material` proto výkaz nejdřív
načtou, přidají řádek a pošlou zpět kompletní seznam — jinak by přidání jedné
hodiny smazalo všechno ostatní.

Hodinová sazba se odvozuje v pořadí *poslední řádek výkazu → zakázka → odběratel
→ výchozí sazba firmy* (`resolveHourlyRate`). Nulová hodnota se bere jako
nevyplněno a jde se o úroveň výš. Sazbu DPH materiálu naopak **neodvozujeme** —
buď je ve výkazu, nebo si ji nástroj vyžádá; špatná sazba se propíše do přiznání.

`resolveDraftInvoice` dohledá doklad podle názvu zakázky nebo odběratele.
Při víc než jednom kandidátovi **záměrně selže s výpisem možností** místo výběru
prvního — zápis hodin na cizí doklad je horší než doptání se.

## Struktura

```
src/index.mjs   vstupní bod — konfigurace, MCP handshake, mapování chyb
src/client.mjs  HTTP klient — throttling, retry, hlavičky, serializace query
src/tools.mjs   katalog nástrojů (jediné místo, kam se přidává nový nástroj)
```

Přidání nástroje = jeden záznam v `TOOLS`. `write: true` u čehokoli, co mění data —
podle toho se nástroj skryje v režimu jen pro čtení a označí varováním v popisu.

## Logování

Každý požadavek nese `X-MyUcto-Client: mcp`, `X-MyUcto-Client-Version` a
`X-MyUcto-Tool` s názvem volaného nástroje. Server je zapisuje do
`api_request_log` a aplikace je zobrazuje v **Nastavení firmy → MCP server →
Log volání** — je tedy vidět, který nástroj co volal, včetně zamítnutých pokusů.

## Bezpečnost

- Token se ukládá jen jako SHA-256 hash; plaintext se zobrazí jednou při vydání.
- Token lze omezit na IP adresy a rozsahy (IPv4 i IPv6) — v aplikaci u tokenu.
- Rozsah `čtení` odmítne jakýkoli zápis už na serveru; `MYUCTO_READ_ONLY`
  je druhá pojistka na straně klienta.
- Bearer token má přístup jen k veřejnému subsetu API — interní správa,
  uživatelé a nastavení jsou pro něj nedostupné bez ohledu na roli.
