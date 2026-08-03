# 56a. Úplné mzdy — aktivace, zaměstnavatel a zaměstnanci

**Cesta: `Mzdy`**

Samostatná sekce Mzdy rozšiřuje zaměstnance z existující Mzdové rekapitulace
o personální profil a více pracovních vztahů. Nevytváří druhý seznam lidí a
nemění dosavadní Mzdovou rekapitulaci.

> [!IMPORTANT]
> V této fázi je v novém modulu dostupná aktivace, nastavení zaměstnavatele
> a mzdových účtáren, přehled podporovaného rozsahu a read-only kontrola
> zaměstnanců a pracovních vztahů. Výpočet úplné mzdy, uživatelské výplatní
> dokumenty a elektronická podání zatím nejsou určeny k ostrému použití.
> Pro zaúčtování nadále používej [Mzdovou rekapitulaci](56_Mzdy.md).

## 56a.1 Zapnutí pro firmu

V **Firma → Nastavení** je přepínač **Vést mzdy**. Ve výchozím stavu je zapnutý
a nyní není navázaný na samostatnou licenci. Je-li vypnutý, sekce Mzdy se skryje
z menu a její přímé adresy nejsou dostupné.

Na přehledu mezd zvolíš první měsíc, který má v budoucnu zpracovat nový modul.
Starší měsíce mohou zůstat v Mzdové rekapitulaci. Jeden měsíc však nelze
zpracovat současně oběma cestami.

Rozpracovanou aktivaci lze zrušit, dokud je pouze ve stavu nastavení. Ostrý
začátek se nezruší obyčejným přepínačem, aby nezmizely vazby na uzavřené mzdy,
platby, dokumenty nebo podání.

## 56a.2 Podporovaný rozsah

Přehled mezd ukazuje podporované roky a schopnosti modulu. Stav má tento význam:

- **Podporováno** — funkce má implementované kontroly pro uvedený rozsah;
- **Ruční kontrola** — systém připraví podklady, ale výsledek vyžaduje odborné
  posouzení;
- **Nepodporováno** — funkci nelze použít pro ostrý mzdový běh.

Označení na přehledu je bezpečnostní hranice. Modul nesmí chybějící pravidlo
nahradit nejbližším rokem nebo odhadem.

## 56a.3 Nastavení zaměstnavatele

V **Mzdy → Nastavení zaměstnavatele** se evidují registrační a kontaktní údaje
pro mzdovou agendu. Firma může mít více mzdových účtáren, ale právě jedna
aktivní účtárna musí být označena jako výchozí. Každá účtárna má vlastní kód,
název a vlastní variabilní symbol pro platby sociálního pojištění. Pole
**Registrační číslo zaměstnavatele** slouží pro evidenci a podání; není
variabilním symbolem platby.

V části **Účty zdravotních pojišťoven** se pro každou pojišťovnu eviduje
zaměstnavatelský variabilní symbol společně s účtem, měnou a obdobím platnosti.
Ulož také druh ověřovacího zdroje, jeho referenci a datum ověření. V seznamu se
bankovní účet zobrazuje jen maskovaně. Změnu samotného účtu nebo začátku
platnosti založ jako nový historický záznam; u existujícího záznamu lze bezpečně
upravit název, platební symboly, konec platnosti a údaje o ověření. Období stejné
pojišťovny a měny se nesmějí překrývat.

Osobní variabilní symbol ČSSZ a číslo pojištěnce OSVČ v obecném nastavení firmy
zůstávají určena pro vlastní odvody fyzické osoby. Platby zaměstnavatele je
nepřebírají. Automatické návrhy a rozpoznání bankovních plateb používají aktivní
mzdovou účtárnu a účet pojišťovny platný k datu platby; nejednoznačný nebo
historický údaj zůstane k ručnímu posouzení.

Na stejné stránce se nastavují výchozí účty automatického zaúčtování. Samostatně
se rozlišuje mzda zaměstnance mimo výkon funkce, příjem společníka a odměna za
výkon funkce člena orgánu. Dále se vybírají účty pojistného, daně a ostatních
srážek. Nabídka obsahuje jen aktivní účty vhodného typu z účtového rozvrhu firmy.

Změny se ukládají s kontrolou souběžné editace. Pokud mezitím nastavení změnil
jiný uživatel, aplikace načte aktuální data a vyžádá nové potvrzení změn.

## 56a.4 Společný seznam zaměstnanců

V **Mzdy → Zaměstnanci** se zobrazují stejné karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se proto týká
téže osoby; žádné slučování duplicitních karet není potřeba.

Seznam ukazuje:

- aktivní nebo neaktivní stav osoby;
- zda její rozšířený mzdový profil vyžaduje doplnění;
- počet a druh pracovních vztahů;
- původní vztah převzatý z Mzdové rekapitulace.

Tlačítkem **Zobrazit vztahy** rozbalíš detail. Na telefonu se seznam automaticky
mění z tabulky na karty.

## 56a.5 Pracovní vztah a předkontace

Jedna osoba může mít více samostatných právních vztahů. Rozlišení je důležité
pro výpočet, podání i účetnictví:

| Druh vztahu | Hrubý náklad | Závazek |
|---|---:|---:|
| pracovní poměr mimo výkon funkce, DPP, DPČ | 521 | 331 |
| příjem společníka ze závislé činnosti | 522 | 366 |
| odměna za výkon funkce člena orgánu | 523 | 366 |
| pojistné hrazené zaměstnavatelem | 524 | 336 |

Odměna jednatele za výkon funkce tedy není totéž co pracovní poměr jednatele
mimo výkon funkce ani jiný příjem společníka. Souběh se vede jako více vztahů
jedné osoby.

Převzatý legacy vztah zachovává dosavadní kontaci Mzdové rekapitulace. Před
ostrým použitím úplných mezd zkontroluj, zda právní titul odpovídá skutečnosti;
zejména starší karta „jednatel-společník“ sama nerozliší smlouvu o výkonu funkce
od ostatní závislé činnosti.

## 56a.6 Oprávnění a citlivé údaje

Sekci mohou číst pouze interní role s oprávněním `payroll`. Nastavení aktivace
a zaměstnavatele vyžaduje `payroll.settings`. API nového modulu je dostupné
jen z přihlášené webové relace, ne přes běžný bearer token.

Běžný seznam ani detail neposílá rodné číslo, adresu nebo bankovní účet.
Citlivé mzdové identifikátory se ukládají kontextově šifrované pro konkrétní
firmu a osobu; vyhledávací otisk nelze použít ke spojování stejné hodnoty mezi
firmami. Citlivé hodnoty a mzdové částky se redigují z provozních logů.

## 56a.7 Vztah k Mzdové rekapitulaci

Mzdová rekapitulace zůstává součástí základní agendy na adrese
**Účetnictví → Mzdová rekapitulace**. Její formulář, automatické měsíční
zaúčtování a mzdový list fungují dál beze změny a nejsou podmíněné budoucí
licencí úplných mezd.

Nový modul nad společnou kartou pouze doplňuje další údaje. Ochrana období
zabrání tomu, aby stejnou firmu a měsíc uzavřela legacy rekapitulace i nový
mzdový běh.
