-- Upřesnění komentáře u `supplier.data_box_type`, aby nesváděl k záměně.
--
-- Sloupec drží druh schránky v ISDS (FO/PFO/PO/OVM). Generátor EPO `VetaP` z něj
-- dřív chybně odvozoval atribut `typ_ds` (typ daňového subjektu), což rozbilo
-- podání DPH/KH/SHV všem právnickým osobám. Typ subjektu drží `taxpayer_type`
-- a nic jiného; tenhle sloupec s daňovými výkazy nesouvisí a žádná logika na
-- něm viset nesmí — je to jen údaj pro budoucí implementaci datových schránek.
--
-- Změna je čistě metadatová, data ani typ sloupce se nemění.

ALTER TABLE supplier
    MODIFY COLUMN data_box_type VARCHAR(8) NULL
        COMMENT 'Druh schránky v ISDS (FO/PFO/PO/OVM). NESOUVISI s typem poplatnika ani s EPO typ_ds - na to je taxpayer_type.';
