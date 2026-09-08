-- Neaktivní archivní identita chybějícího dokladu není živým cílovým ID.
ALTER TABLE bank_match_audit
  ADD COLUMN IF NOT EXISTS archived_document_references JSON NULL;

ALTER TABLE bank_match_suggestions
  ADD COLUMN IF NOT EXISTS archived_document_references JSON NULL;
