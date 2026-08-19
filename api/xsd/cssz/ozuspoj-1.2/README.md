# OZUSPOJ — oznámení záměru uplatňovat slevu na pojistném

Připnutá kopie oficiálního XSD e-podání **OZUSPOJ** („Oznámení záměru uplatňovat
slevu na pojistném za zaměstnance", § 23e zákona č. 589/1992 Sb.). Není součástí
balíčků JMHZ v `api/xsd/jmhz/`: OZUSPOJ nevydává MPSV na `developers.mpsv.cz`
jako ZIP, ale ČSSZ přímo jako jednotlivý soubor, takže downloader
`cmd/download-jmhz-xsd.*` (allowlist hostu, ZIP, počet souborů) na něj nedosáhne.
Proto vlastní adresář a vlastní katalog `OzuspojSchemaCatalog`, který otisky
kontroluje stejně fail-closed jako `PayrollRegistrationSchemaCatalog`.

Staženo 18. 8. 2026 ze stránky
[Definice e-Podání OZUSPOJ](https://www.cssz.gov.cz/definice-e-podani-ozuspoj).

| Soubor | Zdroj | SHA-256 |
|---|---|---|
| `OZUSPOJ23.xsd` (verze schématu 1.2) | `https://www.cssz.gov.cz/documents/20143/2126693/OZUSPOJ23+%282%29.xsd/9b2b3cae-21c1-63cd-6128-a3ea108c3672` | `e4d3968852aaa30e0cc7b37933bdee015fb3288e7a0b7136696ab53df2dce989` |
| `baseTypes2.xsd` | shodný soubor jako `api/xsd/jmhz/prezec-1.2/baseTypes2.xsd` | `0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58` |

Jmenný prostor je `http://schemas.cssz.cz/POJ/OZUSPOJ23`, kořenový element
`podaniOzuspoj`. `xs:import` míří na relativní `baseTypes2.xsd`; ten se sem
nekopíruje z jiného zdroje, je bajt po bajtu totožný s balíčky PREZEC, REGZEC,
REGZELDOPL a DZMH, takže ho hlídá už `api/xsd/jmhz/SHA256SUMS`.

**Pozor na verze:** doprovodný popis datové věty `DV_OZUSPOJ23_v10_20230213.pdf`
je označený jako v1.0, kdežto samotné XSD nese `version="1.2"`. Rozhodující je
XSD; popis datové věty se používá jen na business pravidla, která XSD nevyjadřuje
(povinnost `datumOd`/`datumDo` podle `typPodani` a okno pro doručení oznámení).
Ta jsou přepsaná v `OzuspojXmlValidator` a `OzuspojDeadlinePolicy`.

Změna URL, verze nebo otisku musí být vědomá a musí ji doprovodit aktualizace
téhle tabulky, `OzuspojSchemaCatalog` a testů.
