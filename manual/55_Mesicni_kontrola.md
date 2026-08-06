# 55. Měsíční kontrola

**Cesta: `Účetnictví → Měsíční kontrola`**

Měsíční kontrola spouští nad živými účetními daty stejnou sadu kontrol, kterou
používá předběžný krok roční uzávěrky. Nic sama neopravuje a nevytváří ani
neukládá uzávěrkový krok. Výsledek je okamžitý kontrolní pohled, který lze
opakovat po každé opravě.

Stránka je dostupná firmám s podvojným účetnictvím a uživatelům s právem číst
účetnictví.

## 55.1 Výběr období a rozsahu

Nejdříve vyberte účetní období. Současné webové rozhraní nabízí období ve stavu
**Otevřené** nebo **Uzavírá se**. Následně zvolte:

- **Měsíc** — kalendářní měsíc oříznutý na hranice fiskálního období,
- **Čtvrtletí** — čtyři po sobě jdoucí tříměsíční bloky od začátku fiskálního
  období,
- **Vlastní rozsah** — datum od a do.

Rozsah musí ležet uvnitř vybraného účetního období a datum od nesmí být po
datu do. Backend umí stejnou čtecí kontrolu provést i nad uzavřeným obdobím,
ale aktuální stránka uzavřená období ve výběru nenabízí.

Kontroly mají dva časové režimy:

- položkové kontroly používají vybraný interval nebo stav **k datu do**,
- celoroční invarianty, například návaznost předchozího období, deník celého
  období, inventarizace a roční odpisy, zůstávají svázané s celým fiskálním
  obdobím i při výběru jednoho měsíce.

Proto může měsíční běh upozornit i na problém, který není omezen jen na
zobrazený měsíc.

## 55.2 Jak číst výsledek

Každý řádek ukazuje stav, název kontroly a buď hodnotu, nebo počet nálezů.
Kontroly mají na serveru závažnost:

- **chyba** — celoroční strukturální problém, který může blokovat uzavření
  knih,
- **varování** — stav vyžadující doložení nebo opravu, ale nemusí být sám o
  sobě účetní chybou,
- **informace** — podklad k odbornému posouzení.

Současná tabulka používá pro každý řádek zelenou fajfku nebo červený kříž podle
pole `ok`; samostatný barevný rozdíl závažnosti v ní není. U informačních
kontrol může být `ok = true`, i když seznam nebo nenulový zůstatek vyžaduje
kontrolu účetní.

Kliknutím na počet se otevře živý detail. Náhled je omezený na 50 nálezů.
Popup si data při prvním otevření znovu načte, aby neukazoval starý stav po
opravě. Úplný seznam lze stáhnout jako **CSV** bez tohoto stropu. U účtů a
dokladů jsou dostupné prokliky na opis účtu, deník, fakturu nebo kartu majetku.

U kontroly spárovaných plateb může nabídka **Doúčtovat** vytvořit návrh
vyrovnaného zápisu. Pokud z dat neplyne jednoznačné řešení, otevře se prázdný
ruční zápis; nesoulad názvu protistrany se účetním zápisem neopravuje.

## 55.3 Kontroly období a deníku

| Kontrola | Co server ověřuje | Doporučený postup |
|---|---|---|
| Předchozí období není uzavřené | Bezprostředně předchozí období musí být uzavřené nebo schválené. | Dokončit nebo vědomě znovu otevřít předchozí období. |
| Nenulové výsledkové zůstatky před začátkem období | Náklady a výnosy před počátkem období nemají zůstat otevřené. | Prověřit uzavření a otevření knih. |
| Koncepty v deníku | V celém období existují zápisy bez `posted_at`. | Dokončit nebo odstranit koncepty. |
| Deník není vyrovnaný | Součet řádků MD a Dal celého období se neshoduje. | Jde o strukturální problém; dohledat zápis a neopravovat jej nepodloženým dorovnáním. |
| Nezaúčtované vydané/přijaté doklady | V zadaném rozsahu existují postovatelné doklady bez účetního zápisu; zálohové přijaté výzvy se vylučují. | Otevřít filtrovaný seznam, ověřit doklady a zaúčtovat je. |
| Zápisy bez popisu | Zaúčtovaný zápis v rozsahu nemá obsah účetního případu. | Doplnit popis na zdroji nebo u povoleného ručního zápisu. |
| Stornovaný doklad s aktivním zápisem | Evidence dokladu tvrdí storno, deník stále obsahuje živý předpis. | Opravit vazbu řízeným stornem nebo synchronizací zdroje. |

## 55.4 Zůstatkové a inventarizační kontroly

| Oblast | Kontrolované účty nebo stav |
|---|---|
| Peníze na cestě | 261 k datu konce rozsahu; řádně doložený převod přes hranici období může být v pořádku. |
| Vnitřní zúčtování | 395. |
| Pořízení majetku | 041 a 042. |
| Pořízení zásob | 111 a 131. |
| Zálohy | 314 a 324; otevřený zůstatek může být legitimní nevypořádaná záloha. |
| Vlastní průběžné účty | Všechny aktivní účty označené v osnově jako zúčtovací (`is_clearing`). |
| Neobvyklá strana | Zůstatky účtů na jiné než očekávané straně podle typu účtu. |
| Výsledek hospodaření | Nerozdělený zůstatek účtu 431 k poslednímu dni období. |
| Inventarizace rozvahy | Nezaložená inventarizace je varování; rozpracovaná nebo dokončená s nevyřešenými rozdíly je chyba blokující uzavření knih. |
| Dohady a časové rozlišení | Informativní stavy 388/389 a 381–385; navíc varování na nerozpuštěné dohady přenesené z minulého období. |

Nenulový zůstatek není automaticky chyba. Účetní musí otevřít opis účtu,
identifikovat jednotlivé případy a doložit, proč k rozhodnému dni zůstávají
otevřené.

## 55.5 Doklady, platby, zálohy a měny

Kontrola porovnává evidenční stav dokladu, platební vazby a deník:

- zaplacené vydané faktury s otevřeným saldem na 311,
- zaplacené přijaté faktury s otevřeným saldem na 321,
- zaplacené proformy bez zaúčtované přijaté zálohy na 324,
- zaplacené zálohové přijaté faktury bez úhrady na 314,
- doklady stále ve stavu odeslané/přijaté, přestože účetní saldo už je
  vyrovnané,
- nesoulady spárované platby v částce, měně, protistraně nebo použití kurzu,
- realizované kurzové rozdíly, které nebyly zaúčtované na 563/663,
- otevřené cizoměnové položky určené k přecenění,
- řádky devizových účtů s neúplnou stopou `currency_code` a
  `amount_foreign`,
- odchylku uloženého kurzu dokladu od denního kurzu ČNB.

Kontrola ČNB je upozornění, ne automatická oprava. Pevný kurz, celní kurz nebo
jiný doložený zákonný postup může odchylku vysvětlit. Historický kurz
nepřepisujte jen proto, aby kontrola zezelenala; kurz faktury, kurz úhrady a
závěrkový kurz mají rozdílnou funkci.

## 55.6 Majetek, daně a další zákonné oblasti

Součástí běhu jsou také:

- karta majetku v užívání bez účetního odpisu za fiskální rok,
- majetkové účty bez odpovídajících oprávek,
- nesoulad evidence drobného majetku proti obratu účtu 501.200,
- zůstatek účtu 343 proti přiznání k DPH za zvolený interval,
- oprávněnost čtvrtletního zdaňovacího období podle obratu minulého roku,
- vznik povinné registrace k DPH po překročení zákonného limitu,
- transakce se spojenými osobami a měřitelné odchylky jejich cen od
  srovnatelných cen nespojeným osobám,
- povinnost a vnitřní návaznost přehledu o peněžních tocích a změnách vlastního
  kapitálu u příslušné kategorie účetní jednotky,
- informační připomínka k zaúčtování splatné daně z příjmů přes krok uzávěrky.

Kontrola ceny mezi spojenými osobami tvrdí odchylku jen tam, kde má srovnání
stejné položky vůči nespojeným osobám. Samostatný seznam transakcí se
spojenými osobami je informační podklad a vyžaduje dokumentaci ceny obvyklé.

## 55.7 Zámek účtování k datu

Nahoře je zobrazen aktuální `locked_until`. Uživatel s právem číst účetnictví
stav vidí. Tlačítko **Uzamknout k datu** se zobrazuje podle práva na uzavírání
období, ale server změnu přijme pouze od administrátora.

Administrátor může zámek:

- nastavit nebo posunout dopředu,
- posunout zpět pro řízenou opravu,
- zrušit prázdným datem.

Každá změna vyžaduje důvod o nejméně pěti znacích a ukládá do auditní stopy
původní hodnotu, novou hodnotu a zdůvodnění. Doklady s datem menším nebo
rovným zámku nelze nově zaúčtovat ani přeúčtovat. Podrobnosti jsou v
[Účetním deníku](45_Ucetni_denik.md#459-zamek-uctovani-k-datu).

## 55.8 Co udělat s nálezem

1. Otevřete detail a zjistěte konkrétní doklady nebo účty.
2. Porovnejte nález s nezávislým podkladem, nikoli jen s jinou obrazovkou
   aplikace.
3. Opravte zdrojový doklad, platební vazbu, účetní zápis nebo nastavení podle
   skutečné příčiny.
4. Kontrolu spusťte znovu. Zelený stav má být důsledkem opravy zdroje, ne
   ručního dorovnání bez podkladu.
5. Po vyřešení měsíce připravte
   [Měsíční přehled](56_Mesicni_report.md) a po doloženém podání daní vědomě
   nastavte zámek.

Měsíční kontrola je široká technická brána, ale neprokáže existenci případu,
který v systému nemá žádná data. Nenahrazuje inventurní soupis, potvrzení
banky, partnerské odsouhlasení ani odborný úsudek účetní.
