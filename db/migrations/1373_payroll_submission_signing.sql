-- MZ-22-W06: který certifikát se používá pro podpis mzdových podání.
--
-- Certifikát sám se tu NEUKLÁDÁ. Trezor je v aplikaci jeden
-- (`epo_signing_credentials`, plní se z EPO konfigurace) a čtou z něj už
-- podpisy e-mailů i PDF. Druhé úložiště by znamenalo týž certifikát na dvou
-- místech, tedy dvě platnosti a dvě hesla, které se rozejdou v nejhorší chvíli.
-- Tahle tabulka drží jen VOLBU: který z uložených certifikátů patří ke které
-- firmě a prostředí.
--
-- Klíč je (supplier_id, environment), protože testovací certifikát bývá jiný
-- než produkční a záměna by znamenala podání odmítnuté až protokolem.
--
-- `cssz_registered_serial` je sériové číslo, pod kterým je certifikát
-- zaregistrovaný u ČSSZ. Bez něj nelze poznat, že se podepsalo tím nesprávným
-- z několika uložených certifikátů — a to selhání vypadá jako úspěch až do
-- protokolu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_submission_signing_profiles (
  supplier_id            INT UNSIGNED NOT NULL,
  environment            ENUM('production','test') NOT NULL,
  credential_id          BIGINT UNSIGNED NOT NULL,
  owner_user_id          BIGINT UNSIGNED NOT NULL,
  cssz_registered_serial VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  row_version            INT UNSIGNED NOT NULL DEFAULT 1,
  created_by             BIGINT UNSIGNED NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, environment),
  KEY idx_pssp_credential (credential_id),
  CONSTRAINT fk_pssp_supplier FOREIGN KEY (supplier_id)
    REFERENCES supplier (id) ON DELETE CASCADE,
  -- Smazání certifikátu z trezoru nesmí tiše nechat firmu s odkazem do
  -- prázdna; volba zaniká spolu s ním.
  CONSTRAINT fk_pssp_credential FOREIGN KEY (credential_id)
    REFERENCES epo_signing_credentials (id) ON DELETE CASCADE,
  CONSTRAINT fk_pssp_owner FOREIGN KEY (owner_user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_pssp_serial CHECK (
    cssz_registered_serial IS NULL
    OR cssz_registered_serial REGEXP '^[0-9A-Fa-f]{1,64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_signing_profile_update_guard//
CREATE TRIGGER trg_payroll_signing_profile_update_guard
BEFORE UPDATE ON payroll_submission_signing_profiles
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'signing profile scope is immutable';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'signing profile row_version must advance by one';
  END IF;
END//

DELIMITER ;
