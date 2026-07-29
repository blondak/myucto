# Přispívání do MyÚčto.cz

Pull requesty, hlášení chyb i náměty vítáme. Díky, že chcete MyÚčto zlepšovat!

## Licenční pravidla příspěvků (důležité)

- Odesláním pull requestu poskytujete celý svůj příspěvek pod licencí **MIT** —
  včetně práva držitele práv (MyWebdesign.cz s.r.o.) i kohokoli dalšího jej
  užívat, upravovat, šířit a **komerčně provozovat bez nároku na honorář** či
  jinou odměnu.
- Potvrzujete, že jste autorem příspěvku (nebo máte právo jej takto poskytnout)
  a že neobsahuje kód třetích stran pod licencí neslučitelnou s MIT.
- Pravidlo platí i pro příspěvky do proprietárních částí produktu: váš
  příspěvek je licencován MIT, výsledný celek zůstává v kombinovaném licenčním
  režimu podle [LICENCE.txt](LICENCE.txt).
- Pull requesty, které tato pravidla nesplňují, nemůžeme přijmout.

## Odměna za významné příspěvky

Autorům **významných pull requestů** nabízíme jako poděkování **doživotní
licenci MyÚčto zdarma** pro osobní použití nebo vlastní firmu (jedna instalace,
nepřenosná, nevztahuje se na provoz pro třetí strany). O významnosti příspěvku
rozhoduje držitel práv — zpravidla se ozveme sami u PR, které přinášejí novou
funkčnost, podstatné opravy nebo dlouhodobou údržbu. Připomenout se můžete na
[info@myucto.cz](mailto:info@myucto.cz).

## Jak poslat pull request

1. Forkněte repozitář a založte tematickou větev; posílejte menší, zaměřené PR.
2. Dodržujte konvence repozitáře:
   - databázové změny výhradně idempotentními migracemi přes
     `php api/bin/migrate.php` (SQL nikdy ručně),
   - po změně `web/src` commitněte i aktualizovaný `dist/`,
   - po změně veřejného API aktualizujte `api/openapi.yaml`,
   - po změně uživatelské funkce aktualizujte kapitolu manuálu a spusťte
     `php tools/generateManualHtml.php`,
   - texty UI a manuálu česky.
3. Před odesláním ověřte:

   ```powershell
   Set-Location api; php vendor/bin/phpunit
   Set-Location web; pnpm type-check; pnpm build
   ```

4. V popisu PR stručně uveďte co a proč se mění a jak jste to otestovali.

## Hlášení chyb a bezpečnost

- Chyby a náměty hlaste přes issues — uveďte verzi, prostředí a postup
  reprodukce.
- **Bezpečnostní chyby nikdy neoznamujte veřejně** — postupujte podle
  [SECURITY.md](SECURITY.md).
