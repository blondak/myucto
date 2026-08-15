-- 1373: whitelist odesílatele u seedovaného globálního provideru České spořitelny
--
-- PROČ: `0098_seed_ceska_sporitelna_provider.sql` naseedoval providera s prázdným
-- `sender_whitelist`, protože tehdy prázdný whitelist znamenal „věř komukoliv".
-- `1289_bank_email_notice_secure_defaults.sql` to obrátilo na fail-closed
-- (`AbstractBankEmailNoticeParser::senderAllowed`) a řádek v původním nebezpečném
-- stavu vypnula. Whitelist ale nikdo nedoplnil, takže z provideru zůstal řádek,
-- který NIKDY nic nenaparsuje: `supports()` skončí na prázdném whitelistu dřív, než
-- se podívá na tělo, a scan každého avíza padne na `parse_failed` s hláškou
-- „Pro e-mail nebyl nalezen žádný aktivní parser provider". Uživatel to neumí
-- spravit z produktu: definici globálního provideru `saveProvider()` needituje
-- (patří všem dodavatelům) a UI u něj z téhož důvodu nenabízí ani Editovat.
--
-- Doména `csas.cz` je odesílatel avíz České spořitelny (položka bez „@" matchuje
-- doménu včetně subdomén, tedy i `mail.csas.cz`, a je end-anchored, takže
-- `csas.cz.evil.example` neprojde). Bezpečnostní vlastnost z 1289 zůstává —
-- provider má odesílatele omezeného, jen ho konečně má vyplněného.
--
-- ZAPÍNÁME ZPĚT, protože důvod vypnutí tímhle mizí. 1289 vypínala výhradně řádek
-- „globální, zapnutý, bez whitelistu i bez patternu předmětu" — tedy ten, který
-- věřil komukoliv. Se správným whitelistem je ČS ve stejné pozici jako všechny
-- ostatní banky, které mají vlastní parser třídu (Fio, ČSOB, RB, Air Bank, Moneta,
-- UniCredit, Creditas): ty jsou v produktu trvale k dispozici a odesílatele si
-- ověřují v kódu. Vypnutá ČS byla jediná výjimka, a to kvůli chybějícím datům,
-- ne kvůli rozhodnutí. Provider se navíc uplatní jen tam, kde si ho dodavatel sám
-- namapoval na měnu a schránku, a per-supplier override (1289) dál přebíjí `enabled`
-- pro toho, kdo ho vypnutý mít chce.
--
-- NEPŘEPISUJEME nic, čeho se někdo dotkl: cílíme výhradně na řádek pořád v tom
-- stavu, v jakém ho 1289 nechala (vypnutý, prázdný whitelist, bez patternu
-- předmětu). Kdo si mezitím doplnil vlastní adresu nebo si providera vědomě zapnul,
-- o své nastavení nepřijde. Druhý běh migrace už žádný řádek nenajde → re-run safe.

SET NAMES utf8mb4;

UPDATE bank_email_notice_providers
   SET sender_whitelist = 'csas.cz',
       enabled = 1
 WHERE supplier_id IS NULL
   AND code = 'ceska-sporitelna'
   AND enabled = 0
   AND (sender_whitelist IS NULL OR TRIM(sender_whitelist) = '')
   AND (subject_pattern IS NULL OR TRIM(subject_pattern) = '');
