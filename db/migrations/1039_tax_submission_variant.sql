-- 1039: Typ podání (varianta) archivovaného EPO XML — C7' (audit 2026-07, vat).
-- Odlišuje řádné (B) od opravných/dodatečných/následných podání téhož období.
-- Dodatečné DPH přiznání (D/E) se počítá jako ROZDÍL proti poslední známé dani —
-- základnou je POSLEDNÍ archivované ŘÁDNÉ/OPRAVNÉ (B/O) přiznání, proto ho musíme
-- umět odlišit od amendmentů, aby se diff nepočítal proti jinému dodatečnému.
--   dphdp3: B=řádné, O=opravné (§138), D=dodatečné (§141), E=dodatečné/opravné
--   dphkh1: B=řádné, O=řádné/opravné, N=následné, E=následné/opravné

ALTER TABLE `tax_submissions`
    ADD COLUMN IF NOT EXISTS `form_variant` CHAR(1) NOT NULL DEFAULT 'B'
        COMMENT 'B|O|D|E (dphdp3) / B|O|N|E (dphkh1) — druh podání'
        AFTER `period_quarter`;
