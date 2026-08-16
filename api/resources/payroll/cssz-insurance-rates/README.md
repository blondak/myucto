# Sazby pojistného na sociální zabezpečení — otevřená data ČSSZ

Připnutá kopie datové sady **„Sazby pojištění v ČR"** České správy sociálního
zabezpečení. Slouží k jedinému účelu: **strojově ověřit, že sazby v dodané
legislativní sadě** (`CzechPayrollRulesets2026`, doména `social_insurance`)
**odpovídají oficiálnímu zdroji**.

## Připnutý stav

| Údaj | Hodnota |
|---|---|
| Datová sada | Sazby pojištění v ČR |
| Vydavatel | Česká správa sociálního zabezpečení |
| Katalog | [data.cssz.cz](https://data.cssz.cz/web/otevrena-data/-/sazby-pojisteni-v-cr) |
| Zdrojová URL | `https://data.cssz.cz/dump/sazby-pojisteni-v-cr.csv` |
| Staženo | 2026-08-15 |
| Rozsah | intervaly platnosti od 2012-01-01 do 2026-12-31 |
| Licence | **volné dílo** — podmínky užití ČSSZ uvádějí, že distribuce neobsahuje autorská díla, není autorskoprávně chráněnou databází ani není chráněna zvláštním právem pořizovatele databáze; v RDF metadatech `PublicDomain` |

| Soubor | SHA-256 |
|---|---|
| `rates-2026-08-15/sazby-pojisteni-v-cr.csv` | `7977808aea348fb52ba27245688517e3bd17294cbc40049365706e2a0b8e03c9` |

Otisky jsou i v `SHA256SUMS`. Soubor je **nezměněná původní publikace ČSSZ**,
nejde o odvozená data.

## Proč připnutý soubor, a ne stažení za běhu testu

Test, který sahá na síť, netestuje naše sazby — testuje dostupnost cizího serveru.
Padal by při výpadku ČSSZ, v offline buildu i za firemním proxy, a hlavně by
**tiše měnil, proti čemu se porovnává**. Připnutá kopie s otiskem dělá z aktualizace
zdroje viditelnou změnu v diffu: kdo mění soubor, mění i hash a musí to obhájit.

Stejný důvod a stejný vzor jako `api/resources/payroll/cz-isco/`
a `api/resources/payroll/jmhz/`.

## Formát

CSV s hlavičkou, oddělovač `,`, kódování UTF-8. Klíčové sloupce:

| Sloupec | Význam |
|---|---|
| `platnost_od`, `platnost_do` | interval platnosti sazby (přesně to, co potřebuje mzdový engine) |
| `sazba_pojistneho` | složka pojistného — `Důchodové pojištění`, `Nemocenské pojištění`, `Příspěvek na státní politiku zaměstnanosti`, `Celková sazba` |
| `zamestnavatel` | sazba zaměstnavatele v procentech |
| `zamestnavatel_zachranar_hasic` | zaměstnavatel u zdravotnických záchranářů a hasičů |
| `zamestnavatel_rizikove_zam` | zaměstnavatel u rizikových zaměstnání |
| `zamestnanec` | sazba zaměstnance |

Řádek `Celková sazba` s `platnost_od = 2026-01-01` nese hodnoty, které porovnává
`CsszInsuranceRatesPinTest`: **24,8 % zaměstnavatel · 29,8 % záchranář/hasič ·
27,8 % rizikové · 7,1 % zaměstnanec**, a řádek `Důchodové pojištění` sazbu
**6,5 %** pro pracujícího důchodce.

## Co tenhle zdroj NEPOKRÝVÁ

Ostatní mzdové veličiny — minimální a zaručená mzda, redukční hranice DPN,
nezabavitelné částky, slevy na dani, daňové zvýhodnění, průměrná mzda,
maximální vyměřovací základ, tuzemské stravné — **strojově čitelný zdroj nemají**.
Sweep Národního katalogu otevřených dat je nenašel (podrobně
`private/LEGISLATIVNI-SADY-KONKURENCE.md`, §5). U nich zůstává ruční kontrola
proti primárnímu předpisu a doložení přes `RulesetSource`.
