# 7. První spuštění (setup wizard)

> Návod, jak po čerstvé instalaci založit první administrátorský účet, volitelně
> prvního dodavatele a ukázková data. Pro správce, který aplikaci spouští poprvé.

## 7.1 Kdy to potřebujete

- Právě jste dokončili instalaci a aplikace ještě nemá žádného uživatele.
- Chcete si před ostrým provozem vyzkoušet systém na ukázkových datech.
- Wizard hlásí problém s prostředím (verze PHP, rozšíření, MariaDB, migrace).
- Po `reset.php` začínáte znovu.

Po čerstvé instalaci je celá aplikace **zamčená na setup wizard**. Žádný jiný
endpoint kromě setup endpointů a healthchecku neodpovídá. Wizard je jednorázový:
jakmile vznikne první admin účet, wizard zmizí a obnoví se až po `reset.php`.

Převádíte-li existující instalaci MyInvoice, wizardem neprocházejte, použijte
[Převod dat z MyInvoice](06_Prevod_z_MyInvoice.md).

## 7.2 Než začnete

- Nainstalovaná aplikace s dostupnou databází ([Docker](03_Instalace_Docker.md),
  [nativně](04_Instalace_Nativni.md)).
- E-mailová adresa, která bude loginem administrátora.
- Heslo o délce nejméně 12 znaků.
- Chcete-li rovnou vyplnit dodavatele: IČO, adresu, e-mail a případně bankovní účet.
- Pro přihlášení passkey stabilní HTTPS hostname v `app.url` (lokálně je
  podporované `http://localhost`).

## 7.3 Krok za krokem: kontrola prostředí

Wizard má tři kroky (**Admin účet** → **Dodavatel** (volitelné) → **Hotovo**).
Předchází jim kontrola prostředí, která se ale ukáže jen tehdy, když je co řešit.

1. Otevřete aplikaci v prohlížeči. Vyhovuje-li prostředí, kontrola se vůbec
   nezobrazí a wizard začne krokem 1.
2. Zobrazí-li se kontrola, přečtěte si u každého nálezu naměřenou hodnotu,
   očekávanou hodnotu, dopad a nápravu.
3. Opravte problémy (v Dockeru v image a v `docker-compose.yml`, ne v `php.ini`
   na hostiteli).
4. Klikněte na **Zkontrolovat znovu**.
5. Až je vše v pořádku (nebo zbývají jen varování), pokračujte tlačítkem
   **Pokračovat na setup**, resp. **Pokračovat i přesto**.

**Jak poznáte, že je hotovo:** kontrola nehlásí žádný problém, nebo jen varování.

Kontrola ověřuje verzi PHP a rozšíření, verzi a znakovou sadu MariaDB, limity
nahrávání, práva zápisu, volné místo a nespuštěné migrace. Plánované úlohy se v
této fázi nekontrolují, na čerstvé instalaci ještě žádná neproběhla.

## 7.4 Krok za krokem: administrátor

![Setup wizard krok 1](img/03_setup_admin.webp)

Vytvoříte první uživatelský účet se systémovou rolí **Superadmin** (plná práva).

1. Vyplňte pole podle tabulky níže.
2. Zaškrtněte **Přijímám licenční ujednání a obchodní podmínky produktu MyÚčto.cz**.
   Bez zaškrtnutí nejde pokračovat.
3. Případně zaškrtněte **Vynutit vícefaktorové ověření (MFA) pro všechny uživatele**.
4. Klikněte na **Další**.

| Pole | Význam |
|---|---|
| Jméno | Vaše jméno (zobrazí se v UI a v aktivity logu) |
| E-mail | Login a adresa pro reset hesla a systémové notifikace |
| Heslo | Nejméně 12 znaků, indikátor síly (slabé / střední / silné). Bez maxima, passphrase je v pořádku. |
| Heslo znovu | Ověřovací duplicita |
| Vynutit vícefaktorové ověření (MFA) pro všechny uživatele | Po dokončení wizardu musí admin zaregistrovat passkey nebo zapnout TOTP |
| Přijímám licenční ujednání a obchodní podmínky produktu MyÚčto.cz | **Povinné.** Odkazy vedou na **Licenční ujednání** a **Obchodní podmínky** |

**Jak poznáte, že je hotovo:** wizard přejde na krok **Dodavatel**.

> [!TIP]
> Použijte passphrase ze 4-5 slov místo krátkého složitého hesla. „korelace
> medvědí dýně přístav 2026“ je odolnější vůči brute-force než „Hu1@n!“.

## 7.5 Krok za krokem: dodavatel (volitelné)

![Setup wizard krok 2](img/03_setup_dodavatel.webp)

Vyplníte údaje o prvním dodavateli (firmě nebo OSVČ), za kterého budete
fakturovat. Později můžete přidat další, viz [95. Multi-supplier](95_Multi_supplier.md).

1. Zadejte IČO a klikněte vedle na **Načíst z ARES**. Předvyplní se název, DIČ,
   adresa a právní forma.
2. Zkontrolujte a doplňte ostatní pole podle tabulky.
3. Volitelně zadejte první bankovní účet.
4. Chcete-li krok přeskočit, zaškrtněte **Vyplnit dodavatele později v Nastavení**.
5. Klikněte na **Dokončit setup** (viz [§ 7.7](#77-krok-za-krokem-dokonceni-setupu)).
   Zpět se vrátíte tlačítkem **Předchozí**.

| Sekce | Popis |
|---|---|
| Firma / jméno OSVČ | Bude v hlavičce všech vystavených PDF |
| IČO | **Načíst z ARES** předvyplní název, DIČ, adresu a právní formu. ARES je oficiální veřejný registr v ČR. |
| DIČ | U OSVČ neplátce nechte prázdné |
| Adresa | Ulice, město, PSČ, země pro fakturační hlavičku |
| E-mail / telefon | Kontakt pro klienta |
| Bankovní účet | První účet pro CZK: číslo a kód banky (např. `1000000005 / 0100`) |

Začnete-li pole vyplňovat, jsou ta označená * povinná.

**Jak poznáte, že je hotovo:** všechna povinná pole jsou vyplněná, nebo je
zaškrtnuto přeskočení.

> [!WARNING]
> Bankovní účet musí projít **mod-11 kontrolou** (povinný formát českých
> účtů). Zadáte-li neplatné číslo, QR platba se ve faktuře nezobrazí. Příklad
> platného testovacího čísla: `1000000005 / 0100`.

## 7.6 Krok za krokem: ukázková data (volitelné)

![Setup wizard krok 3](img/03_setup_sample.webp)

Ukázková data si můžete vygenerovat jen v kroku **Dodavatel** a jen pokud ho
nepřeskakujete.

1. V kroku **Dodavatel** zaškrtněte **Vygenerovat ukázková data**.
2. Pokračujte tlačítkem **Dokončit setup**.

**Jak poznáte, že je hotovo:** v závěrečném kroku wizard vypíše, kolik klientů,
dodavatelů, zakázek, faktur a dalších dokladů vzniklo.

### 7.6.1 Odebrání ukázkových dat

Chcete-li začít načisto jen s vlastními daty:

1. Otevřete `Firma → Nastavení`, záložku **Daně a účetnictví**, a najděte sekci
   **Ukázková data**. Zobrazí se jen tehdy, když nějaká ukázková data existují.
2. Klikněte na **Odebrat ukázková data** a potvrďte **Ano, odebrat**.

Smaže se přesně vygenerovaná sada, vaše vlastní záznamy zůstanou.

Alternativně z příkazové řádky `php api/bin/reset.php --keep-users-supplier`
smaže všechna byznys data, ale ponechá přihlášení a nastaveného dodavatele.

## 7.7 Krok za krokem: dokončení setupu

1. Klikněte na **Dokončit setup**. Wizard zobrazí závěrečný krok **Hotovo**.
2. Není-li silné MFA povinné, wizard vás **automaticky přihlásí** a přesměruje
   na [Přehled (dashboard)](10_Prehled.md).
3. Je-li MFA povinné, dostanete nejprve omezenou stránku `/setup-mfa`. Zvolte
   metodu (přístupový klíč nebo TOTP) a dokončete její registraci.

**Jak poznáte, že je hotovo:** vidíte Přehled. Při povinném MFA vznikne plný
přístup až po registraci jedné z povolených metod.

## 7.8 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Kontrola prostředí hlásí problém | Stará verze PHP, chybějící povinné rozšíření, MySQL místo MariaDB, nedostupná databáze nebo nespuštěné migrace | Opravte podle nápravy u nálezu a klikněte na **Zkontrolovat znovu** |
| Kontrola hlásí jen varování | Například chybí volitelné rozšíření | Setup jde dokončit, připomínka zůstane v hlavičce wizardu |
| Kontrolu prostředí se nepodařilo spustit | Kontrola je nedostupná, setup tím není blokovaný | Stav prostředí zkontrolujte po přihlášení v `Systém → Diagnostika` |
| Nejde pokračovat z kroku **Admin účet** | Heslo je kratší než 12 znaků, hesla se neshodují nebo není přijato licenční ujednání | Opravte pole a zaškrtněte souhlas |
| QR platba se na faktuře nezobrazuje | Bankovní účet neprošel mod-11 kontrolou | Opravte číslo účtu v `Peníze → Bankovní účty` na záložce **Měny a účty** |
| Ukázková data chcete vygenerovat až po dokončení setupu a aplikace to odmítne | Ukázková data nejdou doinstalovat zpětně | Viz [§ 7.9.2](#792-ukazkova-data) |
| Passkey nejde zaregistrovat | `app.url` nemá stabilní HTTPS hostname | Nastavte HTTPS hostname (lokálně funguje `http://localhost`) |

## 7.9 Podrobnosti a pravidla

### 7.9.1 Kontrola prostředí

- **Vyhovující prostředí** kontrolu vůbec nezobrazí a wizard začne krokem 1.
- **Varování** vás nezastaví: setup jde dokončit a připomínka zůstane v hlavičce
  wizardu.
- **Problém** (stará verze PHP, chybějící povinné rozšíření, MySQL místo
  MariaDB, nedostupná databáze, nespuštěné migrace) je potřeba opravit, jinak by
  instalace nedoběhla nebo by se rozbila při prvním použití.

U každého nálezu jsou odkazy do příslušné kapitoly manuálu. V Dockeru míří na
[3. Instalace Docker](03_Instalace_Docker.md): PHP i MariaDB se tam ladí v image
a v `docker-compose.yml`.

Stejná kontrola je i po instalaci v `Systém → Diagnostika`, kde navíc hlídá
plánované úlohy, velikost logů, strukturu databáze proti migracím a dostupnost
novější verze, viz [999. Řešení problémů](999_Reseni_problemu.md).

### 7.9.2 Ukázková data

Generovaná sada obsahuje:

- 24 klientů a 12 dodavatelů z více zemí s různými jazyky a měnami,
- 36 zakázek, 120 vystavených faktur, 12 dobropisů a 120 přijatých faktur,
- pravidelnou fakturaci, jednu pokladnu se sedmi pohyby a knihu jízd s autem,
  jízdami a tankováními.

Pro **s.r.o. nebo plátce DPH** generátor navíc automaticky zapne podvojné
účetnictví a skladovou evidenci. Založí účtový rozvrh a účetní období, sklad s
položkami a 120 příjemkami/výdejkami, dva majetky v odpisových skupinách 1 a 2,
trojici e-shopových kategorií a výrobců přiřazených skladovým kartám, šest
bankovních výpisů se 120 pohyby a všechny doklady zaúčtuje do účetního deníku.
Část faktur spáruje s bankovními úhradami, takže lze vyzkoušet také saldokonto
a párování plateb.

Ukázková data **nejdou doinstalovat zpětně**. Přeskočíte-li je a později je
budete chtít, dostanete `409 setup_done` (ochrana proti přepsání reálných
faktur). Reset přes `php api/bin/reset.php` smaže všechno a wizard se objeví znovu.

### 7.9.3 Zabezpečení účtu

Povolené jsou obě metody MFA, uživatel si na stránce `/setup-mfa` vybere. Zúžit
výběr jde až v konfiguraci přes `auth.allowed_mfa_methods`, viz
[101. Bezpečnost](101_Bezpecnost.md).

## 7.10 Co dál po setupu

1. Otevřete `Firma → Nastavení` a doplňte, co wizard nepokryl, například
   e-mailový kontakt, viz [96. Nastavení](96_Nastaveni.md).
2. V `Peníze → Bankovní účty` na záložce **Měny a účty** založte další bankovní
   účty. Fakturujete-li i v EUR, doplňte druhý účet (IBAN a BIC).
3. V `Systém → Uživatelé` přidejte další uživatele (například účetní).
4. V `Systém → E-maily a certifikáty` na záložce **E-mail šablony** upravte
   uvítací text e-mailů (faktury, upomínky).
5. Naplánujte pravidelné úlohy, viz [Po instalaci a CLI nástroje](05_Po_instalaci.md).

**Jak poznáte, že je hotovo:** firma má vyplněné údaje i bankovní účty a další
uživatelé se mohou přihlásit.

## 7.11 Související kapitoly

- [Po instalaci a CLI nástroje](05_Po_instalaci.md)
- [Přihlášení a uživatelský profil](08_Prihlaseni.md)
- [Nastavení](96_Nastaveni.md), [Multi-supplier](95_Multi_supplier.md)
- [Bezpečnost](101_Bezpecnost.md)
