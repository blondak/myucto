-- 1163: § 36a ZDPH + § 23/7 ZDP — spojené osoby a ceny obvyklé
--
-- Obojí měla matice jako CHYBÍ s vysokým rizikem a doslova: pojem „spojená osoba" neměl
-- v repozitáři jediný výskyt. Přitom je to typický terč doměrku — správce daně ho hledá
-- přednostně, protože je snadno prokazatelný z veřejných rejstříků a účetnictví.
--
--   § 36a ZDPH  Je-li úplata mezi spojenými osobami nižší (nebo vyšší) než cena obvyklá
--               a jedna ze stran nemá plný nárok na odpočet, je základem daně cena obvyklá.
--               DPH se pak odvádí z ceny obvyklé, ne ze sjednané.
--
--   § 23/7 ZDP  Liší-li se ceny sjednané mezi spojenými osobami od cen mezi nespojenými
--               a rozdíl NENÍ uspokojivě doložen, upraví se o něj základ daně.
--
-- ── Proč příznak, a ne odvození ─────────────────────────────────────────────────────
-- Spojení osob je právní a faktický vztah (kapitálová účast ≥ 25 %, shodní jednatelé,
-- osoby blízké, pracovněprávní vztah) — z faktur ho vyčíst nelze a z ARES ani z DIČ taky
-- ne. Označí ho tedy uživatel; systém pak umí to podstatné: takové transakce vůbec
-- POJMENOVAT a tam, kde má srovnání, spočítat odchylku od ceny mezi nespojenými.
--
-- Typ vztahu se eviduje zvlášť, protože § 23/7 rozlišuje kapitálově spojené osoby
-- (písm. a) od jinak spojených (písm. b) a doložení se u nich vede jinak.

SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS related_party TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'spojená osoba podle § 36a odst. 3 ZDPH / § 23 odst. 7 ZDP',
    ADD COLUMN IF NOT EXISTS related_party_type
        ENUM('capital','otherwise','employment','close_person') NULL
        COMMENT 'kapitálově spojená / jinak spojená / pracovněprávní vztah / osoba blízká',
    ADD COLUMN IF NOT EXISTS related_party_note VARCHAR(255) NULL
        COMMENT 'doložení vztahu — podíl, jméno jednatele, povaha vazby';

CREATE INDEX IF NOT EXISTS ix_clients_related_party ON clients (supplier_id, related_party);

-- Úprava základu daně podle § 23 odst. 7 ZDP. Ledger, ne jedno pole na přiznání: úprava
-- se dokládá per protistrana a per důvod, a při kontrole se prokazuje právě takhle.
-- `movement` drží obě strany — rozdíl může základ daně zvyšovat i snižovat (druhá strana
-- transakce dělá opak), a jednosměrná evidence by druhý případ znemožnila vykázat.
CREATE TABLE IF NOT EXISTS tax_related_party_adjustments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id   INT UNSIGNED NOT NULL,
    client_id     BIGINT UNSIGNED NULL COMMENT 'protistrana; NULL = souhrnná úprava',
    fiscal_year   SMALLINT UNSIGNED NOT NULL,
    movement      ENUM('increase','decrease') NOT NULL DEFAULT 'increase',
    amount        DECIMAL(14,2) NOT NULL COMMENT 'vždy kladně; směr určuje movement',
    reason        VARCHAR(255) NOT NULL COMMENT 'čím je rozdíl doložen, nebo proč doložen není',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_trpa_supplier_year (supplier_id, fiscal_year),
    CONSTRAINT fk_trpa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_trpa_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='§ 23/7 ZDP — úprava základu daně o rozdíl proti cenám mezi nespojenými osobami';
