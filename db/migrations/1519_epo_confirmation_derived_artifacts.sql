-- MyÚčto.cz — odvozené artefakty z potvrzenky přímého EPO podání (ZAREP).
--
-- Dodejka z EPO je CMS/PKCS#7 v DER: bez nástroje se z ní nedá nic přečíst.
-- Přitom v ní je všechno, co účetní u podání potřebuje doložit — podepsaný obsah
-- potvrzení, echo toho, co si finanční správa u podání eviduje, certifikát pečeti
-- GFŘ (jím se podpis ověří i po expiraci vydávající autority) a v elementu
-- `<Certifikaty>` i certifikát, kterým bylo podání PODEPSÁNO — tedy doklad o tom,
-- kdo za daňový subjekt podal. Ty dva certifikáty se rozlišují: `confirmation_signer_cert`
-- je pečeť správce daně, `submission_signer_cert` je ZAREP podepisující osoby.
-- U asistovaného podání tyhle soubory přiloží uživatel ručně přes „Nahrát výstupy
-- z EPO"; u přímého podání je aplikace umí vytáhnout sama, takže je ukládá rovnou.
--
-- Idempotence: MODIFY COLUMN nastavuje cílovou definici, opakované spuštění je no-op.

SET NAMES utf8mb4;

ALTER TABLE tax_submission_artifacts
  MODIFY COLUMN artifact_kind ENUM(
    'source_xml','epo_xml','signed_submission_p7s','confirmation_p7s',
    'confirmation_xml','epo_echo',
    'confirmation_signer_cert','submission_signer_cert',
    'epo_error_xml','epo_status_xml','receipt_pdf','other'
  ) NOT NULL;
