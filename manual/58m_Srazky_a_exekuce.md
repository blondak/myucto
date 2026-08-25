# Srážky a exekuce

## Účel

Agenda spravuje exekuce, insolvenční a další nucené srážky, jejich pořadí, rozhodné údaje, výpočet a závazky příjemcům.

## Předpoklady a oprávnění

Je nutné oprávnění `payroll.enforcement` a podle případu `payroll.insolvency`. Připravte rozhodnutí, datum doručení, pořadí, typ pohledávky, příjemce a stav řízení. Nejasný případ posuďte s odborníkem.

## Krokový postup

1. Otevřete **Mzdy → Srážky a exekuce** a založte případ u správné osoby.
   Pokud jste jej založili omylem, můžete jej v detailu smazat, dokud je stále
   ve stavu **Přijato — čeká na ověření** a nemá pohledávku, rozhodnutí, změnu
   stavu, výpočet, pohyb srážky ani platební závazek. Dříve uložené rozepsané
   ověření samo o sobě smazání neblokuje.
2. Vyplňte rozhodné datum, pořadí, druh pohledávky, spis, příjemce a částky.
3. Zkontrolujte vyživované osoby a další údaje ovlivňující nezabavitelnou částku.
4. Před uzavřením běhu projděte rozdělení srážek a zůstatky všech souběžných případů.
5. Po úhradě aktualizujte stav; změnu pořadí nebo skončení proveďte podle doložené události.

## Stavy

Případ může být evidovaný, aktivní, pozastavený, čekající v pořadí, doplacený nebo ukončený. Samotné vložení rozhodnutí nezaručuje srážku v uzavřeném běhu; rozhodují účinnost, pořadí a disponibilní částka.

## Kontroly a bezpečnost

Použijte nejvyšší míru omezení přístupu. Ověřte pravidla účinná v měsíci, pořadí doručení, přednostní charakter, nezabavitelnou částku a souběh. Citlivé listiny neukládejte do veřejných odkazů a neměňte historické rozhodné datum bez auditní stopy.

Jakmile případ vytvoří právní, mzdovou nebo platební stopu, fyzické smazání se
zablokuje. Takový případ zachovejte v historii a použijte příslušný krok
**Zastavit** nebo jiné řádné ukončení; aplikace vždy uvede konkrétní důvod blokace.

## Časté chyby

- Chybné pořadí více exekucí.
- Záměna přednostní a nepřednostní pohledávky.
- Neaktualizovaný zůstatek po externí platbě.
- Ruční srážka navíc k automaticky vypočtené částce.

## Návaznosti

Dobrovolné srážky jsou v [kapitole 58l](58l_Dohody_o_srazkach.md), účinné parametry v [58q](58q_Legislativni_pravidla_mezd.md), výpočet v [58e](58e_Mzdove_behy.md) a úhrada příjemci v [58g](58g_Platby_a_uhrady.md).
