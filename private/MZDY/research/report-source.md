# Podání PPZ a HOZ zdravotním pojišťovnám od roku 2026

**Datum ověření:** 26. 8. 2026

**Publikum:** vývoj a produkt MyÚčto

**Rozsah:** formát příloh a doložené elektronické kanály sedmi českých zdravotních pojišťoven

## Výsledek

Do datové schránky se neposílá XSD. XSD je pouze schéma, proti kterému se
validuje konkrétní XML dokument. Ani XDP není obecný datový formát podání:
u VZP a VoZP jde o šablonu formuláře pro hromadné vyplnění a následný vznik
PDF.

Neexistuje jeden doložený formát ISDS přílohy, který by byl bezpečné použít
u všech sedmi pojišťoven. Pro MyÚčto je proto správná uzavřená matice podle
pojišťovny a období. Přímá portálová nebo B2B integrace je jiná transportní
cesta a nesmí se odvozovat jen z existence XML schématu.

## Doporučená matice MyÚčta

| Kód | Pojišťovna | Preferovaná ISDS příloha | Doložená poznámka |
|---|---|---|---|
| 111 | VZP ČR | vytěžitelné PDF | VZP výslovně dovoluje datovou schránku; vlastní automatizovaná rozhraní používají zvláštní DR/B2B obálku. |
| 201 | VoZP ČR | vytěžitelné PDF | VoZP zveřejňuje formulář/XDP a dovoluje podání z ověřené datové schránky; veřejně doložené automatické rozhraní není důvodem měnit ISDS přílohu. |
| 205 | ČPZP | XML | Pojišťovna zveřejňuje PPPZ.XML a HOZ.XML, XSD i pravidla pro jednu hlavní XML přílohu odeslanou E-přepážkou nebo datovou schránkou. |
| 207 | OZP | XML | Pojišťovna požaduje elektronické podání v XML a uvádí Portal ZP nebo datovou schránku. |
| 209 | ZPŠ | vytěžitelné PDF | Pojišťovna výslovně připouští PDF přes datovou schránku v přechodném období roku 2026. |
| 211 | ZP MV ČR | PDF do 30. 6. 2026; XML od 1. 7. 2026 | Od 1. 7. 2026 pojišťovna přijímá nový XML formát; PDF zůstává přijímanou přechodnou alternativou do 31. 12. 2026. |
| 213 | RBP | XML | RBP připouští XML i vytěžitelné PDF; XML je vhodný preferovaný strojový formát. |

Volba „preferovaná“ neříká, že jiný formát pojišťovna nikdy nepřijme. Říká,
jaký doložený formát má MyÚčto vytvořit deterministicky. Odeslání přes ISDS
musí vždy výslovně potvrdit uživatel.

## PPZ a HOZ nejsou JMHZ

VZP výslovně potvrzuje, že PPZ i HOZ zůstávají mimo JMHZ a podávají se přímo
zdravotním pojišťovnám. PPZ se podává za každý měsíc, HOZ oznamuje jednotlivé
skutečnosti. Stejný transportní kanál proto neznamená stejný obsah nebo
stejnou lhůtu.

Současný produktový závěr:

- PPZ lze připravovat podle matice výše a nabídnout ke stažení nebo k ručně
  potvrzenému odeslání přes ISDS.
- U HOZ se nesmí z existence společného XSD automaticky odvodit jednotná
  akceptace všemi pojišťovnami. Dokud není pro konkrétní pojišťovnu a období
  doložený celý artefakt a kanál, zůstává bezpečný pracovní inbox a ruční
  dokončení na oficiálním kanálu.
- Přímé portálové API je samostatné budoucí rozšíření. Certifikát použitelný
  u jednoho portálu nebo B2B služby nedokládá interoperabilitu ostatních.

## Claim-to-source ledger

| Tvrzení | Primární zdroj | Síla důkazu |
|---|---|---|
| VZP: PPZ a HOZ jsou od roku 2026 elektronické, mimo JMHZ; kanál VZP Point nebo datová schránka. | [VZP — Podání PPZ a HOZ](https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/podani-prehledu-o-platbe-pojistneho-a-hromadne-oznameni-zamestnavatele) | přímé vyjádření pojišťovny |
| VZP: automatické rozhraní pro PPPZ/HOZ přenáší vlastní DR soubor uvnitř XML obálky. | [VZP — Alternativní rozhraní VZP Pointu](https://www.vzp.cz/e-vzp/informace-pro-sw-firmy/ais/popis-sluzeb) | technická dokumentace pojišťovny |
| VZP: formulář nabízí PDF a XDP určené k hromadnému vyplnění z účetních systémů. | [VZP — Přehled o platbě pojistného zaměstnavatele](https://www.vzp.cz/platci/formulare/prehled-o-platbe-pojistneho-zamestnavatele) | oficiální formulářová stránka |
| VoZP: podání je možné online nebo z ověřené datové schránky a stránka nabízí formulářové soubory. | [VoZP — Formuláře zaměstnavatele](https://www.vozp.cz/formulare-zamestnavatele) | oficiální formulářová stránka |
| ČPZP: PPPZ.XML a HOZ.XML mají zveřejněná XSD a lze je poslat E-přepážkou nebo datovou schránkou. | [ČPZP — Změny 2026](https://www.cpzp.cz/zmeny2026), [ČPZP — Oznamovací povinnost](https://cpzp.cz/clanek/921-0-Oznamovaci-povinnost-pro-platce.html) | přímé technické pokyny pojišťovny |
| OZP: elektronické podání používá XML přes Portal ZP nebo datovou schránku. | [OZP — Informace pro zaměstnavatele](https://www.ozp.cz/pro-platce/zamestnavatel/informace-pro-zamestnavatele) | přímé pokyny pojišťovny |
| ZPŠ: PDF je v roce 2026 přijímaný elektronický formát včetně datové schránky. | [ZPŠ — Zaměstnavatelé a zaměstnanci](https://www.zpskoda.cz/zamestnavatele-zamestnanci) | přímé pokyny pojišťovny |
| ZP MV ČR: od 1. 7. 2026 přijímá také nový XML formát; PDF/TIF zůstává v přechodném období do konce roku. | [ZP MV ČR — Přechod na nové elektronické formáty](https://www.zpmvcr.cz/o-nas/aktuality/informace-pro-zamestnavatele-a-osvc-prechod-na-nove-elektronicke-formaty-podani) | přímé datované vyjádření pojišťovny |
| RBP: podání lze učinit přes Portal ZP, my213 nebo datovou schránku v XML či vytěžitelném PDF. | [RBP — Elektronické podání přehledů a oznámení](https://www.rbp213.cz/elektronicke-podani-prehledu-o-platbach-a-hromadnych-oznameni/a-5530/) | přímé pokyny pojišťovny |

## Nejistoty a hranice závěru

- Veřejná stránka VoZP dokládá elektronický kanál a formulář, nikoli veřejné
  smluvní B2B rozhraní. Proto se neimplementuje automatické portálové odeslání.
- VZP má vlastní automatizovaná rozhraní, ale jejich transportní obálka a DR
  nejsou totožné s prostým přiložením společného XML do ISDS.
- Přijetí datové zprávy není věcné přijetí formuláře. Produkt musí vést zvlášť
  doručení ISDS a následnou odpověď pojišťovny.
- Oficiální pravidla se mohou změnit. Katalog musí být datovaný, testovaný na
  hranicích účinnosti a před produkčním spuštěním znovu ověřený.

## Implementační dopad

1. Katalog příloh držet jako jediné místo pravdy pro UI, generátor a ISDS.
2. Testovat všech sedm kódů a hraniční data ZP MV ČR 30. 6./1. 7. 2026.
3. V UI neukazovat neurčité věty typu „přijetí se nepodařilo doložit“, pokud je
   doložený formát a ručně potvrzený ISDS kanál podporovaný.
4. Neoznačovat soubor jako odeslaný po pouhém sestavení nebo přesměrování.
5. Před produkčním spuštěním od 1. 10. 2026 provést kvalifikační podání do
   každé pojišťovny, u které bude reálný zaměstnanec, a uložit anonymizovaný
   výsledek/protokol jako provozní důkaz mimo veřejný repozitář.
