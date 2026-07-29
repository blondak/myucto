-- 1129 — Poznámky k účetnímu zápisu (1:N), deník.
--
-- Doplňuje jednořádkový `journal_entries.description` (§35) o libovolný počet
-- interních poznámek. Smysl featury: popis se u zápisů generovaných ze zdroje
-- (invoice, purchase_invoice, bank, cash…) editovat NESMÍ — je řízený dokladem —
-- ale účetní si k takovému zápisu potřebuje psát komentáře. Poznámky proto nemají
-- žádné omezení podle source_type.
--
-- ZÁMĚRNĚ BEZ SYSTEM VERSIONING, i když rodič journal_entries versioned JE
-- (stejně jako journal_entry_attachments, migrace 1024). Kdyby tahle tabulka byla
-- versioned taky, `JournalEntryHistory` by musel slučovat dvě nezávislé časové osy
-- (row_start/row_end zápisu × poznámky) a diff by přestal dávat smysl. Historie
-- poznámky se drží ručně: `deleted_at` (soft delete) + `updated_at`/`updated_by`.
--
-- FK je KOMPOZITNÍ (supplier_id, entry_id) → journal_entries(supplier_id, id),
-- tj. míří na unique `uq_je_supplier_id` z migrace 1122. Tenant je tak vynucený
-- databází, ne jen aplikací — poznámka nemůže odkazovat na zápis cizí firmy.
-- Ověřeno na klonu: FK na system-versioned rodiče projde a ON DELETE CASCADE
-- při DELETE zápisu poznámky smaže (zápis sám zůstává v historické tabulce).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entry_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_jen_entry (supplier_id, entry_id, pinned DESC, created_at DESC),
  CONSTRAINT fk_jen_entry FOREIGN KEY (supplier_id, entry_id) REFERENCES journal_entries(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_jen_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
