-- MyÚčto.cz — MZ-25: roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tahle migrace je
-- ─────────────────────────────────────────────────────────────────────────────
-- Modul umí měsíční mzdu a při schválení běhu ukládá roční kumulace daně
-- (`payroll_statutory_accumulator_entries`, migrace 1258). Roční agendu ale
-- neumí vůbec: chybí evidence toho, že poplatník o zúčtování POŽÁDAL a co
-- doložil, a chybí místo, kde by výsledek zůstal dohledatelný.
--
-- Obojí je přitom podmínka, ne administrativa:
--
--   § 38ch odst. 1: „…může požádat o provedení ročního zúčtování záloh
--   a daňového zvýhodnění posledního z uvedených plátců daně, a to nejpozději
--   do 15. února po uplynutí zdaňovacího období. Roční zúčtování záloh
--   a daňového zvýhodnění NEPROVEDE plátce u poplatníka, který podá nebo je
--   povinen podat přiznání k dani."
--
--   § 38ch odst. 3: „Plátce daně provede roční zúčtování … JEN NA ZÁKLADĚ
--   DOKLADŮ za uplynulé zdaňovací období od všech předchozích plátců daně…
--   Plátce daně roční zúčtování … neprovede, pokud poplatník tyto doklady
--   nepředloží plátci daně do 15. února po uplynutí zdaňovacího období."
--
--   § 38j odst. 2 písm. h): mzdový list musí obsahovat „údaje o výpočtu daně
--   a provedeném ročním zúčtování záloh a daňového zvýhodnění".
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Fail-closed: „nevíme" NENÍ „v pořádku"
-- ─────────────────────────────────────────────────────────────────────────────
-- Každý stavový sloupec žádosti má výchozí hodnotu `unknown` a `unknown`
-- zúčtování ZASTAVÍ. Je to schválně: kdyby výchozí stav znamenal „nic nebrání",
-- stačilo by na zaměstnance zapomenout a aplikace by mu vyrobila přeplatek bez
-- jediného dokladu. CHECK constrainty navíc vynucují, že doložený stav nese
-- doklad a datum — jinak by bylo možné uložit „doloženo" bez čehokoli za tím.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Co se tu ZÁMĚRNĚ nezakládá
-- ─────────────────────────────────────────────────────────────────────────────
-- Druhé úložiště pro samotný výsledek. Snapshot ročního zúčtování jde do už
-- existujících `payroll_annual_document_revisions` s `purpose =
-- 'annual_settlement_result'` — tuhle hodnotu jejich CHECK z migrace 1265
-- povoluje od začátku a nikdo ji dosud nezapsal. `payroll_annual_settlement_outcomes`
-- níž NENÍ druhá pravda, je to dotazovatelný rejstřík: snapshot je šifrovaný,
-- takže se v něm nedá hledat „komu vyšel přeplatek a je už vyplacený".
--
-- Taky se tu nezakládá nic pro vyúčtování daně ze závislé činnosti podle
-- § 38j odst. 4 a 5. To je samostatná agenda vůči správci daně a MZ-25 ji neřeší.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Žádost o roční zúčtování a podklady k ní
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_annual_settlement_requests (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,

  request_status              ENUM('unknown','requested','not_requested','withdrawn')
                              NOT NULL DEFAULT 'unknown'
                              COMMENT '§ 38ch odst. 1. unknown = nevíme, není to „nepožádal".',
  requested_on                DATE NULL
                              COMMENT 'Den podání žádosti. Po 15. 2. je žádost opožděná.',
  request_evidence_reference  VARCHAR(500) NULL
                              COMMENT 'Čím je žádost doložená. Bez ní se žádost neuloží jako podaná.',

  prior_employers             ENUM('unknown','none','all_documented','missing')
                              NOT NULL DEFAULT 'unknown'
                              COMMENT '§ 38ch odst. 3. none = jiného plátce v roce neměl.',
  prior_documents_received_on DATE NULL
                              COMMENT 'Kdy doklady předchozích plátců došly (§ 38ch odst. 3).',

  filing_obligation           ENUM('unknown','none','required')
                              NOT NULL DEFAULT 'unknown'
                              COMMENT '§ 38g. required = zúčtování provést NELZE (§ 38ch odst. 1 věta druhá).',
  filing_obligation_reason    VARCHAR(500) NULL
                              COMMENT 'Proč přiznání podá nebo musí podat. Aplikace to neodvozuje.',

  annual_claims               ENUM('unknown','none','present_unsupported')
                              NOT NULL DEFAULT 'unknown'
                              COMMENT '§ 38h odst. 6 — § 15, § 35 odst. 4, sleva na manžela. Modul je neumí.',
  annual_claims_note          VARCHAR(500) NULL
                              COMMENT 'Co konkrétně poplatník ročně uplatňuje.',

  note                        VARCHAR(1000) NULL,
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_request_supplier_id (supplier_id, id),
  -- Jedna žádost na zaměstnance a rok. § 38ch odst. 1 zná jednoho plátce
  -- a jedno zdaňovací období; druhý řádek by znamenal dvě odpovědi na tutéž
  -- otázku.
  UNIQUE KEY uq_payroll_annual_settlement_request_scope
    (supplier_id, employee_id, tax_year),
  KEY idx_payroll_annual_settlement_request_year
    (supplier_id, tax_year, request_status),
  KEY fk_payroll_annual_settlement_request_creator (created_by),
  KEY fk_payroll_annual_settlement_request_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_request_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_annual_settlement_request_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_request_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Každé omezení zvlášť a vždy nejdřív zahodit: MariaDB neumí u CHECK ani
-- u cizího klíče `IF NOT EXISTS` a migrace se pouští opakovaně (testy
-- i čerstvý klon). Vzor je z migrací 1027, 1384 a 1394.
ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_year;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_year
    CHECK (tax_year BETWEEN 2000 AND 2199);

-- Podaná žádost musí nést datum i doložení; nepodaná nesmí nést datum.
-- Bez toho by šlo uložit „požádal" bez čehokoli za tím — a přesně to je stav,
-- který by pak vyrobil přeplatek na základě ničeho.
ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_evidence;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_evidence
    CHECK (
      (request_status = 'requested'
        AND requested_on IS NOT NULL
        AND request_evidence_reference IS NOT NULL)
      OR (request_status <> 'requested' AND requested_on IS NULL)
    );

-- Datum převzetí dokladů smí existovat jen u doloženého stavu a naopak.
ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_prior;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_prior
    CHECK (
      (prior_employers = 'all_documented' AND prior_documents_received_on IS NOT NULL)
      OR (prior_employers <> 'all_documented' AND prior_documents_received_on IS NULL)
    );

-- Povinnost podat přiznání se neeviduje bez důvodu: je to důvod, proč
-- zaměstnanci zúčtování provést NEJDE, a ten se musí dát dohledat.
ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_filing;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_filing
    CHECK (
      filing_obligation <> 'required' OR filing_obligation_reason IS NOT NULL
    );

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_claims;
ALTER TABLE payroll_annual_settlement_requests
  ADD CONSTRAINT chk_payroll_annual_settlement_request_claims
    CHECK (
      annual_claims <> 'present_unsupported' OR annual_claims_note IS NOT NULL
    );

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Rejstřík výsledků — dotazovatelný protějšek šifrovaného snapshotu
-- ─────────────────────────────────────────────────────────────────────────────
-- Závazná pravda je snapshot v `payroll_annual_document_revisions`. Tady je jen
-- to, na co se člověk ptá seznamem: komu vyšlo co a je to už vyplacené.
-- Částky se proto NIKDY nepřepočítávají z tohohle řádku — jsou tu odvozené
-- a `annual_revision_id` říká, ze kterého snapshotu.
CREATE TABLE IF NOT EXISTS payroll_annual_settlement_outcomes (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,
  annual_revision_id          BIGINT UNSIGNED NOT NULL
                              COMMENT 'Snapshot výsledku (purpose = annual_settlement_result).',

  outcome                     ENUM(
                                'overpayment',
                                'overpayment_below_threshold',
                                'no_difference',
                                'underpayment_not_withheld'
                              ) NOT NULL,

  -- § 35d odst. 7 počítá oba rozdíly ZVLÁŠŤ a jednotné měsíční hlášení je
  -- vykazuje samostatnými položkami 10322 a 10323. Uložit jen součet by
  -- znamenalo, že se hlášení nedá vyplnit bez rozšifrování snapshotu.
  tax_difference_minor        BIGINT NOT NULL
                              COMMENT 'Rozdíl na dani. Kladný = přeplatek.',
  bonus_difference_minor      BIGINT NOT NULL
                              COMMENT 'Rozdíl na daňovém bonusu. Kladný = doplatek na bonusu.',
  settlement_difference_minor BIGINT NOT NULL
                              COMMENT 'Doplatek ze zúčtování = součet obou rozdílů (§ 35d odst. 8).',
  payable_minor               BIGINT UNSIGNED NOT NULL
                              COMMENT 'Kolik se skutečně vyplácí. Nedoplatek se nesráží, proto nikdy záporné.',

  -- § 38ch odst. 5: „činí-li úhrnná výše tohoto přeplatku více než 50 Kč".
  -- Přeplatek pod prahem je jiný stav než žádný přeplatek a musí být vidět.
  payout_threshold_minor      BIGINT UNSIGNED NOT NULL,

  settled_on                  DATE NOT NULL
                              COMMENT 'Den provedení zúčtování (§ 38ch odst. 4 — nejpozději 31. 3.).',
  -- Mzdový vstup, kterým se přeplatek vrací. NULL = ještě nezaložený; vrací se
  -- podle § 38ch odst. 5 nejpozději při zúčtování mzdy za březen.
  payroll_input_id            BIGINT UNSIGNED NULL,

  created_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_outcome_supplier_id (supplier_id, id),
  -- § 38ch odst. 4: zúčtování se provádí JEDNOU za zdaňovací období. Kontrola
  -- jednotného měsíčního hlášení to říká stejně („právě jednou za kalendářní
  -- rok v měsíci leden, únor nebo březen"). Opakované spuštění proto nesmí
  -- vyrobit druhý výsledek — a tenhle unikátní klíč je to jediné, co to
  -- zaručuje i při souběhu dvou požadavků.
  UNIQUE KEY uq_payroll_annual_settlement_outcome_scope
    (supplier_id, employee_id, tax_year),
  UNIQUE KEY uq_payroll_annual_settlement_outcome_revision
    (supplier_id, annual_revision_id),
  KEY idx_payroll_annual_settlement_outcome_year
    (supplier_id, tax_year, outcome),
  KEY fk_payroll_annual_settlement_outcome_input (supplier_id, payroll_input_id),
  KEY fk_payroll_annual_settlement_outcome_creator (created_by),

  CONSTRAINT fk_payroll_annual_settlement_outcome_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_outcome_revision
    FOREIGN KEY (supplier_id, annual_revision_id)
    REFERENCES payroll_annual_document_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_outcome_input
    FOREIGN KEY (supplier_id, payroll_input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_settlement_outcome_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_year;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_year
    CHECK (tax_year BETWEEN 2000 AND 2199);

-- Součet musí sedět. Rozdíly se ukládají odděleně kvůli hlášení, ale nesmí se
-- rozejít se souhrnem — to by byla druhá pravda uvnitř jednoho řádku.
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_sum;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_sum
    CHECK (settlement_difference_minor = tax_difference_minor + bonus_difference_minor);

-- Vyplácí se buď celý doplatek, nebo nic. Částečná výplata nemá v § 38ch
-- odst. 5 ani v § 35d odst. 8 oporu — a nula u záporného rozdílu je přímo tam:
-- „Případný nedoplatek … se poplatníkovi nesráží."
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_payable;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_payable
    CHECK (
      payable_minor = 0
      OR (payable_minor = settlement_difference_minor
          AND settlement_difference_minor > payout_threshold_minor)
    );

-- Stav musí odpovídat číslům. Bez toho by šlo uložit „přeplatek" u záporného
-- rozdílu a seznam by lhal, aniž by to snapshot poznal.
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_state;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_state
    CHECK (
      (outcome = 'underpayment_not_withheld'
        AND settlement_difference_minor < 0 AND payable_minor = 0)
      OR (outcome = 'no_difference'
        AND settlement_difference_minor = 0 AND payable_minor = 0)
      OR (outcome = 'overpayment_below_threshold'
        AND settlement_difference_minor > 0
        AND settlement_difference_minor <= payout_threshold_minor
        AND payable_minor = 0)
      OR (outcome = 'overpayment'
        AND settlement_difference_minor > payout_threshold_minor
        AND payable_minor = settlement_difference_minor)
    );

-- Mzdový vstup smí nést jen výsledek, který se skutečně vyplácí.
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_input;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_input
    CHECK (payroll_input_id IS NULL OR payable_minor > 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Nový druh vygenerovaného dokumentu
-- ─────────────────────────────────────────────────────────────────────────────
-- Výsledek ročního zúčtování je doklad, ne obrazovka — archivuje se stejnou
-- cestou jako mzdový list a potvrzení o zdanitelných příjmech. Vzor drop/re-add
-- je převzatý z migrace 1268.
ALTER TABLE payroll_generated_documents
  DROP CONSTRAINT IF EXISTS chk_payroll_document_kind;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT chk_payroll_document_kind CHECK (
    document_kind IN (
      'payslip',
      'payroll_sheet',
      'taxable_income_advance_certificate',
      'taxable_income_withholding_certificate',
      'employment_certificate',
      'average_earnings_certificate',
      'annual_settlement_result',
      'monthly_bundle'
    )
  );
