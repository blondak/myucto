-- Skončení pracovního vztahu — jediný zdroj způsobu a důvodu skončení.
--
-- Důvod skončení se dřív zadával dvakrát a nezávisle: jako číselný kód
-- v odhlášce REGZEC A2 (podklady pro Úřad práce) a jako druh skončení
-- v potvrzení zaměstnavatele pro Úřad práce (§ 313 odst. 2 ZP). Vztah ho
-- neukládal vůbec, takže návrh odstupného (§ 67 ZP) neměl z čeho vycházet.
--
-- Způsob (výpověď, dohoda, okamžité zrušení, …) a zákonný důvod (§ 52 ZP,
-- § 55, § 56) jsou dva sloupce, protože stejný důvod nese jiné následky podle
-- formy: dohoda z organizačních důvodů zakládá odstupné stejně jako výpověď,
-- dohoda bez důvodu ne. Kódy pro REGZEC i druh pro Úřad práce se z nich
-- ODVOZUJÍ (PayrollTerminationReason), neukládají.
--
-- Přepis násobku odstupného (kolektivní smlouva, vnitřní předpis) smí jen
-- zvýšit zákonné minimum — kontroluje služba; tady je jen tvar.

CREATE TABLE IF NOT EXISTS payroll_employment_terminations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    termination_method ENUM(
        'employer_notice',
        'agreement',
        'employee_notice',
        'employer_immediate',
        'employee_immediate',
        'probation_employer',
        'probation_employee',
        'fixed_term_expiry',
        'death',
        'foreigner_permit',
        'other'
    ) NOT NULL,
    legal_ground ENUM(
        'none',
        'organizational',
        'health_long_term',
        'health_work_injury',
        'max_exposure',
        'requirements_unmet',
        'breach_gross',
        'breach_serious',
        'breach_minor_repeated',
        'sickness_regime',
        'criminal_conviction',
        'health_no_transfer',
        'wage_not_paid'
    ) NOT NULL DEFAULT 'none',
    employee_stated_reason VARCHAR(1000) NULL,
    severance_multiple_override TINYINT UNSIGNED NULL,
    severance_override_reason VARCHAR(500) NULL,
    working_time_account_applies TINYINT(1) NOT NULL DEFAULT 0,
    death_tax_assessment VARCHAR(1000) NULL,
    death_tax_assessed_by BIGINT UNSIGNED NULL,
    death_tax_assessed_at DATETIME NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employment_termination (supplier_id, employment_id),
    KEY fk_payroll_employment_termination_created (created_by),
    KEY fk_payroll_employment_termination_updated (updated_by),
    KEY fk_payroll_employment_termination_assessed (death_tax_assessed_by),
    CONSTRAINT fk_payroll_employment_termination_employment
        FOREIGN KEY (supplier_id, employment_id)
        REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_employment_termination_created
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_employment_termination_updated
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_employment_termination_assessed
        FOREIGN KEY (death_tax_assessed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_payroll_employment_termination_override CHECK (
        (severance_multiple_override IS NULL AND severance_override_reason IS NULL)
        OR (severance_multiple_override BETWEEN 1 AND 36
            AND severance_override_reason IS NOT NULL
            AND CHAR_LENGTH(TRIM(severance_override_reason)) > 0)
    ),
    CONSTRAINT chk_payroll_employment_termination_account CHECK (
        working_time_account_applies IN (0, 1)
    ),
    CONSTRAINT chk_payroll_employment_termination_death_tax CHECK (
        (death_tax_assessment IS NULL AND death_tax_assessed_at IS NULL)
        OR (death_tax_assessment IS NOT NULL
            AND CHAR_LENGTH(TRIM(death_tax_assessment)) > 0
            AND death_tax_assessed_at IS NOT NULL
            AND termination_method = 'death')
    ),
    CONSTRAINT chk_payroll_employment_termination_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
