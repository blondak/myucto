-- 1174: Pravidelné faktury — jednotka splatnosti (dny vs. kalendářní měsíc)
--
-- Šablona pravidelné faktury byla jediné místo, které jednotku splatnosti neznalo:
-- `RecurringForm` převzal z klienta jen `payment_due_default` a generátor pak počítal
-- natvrdo `strtotime('+N days')`. Klient se splatností „1× měsíc" tak vygeneroval
-- šablonu se splatností 1 DEN a všechny faktury z ní vyšly splatné druhý den.
--
--   * recurring_invoice_templates.payment_due_unit — 'days' nebo 'month'.
--     NULL = dědit z klienta (a ten případně z dodavatele) — stejná sémantika jako
--     u `projects.payment_due_unit` a `clients.payment_due_unit`: NULL na kterékoli
--     úrovni znamená „zděď z nadřazené". Rozhoduje o tom jediné místo v kódu,
--     `MyInvoice\Service\Invoice\PaymentDueResolver`.
--
-- Existující šablony vznikaly v době, kdy jednotka neexistovala a hodnota se VŽDY
-- interpretovala jako dny. Necháváme je proto NULL jen tam, kde by dědění vyšlo
-- nastejno, a jinak jim dny zapisujeme explicitně — jinak by šablona po nasazení
-- tiše přepnula na měsíce, jakmile má klient/dodavatel jednotku 'month'.
--
-- Idempotence: MariaDB native IF NOT EXISTS + UPDATE gate-ovaný na IS NULL. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_templates
    ADD COLUMN IF NOT EXISTS payment_due_unit ENUM('days','month') NULL DEFAULT NULL
        COMMENT 'Jednotka splatnosti šablony. NULL = dědit z klienta → dodavatele; month = payment_due_days kalendářních měsíců (overflow → poslední den měsíce).'
        AFTER payment_due_days;

-- Zpětná kompatibilita: šablony existující před touto migrací znamenaly dny.
-- Zafixujeme jim to jen tehdy, když by dědění dalo jiný výsledek (klient nebo
-- dodavatel má 'month') — jinak ať zůstane NULL a šablona dědí i do budoucna.
UPDATE recurring_invoice_templates t
   JOIN clients c ON c.id = t.client_id
   JOIN supplier s ON s.id = t.supplier_id
    SET t.payment_due_unit = 'days'
  WHERE t.payment_due_unit IS NULL
    AND COALESCE(c.payment_due_unit, s.default_payment_due_unit) = 'month';
