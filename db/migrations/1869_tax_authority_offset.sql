-- XXXX: souhrnné vykázání daňových pohledávek a závazků vůči finančnímu úřadu
--
-- § 58 odst. 2 vyhl. 500/2002 Sb. za vzájemné zúčtování nepovažuje souhrnné vykázání
-- pohledávek a závazků vůči téže osobě se splatností do jednoho roku ve stejné měně
-- (kromě záloh). Firma, která přeplatek jedné daně a nedoplatek jiné vůči finančnímu
-- úřadu vykazuje souhrnně, to uvede v příloze. Volba per firma, výchozí vypnutá.

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
    ADD COLUMN IF NOT EXISTS tax_authority_offset TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'rozvaha: souhrnné vykázání daňových pohledávek a závazků vůči FÚ (§ 58 odst. 2 vyhl. 500/2002)';
