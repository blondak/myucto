-- Souhrnné hlášení se archivovalo s výchozí variantou 'B', kterou EPO u tohohle
-- formuláře nezná: `shvies_forma` povoluje pouze [RN] (R = řádné, N = následné).
-- Kód 'B' je z přiznání k DPH a v souhrnném hlášení neznamená nic — snapshot tak
-- tvrdil jiný druh podání, než jaký nesl odeslaný dokument.
--
-- Historické řádky se srovnávají na 'R', protože modul dosud jinou variantu než
-- řádnou nevyráběl (`SouhrnneHlaseniBuilder` nastavuje `shvies_forma` napevno na
-- 'R'). Následná hlášení se tím nepřepisují — žádná neexistují.
--
-- Idempotence: UPDATE s podmínkou na starou hodnotu, druhé spuštění nezmění nic.

UPDATE tax_submissions
   SET form_variant = 'R'
 WHERE form_code = 'dphshv'
   AND form_variant = 'B';
