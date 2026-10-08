-- MyUcto.cz - Strukturovaný výsledek importní úlohy.
--
-- PROČ
--
-- Import z Fakturoidu hlásil „Dokončeno", i když část dokladů odmítl (#129).
-- Počty created/skipped/failed v řádku úlohy přepisovala každá agenda tou
-- svou, takže po doběhnutí ukazovaly jen poslední agendu, a odmítnuté doklady
-- byly dohledatelné jen v textovém logu.
--
-- import_jobs.report: JSON s počty per agenda (subjekty, vydané, přijaté),
-- přehledem nepřenesených a ke kontrole označených dokladů (číslo, důvod,
-- návod) a příznakem zkoušky nanečisto. Úlohy, které report nepíší, ho mají
-- NULL a jejich UI se nemění.

ALTER TABLE import_jobs
  ADD COLUMN IF NOT EXISTS report LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
    COMMENT 'JSON výsledek úlohy: počty per agenda a nepřenesené doklady'
    AFTER last_error;
