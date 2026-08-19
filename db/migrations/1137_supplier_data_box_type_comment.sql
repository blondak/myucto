-- Upřesnění komentáře u `supplier.data_box_type`, aby nesváděl k záměně.
--
-- Sloupec drží druh schránky v ISDS (FO/PFO/PO/OVM). Generátor EPO `VetaP` z něj
-- dřív chybně odvozoval atribut `typ_ds` (typ daňového subjektu), což rozbilo
-- podání DPH/KH/SHV všem právnickým osobám. Typ subjektu drží `taxpayer_type`
-- a nic jiného; tenhle sloupec s daňovými výkazy nesouvisí a žádná logika na
-- něm viset nesmí — je to jen údaj pro budoucí implementaci datových schránek.
--
-- Změna je čistě metadatová, data ani typ sloupce se nemění.
--
-- ── Proč se sloupec nejdřív přidává ────────────────────────────────────────────
-- `MODIFY COLUMN` nemá variantu `IF EXISTS`, takže na instalaci, kde sloupec
-- chybí, by tahle migrace spadla na error 1054. A taková instalace existuje:
-- MyInvoice.cz svou migrací `0140_supplier_drop_data_box_type.sql` sloupec
-- zahodil (byl všude NULL a jeho jméno svádělo k té záměně výše). Při přechodu
-- z MyInvoice na MyÚčto se migrace 1000+ pouštějí nad existující databází, takže
-- by upgrade skončil právě tady.
--
-- MyÚčto sloupec používá (ISDS), proto ho pro takovou instalaci zavedeme zpátky
-- rovnou se správným typem i komentářem. Žádná data se tím neztrácejí ani
-- nepřepisují — na MyInvoice byl sloupec vždy NULL. Na instalacích, které ho
-- mají, je `ADD COLUMN IF NOT EXISTS` no-op a účinek migrace zůstává původní.

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS data_box_type VARCHAR(8) NULL
        COMMENT 'Druh schránky v ISDS (FO/PFO/PO/OVM). NESOUVISI s typem poplatnika ani s EPO typ_ds - na to je taxpayer_type.'
        AFTER cz_nace_code;

ALTER TABLE supplier
    MODIFY COLUMN data_box_type VARCHAR(8) NULL
        COMMENT 'Druh schránky v ISDS (FO/PFO/PO/OVM). NESOUVISI s typem poplatnika ani s EPO typ_ds - na to je taxpayer_type.';
