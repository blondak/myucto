# Číselníky a datový slovník JMHZ

Tento adresář obsahuje připnuté kopie oficiálních podkladů ČSSZ/MPSV, ze kterých
se staví datový slovník, katalog kontrol, scénářová matice a číselníky JMHZ.
Každý balíček je ve vlastním verzovaném adresáři a vedle zdrojů leží
deterministický `manifest.json`, který je jediným čtecím vstupem aplikace.

Připnutý stav: datový slovník 1.4.1.6, katalog kontrol 1.4.2.7, datové scénáře
1.4.0.2, overlay externích číselníků ze 13. 8. 2026. Slovník připíná
**46 číselníků** — 40 vložených se 783 položkami a 6 externích referencí, které
zůstávají prázdné. Overlay k nim doplňuje **6 254 obcí (CISOB)** a
**250 zemí (CZEMALFA)**.

| Zdroj | Soubor | Původ | SHA-256 |
|---|---|---|---|
| Datový slovník 1.4.1.6 | `dictionary-1.4.1.6/datovy_slovnik_1.4.1.6.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/f389e547-8bc0-4470-9531-f8319ff4d11e/datovy_slovnik_1.4.1.6.xlsx) | `e794a56d3baa48dd876ad45a0deb5b1bb77c17a0cb44a3511e8ef4028be69743` |
| Katalog kontrol 1.4.2.7 | `dictionary-1.4.1.6/Katalog kontrol MH(public)_1.4.2.7.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/5ef0b0d3-e7b2-4788-8fd1-1075d44a27f5/Katalog%20kontrol%20MH%28public%29_1.4.2.7.xlsx) | `fbc87a3aab479af1c58bd44aa710e43f5a522d5ebca5de6eec9bbb690ad8a440` |
| Datové scénáře 1.4.0.2 | `dictionary-1.4.1.6/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/9fad6021-73d0-4914-80c8-609716b5697d/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx) | `cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37` |
| CISOB (obce) | `external-codebooks-2026-08-13/sb-2025-511-priloha-2-fragment-1093782.ttl` | e-Sbírka, vyhláška č. 511/2025 Sb., příloha č. 2 — **ruční zdroj** | `b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44` |
| CZEMALFA (státy) | `external-codebooks-2026-08-13/CIS1186_CS_2026-08-13.csv` | ČSÚ, číselník 1186 — **ruční zdroj** | `940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886` |

## Obnova

```
pwsh -File cmd\download-jmhz-codebooks.ps1            # Windows
cmd\download-jmhz-codebooks.cmd
bash cmd/download-jmhz-codebooks.sh                   # Linux
```

Volby (platí pro všechny tři obálky):

| Volba | Význam |
|---|---|
| *(bez voleb)* | Stáhne, ověří a atomicky nainstaluje připnuté zdroje. |
| `--verify` | Jen ověří strom na disku; nic nestahuje ani nezapisuje. |
| `--candidate=ADRESÁŘ` | Stáhne kandidáta mimo připnutý strom a nic nenainstaluje. |
| `--diff=SOUBOR --report=SOUBOR` | Porovná připnutý a kandidátský `manifest.json` a zapíše strojový report změn. |

Návratový kód `0` znamená beze změny, `3` nalezenou změnu (kandidát nebo diff)
a `1` jakoukoli chybu ověření. Při chybě se na disku nemění nic.

## Co downloader vynucuje

Manifest `tools/jmhz-codebook-sources.php` připíná u každého zdroje verzi,
oficiální URL, SHA-256, přesný počet bajtů, povolený typ obsahu a očekávaný
podpis souboru. U výsledných katalogů připíná navíc verzi schématu, identitu
balíku, `manifest_sha256`, očekávané počty položek a seznam číselníků, které
musí zůstat prázdnou externí referencí.

Downloader fail-closed odmítne:

- jiný host, schéma, port nebo cestu než připnutou cestu k archivu MPSV,
  a to i po přesměrování (protokolově relativní i mimo-hostový cíl);
- obsah přes velikostní limit, s jiným `Content-Type` nebo bez očekávaného
  podpisu (`PK\x03\x04` u XLSX, čistý UTF-8 bez BOM u textových zdrojů);
- jiný SHA-256 nebo jiný počet bajtů, než je připnuto;
- katalog, jehož `manifest_sha256` nesedí na kanonický otisk vlastního
  `payload`, který má jinou identitu balíku, jinou verzi schématu, jiné počty
  položek nebo neodkazuje na připnutý základní balík;
- jakýkoli ze šesti externích číselníků, pokud přestane být prázdnou externí
  referencí — hodnoty se pro ně nikdy nedoplňují;
- soubor, který v manifestu chybí, na disku chybí nebo nesedí na hash zapsaný
  přímo v `payload.sources`.

Instalace proběhne až po ověření všech zdrojů i katalogů, a to atomickým
přejmenováním připraveného stromu; při jakékoli chybě zůstane připnutý strom
beze změny. Soubor `SHA256SUMS` je deterministický katalog obsahu celého
adresáře (bez sebe sama a bez `.md`); neobsahuje čas stažení ani lokální cesty.
Aby ho Git předával bajt po bajtu, drží kořenový `.gitattributes` konce řádků
i binární režim pro `*.xlsx`, `*.csv`, `*.ttl`, `*.json` i pro `SHA256SUMS`.

## Past: NFC vs. NFD v názvech na blob storage MPSV

Blob storage MPSV míchá klíče v obou normalizacích Unicode. Soubor
`Seznam zaměstnanců.csv` je například dostupný **jen** pod klíčem v NFD —
požadavek na `…Seznam%20zam%C4%9Bstnanc%C5%AF.csv` (NFC) vrací
`404 The specified blob does not exist`, zatímco
`…Seznam%20zame%CC%8Cstnancu%CC%8A.csv` (NFD) vrací `200`. Downloader proto
každou URL nejdřív ověří v připnuté podobě a při 404/410 zkusí druhou
normalizaci; když neexistuje ani jedna, běh fail-closed skončí. Převod nepoužívá
`ext-intl` (na cílových strojích chybí), ale uzavřenou tabulku českých znaků —
název se znakem mimo tabulku se odmítne, místo aby se tiše přeložil špatně.
Chování je zafixované fixturou `api/tests/Fixtures/Payroll/jmhz-mpsv-blob-normalization.json`,
která nese skutečně naměřené stavové kódy; testy nechodí na síť.

## Detekce změn (report)

`JmhzCodebookManifestDiff` porovná připnutý a čerstvě postavený `manifest.json`
a vypíše strojově čitelný report `jmhz-codebook-change-report.v1`: přidané,
odebrané a změněné kódy položek po číselnících (u změněných včetně `row_hash` a
obou popisků), přírůstek a úbytek celých číselníků a identitu obou stran včetně
`versions`, `snapshot_date` a `counts`. Report se **nikdy neaplikuje
automaticky** — je to podklad pro člověka. Neukládá se do databáze a za běhu
aplikace se z něj nečte.

Kandidátský `manifest.json` vzniká spuštěním
`tools/JmhzDictionaryPackageBuilder.php` (resp.
`JmhzExternalCodebookPackageBuilder.php`) nad staženými zdroji. Buildery mají
vlastní připnuté SHA-256, takže nad novou verzí podkladu vědomě selžou —
to je ta lidská brána. Při shodné verzi vyrobí bajtově identický manifest, což
je zároveň kontrola, že se generování nerozešlo se zdrojem.

## Postup při nové verzi podkladu ČSSZ/MPSV

1. `--candidate=ADRESÁŘ` stáhne nové soubory mimo připnutý strom (kód `3`).
2. Aktualizovat SHA-256 a počty bajtů v builderech a nechat je postavit
   kandidátský `manifest.json`.
3. `--diff` proti připnutému manifestu a projít report změn.
4. Teprve po odsouhlasení přepnout `tools/jmhz-codebook-sources.php` (URL,
   verze, hash, počet bajtů, `manifest_sha256`, počty položek), aktualizovat
   tuto tabulku i testy a spustit obnovu bez voleb.

Změna URL, verze, kontrolního součtu, počtu položek nebo seznamu externích
referencí musí být vědomá. Manifest ověřuje původ a integritu; nenahrazuje
verzovaná aplikační business pravidla ani odborné legislativní ověření.
