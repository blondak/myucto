-- MyÚčto.cz — Schvalování přijatých dokladů manažerem střediska (Účtování podle dimenzí, F6)
--
-- Typ dimenze (typicky Středisko) může vyžadovat schválení nákladu odpovědnou osobou
-- hodnoty (`dimension_values.responsible_user_id`). Přechod přijatého dokladu
-- z konceptu do stavu „přijato" pak místo přijetí založí kolo schvalování; doklad
-- zůstane konceptem (mimo platební příkazy, náklady i účtování), dokud neschválí
-- všichni schvalovatelé dotčených hodnot.
--
-- ## Typ dimenze
--
--   • `requires_approval` — doklady s hodnotou tohoto typu (hlavička nebo položka)
--     schvaluje odpovědná osoba hodnoty.
--   • `approval_threshold` — základ v Kč (bez DPH), pod kterým se neschvaluje.
--     NULL / 0 = schvaluje se vždy. Porovnává se základ připadající na hodnotu
--     (u položkových dimenzí součet základů položek s touto hodnotou).
--
-- ## Doklad (`purchase_invoices.approval_status`)
--
-- Souhrn pro filtry a seznamy: none / pending / approved / rejected. Jednotlivá
-- rozhodnutí jsou v `purchase_invoice_approvals`.
--
-- ## Schválení (`purchase_invoice_approvals`)
--
-- Řádek = jedna hodnota dimenze v jednom kole. `amount_czk` je základ, za který
-- schvalovatel ručí; změní-li se po schválení středisko nebo částka, další pokus
-- o přijetí založí nové kolo jen pro dotčenou hodnotu. Odkaz z e-mailu nese token,
-- v DB je jen jeho SHA-256 (`token_hash`) — dump databáze funkční odkaz nedá.
--
-- Bez typu s `requires_approval = 1` se doklady přijímají beze změny.
--
-- Idempotentní: ADD COLUMN / KEY IF NOT EXISTS, CREATE TABLE IF NOT EXISTS,
-- INSERT IGNORE.

SET NAMES utf8mb4;

ALTER TABLE dimension_types
    ADD COLUMN IF NOT EXISTS requires_approval TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Přijaté doklady s hodnotou typu schvaluje odpovědná osoba hodnoty',
    ADD COLUMN IF NOT EXISTS approval_threshold DECIMAL(15,2) NULL
        COMMENT 'Základ v Kč bez DPH, pod kterým se neschvaluje (NULL/0 = vždy)';

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'
        COMMENT 'Souhrn schvalování manažerem střediska (purchase_invoice_approvals)';

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_supplier_approval (supplier_id, approval_status);

CREATE TABLE IF NOT EXISTS purchase_invoice_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    purchase_invoice_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    approver_user_id BIGINT UNSIGNED NOT NULL,
    round INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    amount_czk DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Základ v Kč, za který schvalovatel ručí',
    token_hash CHAR(64) NULL COMMENT 'SHA-256 tokenu z e-mailu; token sám se neukládá',
    token_expires_at DATETIME NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requested_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decided_via ENUM('app','email') NULL,
    comment VARCHAR(500) NULL,
    reminders_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_reminder_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pia_token_hash (token_hash),
    KEY idx_pia_invoice_round (purchase_invoice_id, round),
    KEY idx_pia_approver_status (approver_user_id, status),
    KEY idx_pia_supplier_status (supplier_id, status),
    KEY idx_pia_value (dimension_value_id),
    CONSTRAINT fk_pia_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_pia_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_pia_value FOREIGN KEY (dimension_value_id) REFERENCES dimension_values(id) ON DELETE CASCADE,
    CONSTRAINT fk_pia_approver FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pia_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oprávnění „Schvalovat přijaté doklady". Rozhoduje se s úrovní čtení, aby šlo
-- přidělit i roli jen pro čtení (manažer střediska nemusí mít licenci účetní).
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'purchase_invoices.approve',
       CASE WHEN r.system_key = 'readonly' THEN 1 ELSE 2 END
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant', 'readonly');
