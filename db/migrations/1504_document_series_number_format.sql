-- MyÚčto.cz — volitelná šablona čísla řady deníku (#22)
--
-- Řady deníku uměly jen prefix, tvar `{prefix}-{rok}-{NNNN}` byl natvrdo
-- v DocumentSeriesService::format(). Firma přebírající řadu z jiného systému
-- (např. `26HP00011`) ji takhle složit nemohla. `number_format` je per firma ×
-- řada × rok volitelná šablona se stejnými placeholdery jako u faktur
-- ({PREFIX}, {YYYY}, {YY}, {C+}); NULL = vestavěný `{PREFIX}-{YYYY}-{CCCC}`,
-- takže existující řady pokračují beze změny.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE accounting_document_series
  ADD COLUMN IF NOT EXISTS number_format VARCHAR(40) NULL
    COMMENT 'Šablona čísla ({PREFIX}/{YYYY}/{YY}/{C+}); NULL = {PREFIX}-{YYYY}-{CCCC}'
    AFTER prefix;
