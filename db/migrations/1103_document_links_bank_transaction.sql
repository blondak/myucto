-- MyÚčto.cz — DMS: rozšíření polymorfní vazby document_links o bankovní pohyby
--
-- Bankovním pohybům dosud chybělo připojování dokumentů přes DMS (LinkedDocumentsPanel),
-- stejně jako to už funguje u faktur/klienta/projektu/zápisu deníku. journal_entry byl
-- do enumu doplněn už migrací 1025 (f7_upstream_links), v kódu
-- (DocumentLinkRepository::ENTITY_TYPES) ale chyběl — doplňujeme spolu s bank_transaction.
--
-- Append-only ALTER — re-list VŠECH stávajících členů enumu (ověřeno proti 1025):
-- enum('client','invoice','purchase_invoice','project','journal_entry') → + 'bank_transaction'

SET NAMES utf8mb4;

ALTER TABLE document_links
  MODIFY COLUMN entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry','bank_transaction') NOT NULL;
