-- MyUcto.cz -- dynamicke role a jemnozrnna opravneni (RBAC).
--
-- Prvni faze ponechava legacy users.role a user_suppliers.role pro diagnostiku
-- a rollback aplikace. Novy kod pouziva vyhradne ciselne role_id.
--
-- DDL je opakovatelne i po padu uprostred migrace. Seed a datove backfilly
-- tvori jeden atomicky DML blok. Novy superadmin radek je jednorazovy bootstrap
-- marker: pri opakovanem spusteni se neobnovi pozdeji smazane presety ani se
-- znovu neprideli vsechny firmy uzivateli, kteremu je administrator odebral.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_key  VARCHAR(32) NULL,
  name        VARCHAR(120) NOT NULL,
  role_type   ENUM('superadmin','staff','client') NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_system_key (system_key),
  KEY idx_roles_active_type (is_active, role_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  access_level   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_key),
  CONSTRAINT fk_roleperm_role
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT chk_roleperm_level CHECK (access_level IN (0,1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role_id INT UNSIGNED NULL AFTER role,
  ADD KEY IF NOT EXISTS idx_users_role_id (role_id),
  ADD CONSTRAINT fk_users_role
    FOREIGN KEY IF NOT EXISTS (role_id) REFERENCES roles(id);

ALTER TABLE user_suppliers
  ADD COLUMN IF NOT EXISTS role_id INT UNSIGNED NULL AFTER role,
  ADD KEY IF NOT EXISTS idx_usersup_role_id (role_id),
  ADD CONSTRAINT fk_usersup_role
    FOREIGN KEY IF NOT EXISTS (role_id) REFERENCES roles(id);

START TRANSACTION;

INSERT INTO roles (system_key, name, role_type, is_active)
SELECT 'superadmin', 'Superadmin', 'superadmin', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE system_key = 'superadmin');

SET @rbac_bootstrap := ROW_COUNT();

INSERT INTO roles (system_key, name, role_type, is_active)
SELECT seed.system_key, seed.name, seed.role_type, 1
FROM (
  SELECT 'accountant' AS system_key, 'Účetní' AS name, 'staff' AS role_type
  UNION ALL SELECT 'readonly', 'Pouze pro čtení', 'staff'
  UNION ALL SELECT 'client', 'Klient', 'client'
) AS seed
WHERE @rbac_bootstrap = 1;

CREATE TEMPORARY TABLE rbac_permission_presets (
  system_key     VARCHAR(32) NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  access_level   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (system_key, permission_key),
  CONSTRAINT chk_rbac_preset_level CHECK (access_level IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy accountant: plny zapis do beznych firemnich agend, jen cteni
-- dashboardu, exportnich akci a nastaveni firmy. Globalni administrativa zustava
-- napevno pouze superadminovi a nema permission klice.
INSERT INTO rbac_permission_presets (system_key, permission_key, access_level) VALUES
('accountant','dashboard',1),
('accountant','dashboard.portfolio',1),
('accountant','clients',2),
('accountant','clients.create',2),
('accountant','clients.archive',2),
('accountant','clients.public_links',2),
('accountant','projects',2),
('accountant','projects.create',2),
('accountant','projects.archive',2),
('accountant','invoices',2),
('accountant','invoices.create',2),
('accountant','invoices.issue',2),
('accountant','invoices.send',2),
('accountant','invoices.reminder',2),
('accountant','invoices.mark_paid',2),
('accountant','invoices.cancel',2),
('accountant','invoices.clone',2),
('accountant','invoices.delete',2),
('accountant','invoices.approval',2),
('accountant','purchase_invoices',2),
('accountant','purchase_invoices.create',2),
('accountant','purchase_invoices.transition',2),
('accountant','purchase_invoices.scan',2),
('accountant','purchase_invoices.payment_orders',2),
('accountant','purchase_invoices.delete',2),
('accountant','recurring',2),
('accountant','recurring.create',2),
('accountant','recurring.run',2),
('accountant','recurring.pause',2),
('accountant','recurring.delete',2),
('accountant','bank',2),
('accountant','bank.import',2),
('accountant','bank.match',2),
('accountant','bank.post',2),
('accountant','bank.unpost',2),
('accountant','bank.rules',2),
('accountant','documents',2),
('accountant','documents.upload',2),
('accountant','documents.move',2),
('accountant','documents.delete',2),
('accountant','documents.restore',2),
('accountant','documents.requests',2),
('accountant','accounting',2),
('accountant','accounting.journal.write',2),
('accountant','accounting.journal.post',2),
('accountant','accounting.offsets',2),
('accountant','accounting.templates',2),
('accountant','tax_evidence',1),
('accountant','tax_evidence.classification.write',2),
('accountant','tax_evidence.export',1),
('accountant','reports',2),
('accountant','reports.finalize',2),
('accountant','reports.submit',2),
('accountant','reports.reopen',2),
('accountant','reports.export',1),
('accountant','cash',2),
('accountant','cash.document.write',2),
('accountant','cash.close',2),
('accountant','assets',2),
('accountant','assets.write',2),
('accountant','assets.depreciation',2),
('accountant','assets.dispose',2),
('accountant','stock',2),
('accountant','stock.items.write',2),
('accountant','stock.documents.write',2),
('accountant','stock.take',2),
('accountant','stock.close',2),
('accountant','eshop',2),
('accountant','eshop.write',2),
('accountant','logbook',2),
('accountant','logbook.write',2),
('accountant','logbook.import',2),
('accountant','logbook.delete',2),
('accountant','settings.company',1),
('accountant','utilities',1),
('accountant','utilities.export',1),
('accountant','utilities.archives',1),
('accountant','settings.signing',2),
('accountant','profile',2),
('accountant','profile.tokens',1);

-- Legacy readonly: cteni vsech beznych firemnich modulu a exportu. Akcni
-- klice se neukladaji, tedy zustavaji fail-closed na none.
INSERT INTO rbac_permission_presets (system_key, permission_key, access_level) VALUES
('readonly','dashboard',1),
('readonly','dashboard.portfolio',1),
('readonly','clients',1),
('readonly','projects',1),
('readonly','invoices',1),
('readonly','purchase_invoices',1),
('readonly','recurring',1),
('readonly','bank',1),
('readonly','documents',1),
('readonly','documents.requests',1),
('readonly','accounting',1),
('readonly','tax_evidence',1),
('readonly','tax_evidence.export',1),
('readonly','reports',1),
('readonly','reports.export',1),
('readonly','cash',1),
('readonly','assets',1),
('readonly','stock',1),
('readonly','eshop',1),
('readonly','logbook',1),
('readonly','settings.company',1),
('readonly','utilities',1),
('readonly','utilities.export',1),
('readonly','utilities.archives',1),
('readonly','profile',2),
('readonly','profile.tokens',1);

-- Legacy client: pracovni cyklus vlastnich vydanych/prijatych dokladu,
-- kontaktu a pravidelne fakturace. Ucetnictvi, banka, reporty, DMS, sklad,
-- globalni nastroje a verejne odkazy zustavaji none.
INSERT INTO rbac_permission_presets (system_key, permission_key, access_level) VALUES
('client','clients',2),
('client','clients.create',2),
('client','clients.archive',2),
('client','invoices',2),
('client','invoices.create',2),
('client','invoices.issue',2),
('client','invoices.send',2),
('client','invoices.reminder',2),
('client','invoices.mark_paid',2),
('client','invoices.cancel',2),
('client','invoices.clone',2),
('client','invoices.delete',2),
('client','invoices.approval',2),
('client','purchase_invoices',2),
('client','purchase_invoices.create',2),
('client','purchase_invoices.transition',2),
('client','purchase_invoices.delete',2),
('client','recurring',2),
('client','recurring.create',2),
('client','recurring.run',2),
('client','recurring.pause',2),
('client','recurring.delete',2),
('client','settings.company',1),
('client','profile',2);

INSERT INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, p.access_level
FROM rbac_permission_presets p
JOIN roles r ON r.system_key = p.system_key
WHERE @rbac_bootstrap = 1;

DROP TEMPORARY TABLE rbac_permission_presets;

-- Vychozi role vsech legacy uzivatelu. Backfill je umyslne omezen bootstrap
-- flagem, aby pozdejsi opakovani migrace neprepsalo vlastni role.
UPDATE users u
JOIN roles r ON r.system_key = CASE u.role
  WHEN 'admin' THEN 'superadmin'
  WHEN 'accountant' THEN 'accountant'
  WHEN 'readonly' THEN 'readonly'
  WHEN 'client' THEN 'client'
  ELSE NULL
END
SET u.role_id = r.id
WHERE @rbac_bootstrap = 1
  AND u.role_id IS NULL;

-- Legacy override se aplikoval pouze na staff role. Stray accountant/readonly
-- hodnota u admina nebo clienta se drive ignorovala a musi zustat zdedena NULL;
-- prevod na nekompatibilni typ by klienta v novem fail-closed modelu zamkl.
UPDATE user_suppliers us
JOIN users u ON u.id = us.user_id AND u.role IN ('accountant','readonly')
JOIN roles r ON r.system_key = us.role
SET us.role_id = r.id
WHERE @rbac_bootstrap = 1
  AND us.role_id IS NULL
  AND us.role IS NOT NULL;

-- Dosud znamenal prazdny membership pro accountant/readonly pristup ke vsem
-- firmam. Zachovame jej explicitnimi radky. Jiz omezenym uzivatelum, klientum
-- ani superadminum zadne firmy nepridavame.
INSERT INTO user_suppliers (user_id, supplier_id, role, role_id)
SELECT u.id, s.id, NULL, NULL
FROM users u
CROSS JOIN supplier s
WHERE @rbac_bootstrap = 1
  AND u.role IN ('accountant','readonly')
  AND NOT EXISTS (
    SELECT 1 FROM user_suppliers existing WHERE existing.user_id = u.id
  );

-- Fail guard bez ulozene procedury (CREATE PROCEDURE by implicitne commitnul).
-- Temporary-table DDL v MariaDB transakci necommitne; poruseni CHECK ukonci
-- migraci a spojeni rollbackne kompletni seed i backfilly.
CREATE TEMPORARY TABLE rbac_migration_guard (
  ok TINYINT NOT NULL,
  CONSTRAINT chk_rbac_migration_complete CHECK (ok = 1)
) ENGINE=InnoDB;

INSERT INTO rbac_migration_guard (ok)
SELECT CASE WHEN @rbac_bootstrap <> 1 OR COUNT(*) = 0 THEN 1 ELSE 0 END
FROM users
WHERE is_active = 1
  AND role_id IS NULL;

DROP TEMPORARY TABLE rbac_migration_guard;

COMMIT;
