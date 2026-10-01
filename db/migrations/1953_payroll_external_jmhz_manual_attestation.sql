-- Potvrzení účetní, že měsíční hlášení JMHZ za převzatý měsíc podal předchozí
-- program nebo portál ČSSZ mimo MyÚčto, i když doklad (export PAMICA, XML) v aplikaci
-- není.
--
-- PROČ TATÁŽ TABULKA: historie podání předchozím programem je jediné místo, ze kterého
-- příprava řádného hlášení (zákaz druhého řádného hlášení za měsíc), přehled povinností
-- i hlídač převzatých měsíců bez hlášení poznají, že za měsíc řádné hlášení odešlo.
-- Potvrzení je další zdroj téhož záznamu (`manual_attestation`), ne paralelní evidence.
-- Obsah dokladu nemá; v zapečetěném obsahu je jen datum, poznámka a kdo potvrdil.

SET NAMES utf8mb4;

ALTER TABLE payroll_external_jmhz_submissions
  MODIFY COLUMN source ENUM('pamica','jmhz_xml','manual_attestation') NOT NULL;

-- Poznámka účetní k potvrzení (např. „podáno portálem ČSSZ").
ALTER TABLE payroll_external_jmhz_submissions
  ADD COLUMN IF NOT EXISTS note VARCHAR(500) NULL AFTER file_name;
