-- MyÚčto.cz — přepínač „Vést mzdy" na firmě.
--
-- Výchozí zapnutí zachovává dostupnost modulu pro existující i nové firmy.
-- Přepínač je produktové nastavení rozsahu UI, nikoli licenční atribut.

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payroll_enabled TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'vést mzdy; 0 = modul skrytý a interní payroll API zakázané; na licenci nemá vliv'
      AFTER accounting_enabled;
