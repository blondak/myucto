# 37. Kniha DPH

### Cesta: `Daně → Kniha DPH`

Kniha DPH je interní kontrolní sestava za měsíc nebo čtvrtletí. Není formulářem EPO,
neodesílá se správci daně a její PDF se neukládá do Archivu podání. Slouží k dohledání,
z jakých dokladů a řádků vzniklo [přiznání DPH a kontrolní hlášení](36_Vykazy_DPH.md).

## Zdroj dat a rozhodné období

Kniha používá stejnou řádkovou evidenci DPH jako DPHDP3 a DPHKH1, a proto je stejná
v daňové evidenci i v podvojném účetnictví. Zahrnuje:

- položky vystavených faktur podle DUZP, případně podle data vystavení,
- položky přijatých faktur; u tuzemského odpočtu se respektuje také datum přijetí,
- řádky DPH zaúčtovaných pokladních daňových dokladů,
- samovyměření reverse charge a jeho případný zrcadlový nárok na odpočet.

Datum úhrady nerozhoduje o období DPH. Cizí měny se převádějí kurzem uloženým na
dokladu. Chybějící nebo neplatný kurz či klasifikace je důvodem k opravě zdrojového
dokladu, ne k ručnímu přepsání knihy.

Pracovní kniha záměrně zahrnuje i koncepty, které jsou v PDF označené. Stornované
doklady a zálohové výzvy se nezahrnují. Ostré DPHDP3 a KH naopak koncepty neobsahují,
proto se před podáním ujisti, že v knize nezůstaly neuzavřené doklady.

## Členění knihy

Řádky se seskupují podle dokladu, klasifikace a sazby. Kód sekce kombinuje pracovní
skupinu a řádek přiznání, například:

- `15.xxx` — přijatá tuzemská plnění,
- `36.xxx` — uskutečněná plnění,
- `43.xxx` — samovyměření a zrcadlový odpočet reverse charge,
- `47.047` — doplňující hodnota pořízeného dlouhodobého majetku; do odpočtu se
  podruhé nepřičítá.

Každý řádek uvádí datum plnění, datum zaúčtování, typ a číslo dokladu, popis,
základ, DPH a celkem v Kč, protistranu a DIČ, původní číslo dokladu a účinnou
sekci KH. Doklady se řadí přirozeně podle čísla.

U poměrného odpočtu (§ 75) se základ a DPH na vstupu krátí zadaným procentem.
U kráceného odpočtu (§ 76) kniha oddělí částku do krácené skupiny; zálohový
koeficient a konečné roční vypořádání se uplatní až v DPHDP3, nikoli jako
samostatný pokladní pohyb v knize.
Plnění bez nároku nevytváří odpočet, ale u samovyměření zůstává daň na výstupu.

## Kontrola před podáním

1. Vyfiltruj stejné období jako v DPHDP3 a zkontroluj koncepty a chybějící klasifikace.
2. Porovnej součty sekcí s řádky náhledu DPHDP3. Částky se v evidenci drží na haléře,
   zatímco XML přiznání zaokrouhluje jednotlivé formulářové údaje na celé Kč; rozdíl
   několika korun proto může být jen důsledkem zákonného zaokrouhlení.
3. Zkontroluj účinnou sekci KH. Limit 10 000 Kč se posuzuje z absolutní celkové částky
   dokladu včetně DPH; přesně 10 000 Kč patří do souhrnné A.5/B.3, individuální
   A.4/B.2 je až nad limitem a vyžaduje DIČ.
4. Stáhni PDF jako pracovní podklad. Důkazem podání je až potvrzení z portálu, nikoli PDF.

Kniha neporovnává data s XML, které bylo skutečně odesláno na portál. Pokud se po
stažení podkladů změnil doklad, použij ve DPHDP3 frontu změn po podání a znovu posuď,
zda je nutné opravné nebo dodatečné podání.
