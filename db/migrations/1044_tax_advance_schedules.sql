-- MyÚčto.cz — Správa záloh na daň a pojistné (E9) + kód zdravotní pojišťovny (E11)
--
-- Fáze E (audit 2026-07): daň z příjmů — dorovnání, poslední dvě položky.
--
--  * tax_advance_schedules = předpisy záloh vygenerované z finalizovaného přiznání:
--      - daň (§38a ZDP): DPPO/DPFO — pololetní (poslední daň 30–150 tis.) nebo
--        čtvrtletní (> 150 tis.), splatné 15. den příslušného měsíce období;
--      - sociální/zdravotní (OSVČ): nové měsíční zálohy od roku následujícího po
--        podání přehledu (soc. splatná do konce měsíce, zdrav. do 8. dne násl. měsíce).
--    Stav: planned/paid (po splatnosti = derivováno z due_date < dnes při čtení, aby
--    nevznikal stav vyžadující cron/sweep). Párování s bankou přes variable_symbol
--    (VS = DIČ pro daň, VS ČSSZ pro sociální, číslo pojištěnce pro zdravotní) —
--    matched_transaction_id ukazuje na spárovaný bank_transactions.id.
--    period_year = rok, ZA KTERÝ se zálohy platí (= rok příštího přiznání, do jehož
--    ř. 85/360 resp. přehledu vstoupí jako zaplacené zálohy).
--
--  * supplier.health_insurance_code = kód zdravotní pojišťovny (111 VZP, 201 VoZP,
--    205 ČPZP, 207 OZP, 209 ZP Škoda, 211 ZPMV ČR, 213 RBP) pro Přehled OSVČ pro ZP.
--
-- Idempotence: IF NOT EXISTS. Re-run safe. Tenant izolace přes supplier_id.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_advance_schedules (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id            INT UNSIGNED NOT NULL,
    taxpayer_type          ENUM('fo','po') NOT NULL,
    advance_kind           ENUM('tax','social','health') NOT NULL COMMENT 'druh zálohy: daň §38a / sociální OSVČ / zdravotní OSVČ',
    period_year            SMALLINT UNSIGNED NOT NULL COMMENT 'rok, za který se záloha platí (= rok příštího přiznání/přehledu)',
    seq_no                 TINYINT UNSIGNED NOT NULL COMMENT 'pořadí zálohy v roce (1..12 měsíční, 1..4 čtvrtletní, 1..2 pololetní)',
    amount                 DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'předepsaná částka zálohy',
    due_date               DATE NOT NULL COMMENT 'splatnost zálohy',
    variable_symbol        VARCHAR(20) NULL COMMENT 'očekávaný VS pro párování s bankou (DIČ / VS ČSSZ / číslo pojištěnce)',
    status                 ENUM('planned','paid') NOT NULL DEFAULT 'planned' COMMENT 'po splatnosti se odvozuje z due_date při čtení',
    paid_amount            DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'spárovaná uhrazená částka',
    paid_on                DATE NULL COMMENT 'datum úhrady (posted_at spárované transakce)',
    matched_transaction_id BIGINT UNSIGNED NULL COMMENT 'bank_transactions.id spárované úhrady',
    source_return_id       INT UNSIGNED NULL COMMENT 'income_tax_returns.id finalizovaného přiznání, které předpis vygenerovalo',
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tas (supplier_id, taxpayer_type, advance_kind, period_year, seq_no),
    KEY idx_tas_due (supplier_id, status, due_date),
    KEY idx_tas_match (matched_transaction_id),
    CONSTRAINT fk_tas_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_tas_transaction FOREIGN KEY (matched_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS health_insurance_code VARCHAR(3) NULL
        COMMENT 'kód zdravotní pojišťovny (111 VZP, 201 VoZP, 205 ČPZP, 207 OZP, 209 ZP Škoda, 211 ZPMV ČR, 213 RBP) — Přehled OSVČ pro ZP'
        AFTER health_insurance_number;
