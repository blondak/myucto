-- Kód předmětu plnění (§ 92b–92f) — sladit šířku sloupce s XSD kontrolního hlášení.
--
-- `dphkh1.xsd` připouští u atributu `kod_pred_pl` na VetaA1/VetaB1 délku 0–3 znaky
-- (hodnotový výčet je v externím číselníku MFČR, XSD omezuje jen délku). Sloupec byl
-- ale `varchar(2)` — trojmístný kód z číselníku by MariaDB v nestriktním režimu TIŠE
-- uřízla na dvě číslice a do podání by odešel jiný kód, než jaký účetní zadala.
--
-- Dnes používané kódy jsou jedno- až dvoumístné, takže žádná existující data se nemění;
-- jde o odstranění tiché ztráty pro případ, kdy MFČR číselník rozšíří.

ALTER TABLE vat_classifications
    MODIFY COLUMN kod_pred_pl VARCHAR(3) NULL DEFAULT NULL
    COMMENT 'Kód předmětu plnění pro KH A.1/B.1 (§ 92b–92f), číselník MFČR k_pred_pl';
