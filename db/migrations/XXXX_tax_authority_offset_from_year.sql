-- XXXX: souhrnné vykázání daní vůči finančnímu úřadu od zvoleného roku
--
-- Firma, která souhrnné vykázání (§ 58 odst. 2 vyhl. 500/2002 Sb.) začne používat až od
-- některého roku, ho v uzavřených výkazech dřívějších let nemá. Bez roku by ho aplikace
-- použila i na ně, včetně sloupce minulého období převzatého z uzavřeného výkazu.
--
-- NULL = platí pro všechna období (pokud je tax_authority_offset zapnuté).

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
    ADD COLUMN IF NOT EXISTS tax_authority_offset_from_year SMALLINT UNSIGNED NULL
        COMMENT 'první účetní období (fiscal_year) se souhrnným vykázáním daní vůči FÚ; NULL = všechna';
