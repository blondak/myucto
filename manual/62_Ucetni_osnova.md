# 62. Účtový rozvrh

**Cesta: `Nástroje → Účtový rozvrh`**

Účtový rozvrh je seznam účtů, na které firma účtuje. Stránka i její API jsou
dostupné jen firmě v režimu **podvojného účetnictví**. Předkontace jsou po
rozdělení menu popsány samostatně v kapitole [Nástroje](69_Ucetni_nastroje.md);
šablony zápisů a pravidla nákladů v kapitole
[Šablony](61_Sablony.md).

## 62.1 Co stránka zobrazuje

Účty jsou seskupené do tříd **0–7** podle prvního znaku kódu. Samostatně se
zobrazují **podrozvahové účty**, **závěrkové účty** a ostatní kódy. V každé
skupině jsou účty seřazené podle kódu.

| Sloupec | Význam |
|---|---|
| Kód | Syntetika je zobrazena tučně, analytika odsazeně pod rodičem |
| Název | Uživatelský název účtu |
| Typ | Aktivum, pasivum, kapitál, výnos, náklad, podrozvaha nebo závěrkový účet |
| Strana | Obvyklá strana MD/Dal; u saldních účtů může být prázdná |
| Aktivní | Zda lze účet použít pro nové zápisy |

Prázdná obvyklá strana není chyba. Například účty 343, 341 nebo 431 mohou podle
skutečného salda skončit na MD i na Dal. Obvyklá strana slouží sestavám a
kontrolám; vlastní stranu každého řádku určuje účetní zápis.

Přepínač **Zobrazit neaktivní** načte i vyřazené účty. Neaktivní účet zůstává
čitelný v historickém deníku a sestavách, ale není nabízen pro nový zápis.

## 62.2 Automatické založení osnovy

Při zahájení aktivace podvojného účetnictví server idempotentně naseeduje
standardní syntetické účty a systémové předkontace. Stejný seed lze bezpečně
spustit znovu: existující firemní účty ani jejich názvy se neduplikují.
Aktivační průvodce je popsán v kapitole
[Aktivace účetnictví](64_Aktivace_ucetnictvi.md).

Účty jsou vždy oddělené podle firmy (`supplier_id`). API a repozitář při každém
čtení i zápisu používají aktuální firmu z požadavku; znalost číselného ID účtu
jiné firmy nestačí k jeho načtení nebo změně.

## 62.3 Syntetické a analytické účty

- **Syntetický účet** je zpravidla třímístný účet standardní osnovy, například
  `311` nebo `501`.
- **Analytický účet** je firemní podúčet syntetiky, například `311100` nebo
  `501200`.

Tlačítko **Nová analytika** otevře formulář:

- aktivní syntetický rodič,
- unikátní kód o délce 3–10 znaků; povolené jsou číslice, písmena a tečka,
- název analytiky.

Analytika dědí **typ účtu a obvyklou stranu** po rodiči. Web proto tato pole
nenabízí. Kód musí být v rámci firmy unikátní. Nový účet vzniká jako aktivní.
Novou syntetiku nelze založit tímto formulářem; pro řízený hromadný přenos
slouží import.

## 62.4 Aktivace a deaktivace

Akce **Deaktivovat** účet nemaže. Historické řádky deníku, výpis účtu a výkazy
zůstávají beze změny. Opětovná akce **Aktivovat** účet vrátí do nabídek.

Deaktivovaný účet nelze nově použít:

- v ručním zápisu nebo šabloně,
- jako firemní předkontaci,
- při automatickém zaúčtování dokladu,
- jako cílový účet nového majetku.

Pokud starší pravidlo na mezitím deaktivovaný účet stále odkazuje, zaúčtování
nespadne na jiný účet potichu. `PostingService` vrátí chybu a účetní musí účet
znovu aktivovat nebo opravit pravidlo.

Účty se záměrně nemažou. Kód účtu je součást průkazné účetní stopy a mohou na něj
odkazovat deník, šablony, předkontace, střediska nebo karty majetku.

## 62.5 Import a export

Tlačítka **Export** a **Import** pracují s XLSX nebo CSV. Export zahrnuje i
neaktivní účty, aby šel použít jako úplný round-trip. Sloupce jsou `ucet`,
`nazev`, `typ`, `strana`, `nadrizeny_ucet` a `aktivni`; XLSX obsahuje také list
s nápovědou.

Import probíhá ve třech krocích:

1. nahrání `.xlsx` nebo `.csv` do 2 MB,
2. serverový **dry-run** s řádky Založit / Aktualizovat / Přeskočit / Chyba,
3. potvrzení stejného validovaného obsahu.

Náhled ukazuje změny pole po poli a lze jej omezit na problémy. Obsahuje-li
chybu, potvrzení je zablokované. Import **nikdy nemaže**.

### 62.5.1 Pravidla importu

- Identitou je kód účtu.
- `nadrizeny_ucet` označuje analytiku; rodič musí být syntetika existující
  v databázi nebo založená dříve ve stejném souboru.
- U existujícího účtu lze změnit jen název a aktivní stav. Typ, obvyklou stranu
  ani rodiče nelze přepsat.
- U nové syntetiky jsou povinné typ a název.
- Nová analytika přebírá typ a stranu z rodiče; odlišné hodnoty v souboru jsou
  nahlášeny.
- Deaktivace účtu používaného aktivní předkontací nebo nevyřazeným majetkem
  dostane varování. Import může projít, ale navazující automatické účtování
  bude vyžadovat opravu odkazu.

Zápis importu je tenantově omezený a probíhá až po úspěšném náhledu. Selhání
jednoho řádku v potvrzeném souboru nesmí vytvořit neohlášený napůl použitelný
rozvrh; výsledný report vždy uvádí založené, změněné, přeskočené a chybné řádky.

## 62.6 Ochrany při účtování

Vedle existence a aktivity účtu hlídá centrální účetní služba i následující
invarianty:

- účty **701, 702 a 710** lze použít jen v řízeném závěrkovém nebo otevíracím
  zápisu,
- podrozvahové účty 75x/79x se nesmějí v jednom zápisu míchat s rozvahovým či
  výsledkovým účtem,
- každý zápis musí být vyrovnaný na haléř,
- datum musí patřit do otevřeného období a nesmí spadat do zámku účtování
  k datu.

Tyto kontroly běží na backendu i při volání API. Omezení webového výběru proto
nelze obejít vlastním požadavkem.

## 62.7 Karta účtu

Kliknutí na řádek účtu (nebo na jeho kód) otevře **kartu účtu** — rozcestník
pro procházení účetnictví po jednom účtu.

Karta obsahuje:

- **počáteční stav, obrat MD, obrat Dal a konečný zůstatek** za zvolený rozsah;
  počítají se stejně jako v [Hlavní knize](48_Hlavni_kniha.md) a v opisu účtu,
  u syntetiky včetně pohybů jejích analytik,
- **kmenová data** — kód, typ, obvyklá strana, syntetika/analytika, aktivita a
  počet analytik,
- **analytiky** se zůstatky za tentýž rozsah; každá je odkaz na svoji vlastní
  kartu,
- u analytiky odkaz na **nadřízenou syntetiku**.

Rozsah se nastavuje poli **Od / Do** nebo zkratkou na účetní období. Tlačítka
v hlavičce vedou na [Opis účtu](48_Hlavni_kniha.md#485-opis-účtu), do
[Hlavní knihy](48_Hlavni_kniha.md) (kniha účet sama rozbalí) a do
[Účetního deníku](45_Ucetni_denik.md) filtrovaného na tento účet a rozsah;
u syntetiky pokrývá filtr i její analytiky.

Karta je jen ke čtení — název a aktivitu se mění v seznamu účtů.

## 62.8 Oprávnění a chyby

Čtení a export vyžadují oprávnění `accounting`. Založení analytiky, změna
aktivity a import vyžadují zápisové účetní oprávnění; readonly role vidí pouze
seznam a export.

Nejčastější chyby:

- **duplicitní kód** — zvol jinou analytiku nebo oprav import,
- **neexistující či neaktivní rodič** — aktivuj syntetiku nebo změň vazbu,
- **účet je používán** — ponech jej aktivní, případně nejdřív přesměruj
  předkontace a majetek,
- **režim není podvojné účetnictví** — dokonči aktivaci nebo použij funkce
  daňové evidence.

> [!TIP]
> Před větší změnou udělej export, uprav rozvrh v tabulkovém procesoru a nejprve
> projdi dry-run. Je bezpečnější opravit varování v náhledu než až chybu při
> zaúčtování dokladu.
