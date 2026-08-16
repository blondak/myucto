-- Index pro dedup inbox scanu podle STROJOVÉHO originálu (`source_hash`).
--
-- Scanner uměl přeskočit už zpracovaný soubor jen přes `pdf_hash` (migrace 0026c).
-- Holý `.isdoc` / `.xml` ale žádné PDF nearchivuje, takže mu `pdf_hash` zůstal prázdný
-- a každý další běh skenu ho protáhl celým importem znovu — nový doklad sice odmítl
-- unikátní klíč, ale v reportu se objevil jako `created` a počty byly k ničemu.
-- Scanner nově zdrojový artefakt archivuje (source_path/source_hash, migrace 0123)
-- a deduplikuje přes obě osy; tenhle index drží druhou z nich rychlou.
--
-- Idempotence: CREATE INDEX IF NOT EXISTS (MariaDB 10.5+).

SET NAMES utf8mb4;

CREATE INDEX IF NOT EXISTS idx_pi_source_hash ON purchase_invoices (supplier_id, source_hash);
