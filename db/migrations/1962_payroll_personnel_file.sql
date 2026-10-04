-- MyÚčto.cz — Personální spis zaměstnance: nahrané dokumenty (pracovní
-- smlouvy, dodatky, popisy pozic, …) a poznámky personalisty.
--
-- PROČ NE SEKCE DOKUMENTY (DMS)
--
-- Personální spis jsou soukromá data zaměstnance. Sekce Dokumenty je sdílené
-- firemní úložiště s vlastním oprávněním, fulltextem, náhledy a zálohou;
-- pracovní smlouva tam nepatří ani skrytá filtrem. Soubory proto leží ve
-- vlastním kořeni `storage/payroll-personnel/`, šifrované datovým klíčem
-- osoby (`payroll_document_data_keys`, stejně jako výplatní pásky), a zálohují
-- se samostatným cronem `cron-backup-personnel`. Zahození klíče při výmazu
-- osobních údajů tím znečitelní i personální spis.
--
-- Poznámky se ze stejného důvodu ukládají zašifrované (`body_ciphertext`).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_personnel_documents (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  employee_id    BIGINT UNSIGNED NOT NULL,
  category       ENUM(
                   'employment_contract','contract_amendment','job_description',
                   'termination','agreement','certificate','medical','training',
                   'other'
                 ) NOT NULL DEFAULT 'other',
  title          VARCHAR(255) NOT NULL,
  document_date  DATE NULL,
  valid_until    DATE NULL,
  note           VARCHAR(1000) NULL,
  original_name  VARCHAR(255) NOT NULL,
  mime_type      VARCHAR(127) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  file_sha256    CHAR(64) NOT NULL,
  uploaded_by    BIGINT UNSIGNED NULL,
  updated_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_personnel_document_file (supplier_id, employee_id, file_sha256),
  KEY idx_payroll_personnel_document_employee (supplier_id, employee_id, document_date),
  KEY fk_payroll_personnel_document_uploaded_by (uploaded_by),
  KEY fk_payroll_personnel_document_updated_by (updated_by),
  CONSTRAINT fk_payroll_personnel_document_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_personnel_document_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_personnel_document_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_personnel_documents
  DROP CONSTRAINT IF EXISTS chk_payroll_personnel_document_sha;
ALTER TABLE payroll_personnel_documents
  ADD CONSTRAINT chk_payroll_personnel_document_sha
    CHECK (file_sha256 REGEXP '^[0-9a-f]{64}$');

ALTER TABLE payroll_personnel_documents
  DROP CONSTRAINT IF EXISTS chk_payroll_personnel_document_validity;
ALTER TABLE payroll_personnel_documents
  ADD CONSTRAINT chk_payroll_personnel_document_validity
    CHECK (valid_until IS NULL OR document_date IS NULL OR valid_until >= document_date);

CREATE TABLE IF NOT EXISTS payroll_personnel_notes (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  employee_id      BIGINT UNSIGNED NOT NULL,
  body_ciphertext  MEDIUMTEXT NOT NULL,
  pinned           TINYINT(1) NOT NULL DEFAULT 0,
  created_by       BIGINT UNSIGNED NULL,
  updated_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_payroll_personnel_note_employee (supplier_id, employee_id, pinned, created_at),
  KEY fk_payroll_personnel_note_created_by (created_by),
  KEY fk_payroll_personnel_note_updated_by (updated_by),
  CONSTRAINT fk_payroll_personnel_note_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_personnel_note_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_personnel_note_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_personnel_notes
  DROP CONSTRAINT IF EXISTS chk_payroll_personnel_note_pinned;
ALTER TABLE payroll_personnel_notes
  ADD CONSTRAINT chk_payroll_personnel_note_pinned CHECK (pinned IN (0, 1));

-- Oprávnění „Personální spis" dostanou role, které dnes vedou zaměstnance
-- (`payroll.person.write`), včetně vlastních rolí, se stejnou úrovní. Role jen
-- pro čtení ne: pracovní smlouvy nejsou podklad, který by měl vidět každý,
-- kdo smí nahlížet do účetnictví.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT rp.role_id, 'payroll.personnel', rp.access_level
FROM role_permissions rp
WHERE rp.permission_key = 'payroll.person.write';
