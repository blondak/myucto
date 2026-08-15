-- MyÚčto.cz — retenční politika mzdové agendy a výmaz osobních údajů jako NÁVRH.
--
-- Mzdový modul drží nejcitlivější osobní údaje v aplikaci: rodná čísla, adresy,
-- čísla účtů, údaje o dětech, zdravotní pojišťovnu, exekuce a insolvence. Dosud
-- se hromadily bez konce — retence neexistovala, takže na žádost o výmaz nebylo
-- čím odpovědět a zároveň nebylo čím doložit, že se nemazalo předčasně.
--
--
-- 1) payroll_retention_policies — TENANTNÍ ODCHYLKA, NE ZÁKON
--
-- Zákonné lhůty žijí v kódu (`PayrollRetentionCatalog`), protože lhůta je tvrzení
-- o právu a musí projít revizí v diffu. Tahle tabulka slouží ke DVĚMA věcem,
-- které do kódu nepatří:
--
--   a) PRODLOUŽENÍ lhůty pro konkrétní firmu (smluvní závazek, vnitřní předpis).
--   b) DODÁNÍ lhůty tam, kde zákon mlčí — evidence pracovní doby (§ 96 ZP ji
--      přikazuje vést, ale lhůtu nestanoví) a spis k exekučním srážkám.
--
-- ZKRÁTIT zákonnou lhůtu tabulka NESMÍ a aplikace to odmítá
-- (`PayrollRetentionPolicyRepository`). Kdyby to šlo, celý katalog v kódu by byl
-- na nic: stačil by jeden UPDATE a mazalo by se pět let po nástupu.
--
-- Proto tu není sloupec „retention_years" bez dalšího, ale `extra_years`
-- (přičítá se k zákonné lhůtě) a `override_years` (POUZE pro kategorie, které
-- zákonnou lhůtu nemají). Rozdíl je vidět už ve schématu, ne až v kódu.
--
--
-- 2) payroll_erasure_proposals / _items — VÝMAZ JE NÁVRH, NE AUTOMAT
--
-- Nic se nemaže na pozadí. Sestaví se NÁVRH, který dopředu jmenuje, čeho přesně
-- se to týká a co zůstane, člověk ho schválí a teprve pak se provede. Uplynulá
-- retenční lhůta je konec povinnosti uchovávat, ne příkaz ke smazání — stejná
-- úvaha, jakou nese `RetentionPolicy` na účetní straně.
--
-- Položka nese `action`:
--   'erase'     — osoba nemá žádnou účetní stopu a zmizí celá (deleguje se na
--                 PayrollEmployeeDeletionRepository, který tuhle úvahu už umí).
--   'anonymize' — osoba účetní stopu má. Účetní záznam MUSÍ zůstat, jen z něj
--                 zmizí osobní údaj. Mzdový rok se tím nerozpadne a vazby
--                 v deníku neosiří, protože se nemaže ANI JEDEN řádek.
--
-- FK NA payroll_employees ZDE ZÁMĚRNĚ NENÍ.
--
-- Tohle je nejdůležitější rozhodnutí celé migrace. Položka návrhu je zároveň
-- DOKLADEM o provedeném výmazu — co, kdy, kdo schválil, podle které lhůty. Kdyby
-- měla cizí klíč na osobu, kaskáda by ji při úplném výmazu smazala spolu s ní
-- a po výmazu by nezbylo nic. Vypadalo by to jako ztráta dat, ne jako řádný
-- postup. `employee_id` je proto obyčejné číslo bez vazby.
--
-- Ze stejného důvodu položka NENESE jméno ani jiný osobní údaj: doklad o výmazu,
-- který si osobní údaj ponechá, výmaz popírá.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS (vzor 1393).

SET NAMES utf8mb4;

-- 1) Tenantní odchylka od zákonné lhůty.
CREATE TABLE IF NOT EXISTS payroll_retention_policies (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  category       VARCHAR(64) NOT NULL
                 COMMENT 'klíč z PayrollRetentionCatalog::categories()',
  extra_years    SMALLINT UNSIGNED NOT NULL DEFAULT 0
                 COMMENT 'přičítá se k zákonné lhůtě; nikdy ji nezkracuje',
  override_years SMALLINT UNSIGNED NULL
                 COMMENT 'lhůta dodaná tam, kde zákon mlčí; POUZE pro kategorie bez zákonné lhůty',
  reason         VARCHAR(500) NOT NULL
                 COMMENT 'proč se firma odchyluje — bez zdůvodnění je odchylka neobhajitelná',
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_by     BIGINT UNSIGNED NULL,
  updated_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_retention_policy_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_retention_policy_category (supplier_id, category),
  CONSTRAINT fk_payroll_retention_policy_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_retention_policy_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_retention_policy_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_retention_policy_change
    CHECK (extra_years > 0 OR override_years IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Návrh výmazu — hlavička se stavem a schválením.
CREATE TABLE IF NOT EXISTS payroll_erasure_proposals (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  status        ENUM('pending','approved','rejected','executed') NOT NULL DEFAULT 'pending',
  as_of         DATE NOT NULL
                COMMENT 'den, ke kterému se posuzovalo uplynutí lhůt — návrh se tím dá přepočítat',
  note          VARCHAR(500) NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  created_by    BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by   BIGINT UNSIGNED NULL COMMENT 'kdo výmaz schválil — bez toho se neprovede',
  approved_at   DATETIME NULL,
  rejected_by   BIGINT UNSIGNED NULL,
  rejected_at   DATETIME NULL,
  executed_at   DATETIME NULL,

  UNIQUE KEY uq_payroll_erasure_proposal_supplier_id (supplier_id, id),
  KEY idx_payroll_erasure_proposal_status (supplier_id, status, created_at),
  CONSTRAINT fk_payroll_erasure_proposal_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_erasure_proposal_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_erasure_proposal_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_erasure_proposal_rejector
    FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Položky návrhu — zároveň doklad o provedení. Bez FK na osobu, viz hlavička.
CREATE TABLE IF NOT EXISTS payroll_erasure_proposal_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  proposal_id     BIGINT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL
                  COMMENT 'payroll_employees.id — ZÁMĚRNĚ bez FK, doklad musí přežít výmaz osoby',
  action          ENUM('erase','anonymize') NOT NULL,
  outcome         ENUM('pending','done','skipped_hold','skipped_changed') NOT NULL DEFAULT 'pending',
  governing_category VARCHAR(64) NOT NULL
                  COMMENT 'kategorie s NEJDELŠÍ lhůtou — ta, podle které se rozhodlo',
  governing_source VARCHAR(191) NOT NULL
                  COMMENT 'citace ustanovení nebo zákona v okamžiku rozhodnutí',
  governing_source_status VARCHAR(32) NOT NULL
                  COMMENT 'repo_verified / external_unverified — schvalující musí vidět, jak je lhůta doložená',
  retained_until  DATE NOT NULL COMMENT 'poslední den, kdy záznam musel být uchovaný',
  last_record_year SMALLINT UNSIGNED NOT NULL,
  cascade_counts  JSON NULL COMMENT 'co přesně zmizelo/bylo anonymizováno, po skupinách',
  skip_reason     VARCHAR(255) NULL,
  executed_at     DATETIME NULL,

  UNIQUE KEY uq_payroll_erasure_item_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_erasure_item_employee (proposal_id, employee_id),
  KEY idx_payroll_erasure_item_employee (supplier_id, employee_id),
  CONSTRAINT fk_payroll_erasure_item_proposal
    FOREIGN KEY (supplier_id, proposal_id)
    REFERENCES payroll_erasure_proposals (supplier_id, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
