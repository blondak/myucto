-- MyÚčto.cz — Epic F0: členství uživatelů ve firmách (user ↔ supplier)
--
-- Do teď mohl KAŽDÝ přihlášený uživatel operovat nad LIBOVOLNÝM supplierem
-- (X-Supplier-Id header se validoval jen na existenci). Tabulka user_suppliers
-- zavádí explicitní membership: uživatel s alespoň jedním řádkem smí jen do
-- firem, které tu má přiřazené. Uživatel BEZ řádků = bez omezení (zpětná
-- kompatibilita, typicky vlastník single-tenant instalace).
--
-- role = per-supplier override globální users.role (NULL = zdědit globální).
-- Umožňuje např. globální 'accountant' s 'readonly' přístupem do jedné firmy.
-- POZOR: enum je jen 'accountant'/'readonly' — 'admin' zde NENÍ. Admin práva jsou
-- celoinstanční (endpointy /api/admin/* nejsou supplier-scoped), per-supplier admin
-- by byl eskalační vektor na globálního admina. Globální adminy určuje users.role.
--
-- Vynucuje SupplierScopeMiddleware / SupplierAccessResolver (403 mimo membership),
-- RoleMiddleware aplikuje efektivní roli pro aktuální firmu (override stropován
-- na 'accountant', globální admin override ignoruje a vidí všechny firmy).
--
-- Indexes:
--   PRIMARY (user_id, supplier_id) — lookup membershipu uživatele (1 dotaz/request)
--   idx_usersup_supplier — výpis uživatelů firmy + FK cleanup

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_suppliers (
  user_id     BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  role        ENUM('accountant','readonly') NULL DEFAULT NULL COMMENT 'per-supplier override; NULL = zdědit globální users.role. admin ZÁMĚRNĚ chybí (viz hlavička).',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_usersup_supplier (supplier_id),
  CONSTRAINT fk_usersup_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_usersup_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
