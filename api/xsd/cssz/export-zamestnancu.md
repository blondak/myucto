# Export zaměstnanců z ePortálu ČSSZ — schémata

Export „Seznam zaměstnanců" (`ExportZamestnancu`, bez jmenného prostoru), který
zaměstnavatel stahuje z ePortálu ČSSZ. MPSV zveřejnilo XSD pro oba tvary exportu;
import registrací (`RegistrationXmlReader`) validuje soubor proti tomu, kterému
odpovídá, a otisky kontroluje fail-closed `CsszEmployeeExportSchemaCatalog`.

| Tvar | Adresář | Poznámka | SHA-256 `SeznamZamestnancu.xsd` | SHA-256 archivu MPSV |
|---|---|---|---|---|
| do 14. 10. 2026 | `export-zamestnancu-v1/` | bez `PojistnyVztahOd` | `27ef2c3a8739cca4ed1a75ea2b634e71c1dc30ae81f850b370a4c13267d49ff2` | `2357bee2479e168738fac84811190e166a195e63fa879d31746fc6b764071a7a` |
| od 15. 10. 2026 | `export-zamestnancu-v2/` | povinné `PojistnyVztahOd`, nepovinné `KodBlizsihoUrceniCinnosti` a `NazevBlizsihoUrceniCinnosti` | `7b74be3df755bb619e086cb850ea47210f70209b205fe2a1d5da63a98050443c` | `28d8879e99c511ba46f894050e0a8f89913945ed82cd9018af451a2d6753b198` |

Oba tvary nesou volbu `RodneCislo` / `EvidencniCisloPojistence` (cizinec bez
rodného čísla) a nepovinné `PojistnyVztahDo`. Tvar se pozná podle přítomnosti
`PojistnyVztahOd`: nový export ho má u každé věty, starý u žádné.

Schémata jsou veřejná datová věta MPSV/ČSSZ; připínají se bajt po bajtu
(`*.xsd -text` v kořenovém `.gitattributes`). Změna souboru nebo otisku musí být
vědomá a musí ji doprovodit aktualizace této tabulky, katalogu a jeho testu.
