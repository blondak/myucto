-- Osoby blízké zemřelého zaměstnance podle § 328 odst. 1 ZP.
--
-- Mzdová práva zaměstnance smrtí nezanikají. Do výše trojnásobku jeho
-- průměrného měsíčního výdělku přecházejí POSTUPNĚ na manžela nebo partnera,
-- děti a rodiče, žili-li s ním v době smrti ve společné domácnosti; zbytek
-- (a vše, není-li těchto osob) je předmětem dědictví. Aplikace dřív znala jen
-- příznak „skončení úmrtím" do odhlášky A2 a komu vyplatit, nevedla nikde.
--
-- Tabulka drží JEN osoby a skutečnost společné domácnosti. Kdo z nich
-- nárok nabývá, se odvozuje (první neprázdná skupina v pořadí manžel/partner,
-- děti, rodiče), aby pořadí nešlo zadat jinak, než ho stanoví zákon.

CREATE TABLE IF NOT EXISTS payroll_employment_survivors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    relationship ENUM('spouse_partner', 'child', 'parent') NOT NULL,
    shared_household TINYINT(1) NOT NULL,
    bank_account VARCHAR(64) NULL,
    note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employment_survivor_supplier_id (supplier_id, id),
    KEY idx_payroll_employment_survivor_employment (supplier_id, employment_id),
    KEY fk_payroll_employment_survivor_created (created_by),
    CONSTRAINT fk_payroll_employment_survivor_employment
        FOREIGN KEY (supplier_id, employment_id)
        REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_employment_survivor_created
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_payroll_employment_survivor_name CHECK (CHAR_LENGTH(TRIM(full_name)) > 0),
    CONSTRAINT chk_payroll_employment_survivor_household CHECK (shared_household IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
