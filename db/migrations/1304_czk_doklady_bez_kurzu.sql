-- 1304: korunový doklad nesmí nést kurz — jednorázový úklid obou tabulek
--
-- Kurz na CZK dokladu nemění žádné číslo: PostingService::fxRate() i
-- VatLedgerService::normalize() u koruny počítají s 1.0 natvrdo. Je to past, ne chyba —
-- jakákoli budoucí agregace, která kurz použije bez pojistky na CZK, korunovou částku
-- vynásobí (přesně tenhle tvar hlídá api/tests/Architecture/ExchangeRateGuardTest.php:
-- tři místa v kódu ho kdysi měla). Vzniká to typicky importem, který u CZK dokladu
-- vyplní kurz 1.0, nebo změnou měny cizoměnového dokladu na CZK.
--
-- Zapisovací cesty jsou od migrace 1303 uzavřené (PurchaseInvoiceRepository::createDraft/
-- updateDraft nuluje kurz u CZK; ExchangeRateApplier::applyToInvoice to na vydané straně
-- dělal už dřív), tahle migrace dohání existující data.
--
-- `exchange_rate_source` se ZÁMĚRNĚ nemění: je NOT NULL, u korunového dokladu nic
-- neznamená a přepis by jen zamlžil původ dokladu, kdyby se měna vrátila zpátky.
--
-- Idempotence je v samotném WHERE (druhý běh nenajde nic k opravě), žádný marker.

SET NAMES utf8mb4;

UPDATE purchase_invoices pi
  JOIN currencies cur ON cur.id = pi.currency_id
   SET pi.exchange_rate = NULL,
       pi.exchange_rate_date = NULL
 WHERE cur.code = 'CZK'
   AND (pi.exchange_rate IS NOT NULL OR pi.exchange_rate_date IS NOT NULL);

UPDATE invoices i
  JOIN currencies cur ON cur.id = i.currency_id
   SET i.exchange_rate = NULL,
       i.exchange_rate_date = NULL
 WHERE cur.code = 'CZK'
   AND (i.exchange_rate IS NOT NULL OR i.exchange_rate_date IS NOT NULL);
