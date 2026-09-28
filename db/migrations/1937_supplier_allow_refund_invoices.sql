-- MyÚčto — vyúčtování s výsledkem k vyplacení (přeplatek zákazníkovi).
--
-- Přepínač dodavatele, default vypnuto: bez něj zůstává kontrola kladné částky
-- k úhradě beze změny a vratky se neukazují v platebních příkazech ani v pokladně.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS allow_refund_invoices TINYINT(1) NOT NULL DEFAULT 0;
