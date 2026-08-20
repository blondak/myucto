-- MyÚčto.cz — měkká vazba ručního zápisu na existující doklad
--
-- PROČ NOVÁ TABULKA, a ne source_type/source_id na hlavičce zápisu:
-- dvojice (source_type, source_id) v `journal_entries` znamená „TENTO zápis JE
-- zaúčtování toho dokladu". Visí na ní UNIQUE (supplier_id, source_type, source_id)
-- z migrace 1007 (idempotence PostingService), zámek booked_at, počítadlo
-- nezaúčtovaných dokladů, storno větev i mazání zápisu s rollbackem dokladu.
-- Kdyby ruční zápis dostal source_type='invoice', buď spadne na duplicitní klíč
-- proti skutečnému zaúčtování faktury, nebo se fakturou začne tvářit v idempotenci
-- a v uzávěrkových kontrolách. Vazba „souvisí s dokladem" je proto ČISTĚ INFORMATIVNÍ
-- a bydlí mimo hlavičku — účetní tím nic nezaúčtuje, jen zdokumentuje souvislost
-- (dohadné položky, kurzové rozdíly, přeúčtování, opravy).
--
-- N:N ZÁMĚRNĚ: jeden ruční zápis typicky narovnává víc dokladů a naopak k jednomu
-- dokladu může vzniknout víc ručních zápisů (oprava + doúčtování).
--
-- doc_id NEMÁ FK — je polymorfní (4 různé tabulky). Osiřelou vazbu (doklad smazán)
-- čtecí vrstva tiše přeskočí, stejně jako to už dělá JournalLinkService::hydrate()
-- u dokladů jiného tenanta. Tenanta na straně ZÁPISU drží složené FK
-- (supplier_id, entry_id) → journal_entries(supplier_id, id), vzor migrace 1122;
-- na straně DOKLADU ho ověřuje aplikace při vzniku vazby (JournalDocumentLinkRepository).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entry_document_links (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  entry_id    BIGINT UNSIGNED NOT NULL,
  doc_type    ENUM('invoice','purchase_invoice','cash','bank') NOT NULL
                 COMMENT 'Typ dokladu; hodnoty zrcadlí source_type deníku',
  doc_id      BIGINT UNSIGNED NOT NULL COMMENT 'ID dokladu dle doc_type (bez FK — polymorfní)',
  note        VARCHAR(255) NULL COMMENT 'Proč spolu souvisí (volitelné)',
  created_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jedl_entry_doc (supplier_id, entry_id, doc_type, doc_id),
  KEY idx_jedl_doc (supplier_id, doc_type, doc_id),
  CONSTRAINT fk_jedl_entry    FOREIGN KEY (supplier_id, entry_id)
      REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_jedl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_jedl_user     FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
