-- 1162: § 79 až § 79a ZDPH — odpočet při registraci a snížení při zrušení registrace (ř. 45)
--
-- Systém tuhle povinnost neměl vůbec. Přitom jde o dvě protilehlé situace, které se obě
-- vykazují na ř. 45 přiznání (`odp_rez_nar`), jen s opačným znaménkem — a obě se stanou
-- každému plátci nejvýš párkrát za život firmy, takže na ně nikdo nemyslí a chyba je drahá:
--
--   § 79   REGISTRACE: nová plátkyně má NÁROK na odpočet z majetku pořízeného v období
--          12 měsíců přede dnem, kdy se stala plátcem, pokud je ten majetek k tomu dni
--          součástí jejího obchodního majetku. Když si ho neuplatní, jednorázově o něj
--          přijde — lhůta je prekluzivní a zpětně se nedohání.
--
--   § 79a  ZRUŠENÍ REGISTRACE: plátce je POVINEN snížit uplatněný odpočet u majetku,
--          který ke dni zrušení registrace drží. Neprovedené snížení je doměrek.
--
-- Znaménko i období určuje XSD anotace `odp_rez_nar` doslova: nárok při registraci kladně
-- v přiznání za období, do něhož spadá den vzniku plátcovství; snížení při zrušení záporně
-- v přiznání za POSLEDNÍ zdaňovací období registrace.
--
-- ── Proč se eviduje, a neodvozuje z dokladů ─────────────────────────────────────────
-- Podmínka „je součástí obchodního majetku ke dni registrace" je skutkový stav, který
-- systém z přijatých faktur nevidí — materiál mohl být spotřebován, zboží prodáno, majetek
-- vyřazen. Odvozovat nárok z pouhé existence faktury v okně 12 měsíců by znamenalo tvrdit
-- odpočet z věcí, které už firma nemá. Položky proto zadává účetní a systém dopočítá to,
-- co spočítat umí: lhůtu 12 měsíců a výši snížení u dlouhodobého majetku.
--
-- ── Výše snížení u zrušení registrace ───────────────────────────────────────────────
-- § 79a odst. 2 odkazuje na § 78d obdobně: u dlouhodobého majetku se vrací jen POMĚRNÁ
-- část podle roků zbývajících do konce lhůty pro úpravu odpočtu (5 let, u staveb 10).
-- U zásob se vrací odpočet celý — žádná lhůta tam neběží.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_registration_corrections (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id   INT UNSIGNED NOT NULL,
    kind          ENUM('registration','deregistration') NOT NULL
        COMMENT '§ 79 nárok při registraci (+) / § 79a snížení při zrušení registrace (−)',
    label         VARCHAR(255) NOT NULL COMMENT 'popis majetku — co se odpočtu týká',
    acquired_on   DATE NOT NULL
        COMMENT 'pořízení plnění, u dlouhodobého majetku uvedení do stavu způsobilého k užívání',
    effective_on  DATE NOT NULL
        COMMENT 'den vzniku plátcovství (§ 79) nebo den zrušení registrace (§ 79a) — určuje období vykázání',
    asset_kind    ENUM('inventory','fixed_asset') NOT NULL DEFAULT 'inventory'
        COMMENT 'zásoby vracejí odpočet celý, dlouhodobý majetek jen poměrnou část (§ 79a/2 → § 78d)',
    period_years  TINYINT UNSIGNED NULL
        COMMENT 'lhůta pro úpravu odpočtu 5 / 10 let — jen u dlouhodobého majetku',
    vat_amount    DECIMAL(14,2) NOT NULL
        COMMENT 'daň na vstupu: u registrace uplatnitelná, u zrušení dříve uplatněná',
    purchase_invoice_id BIGINT UNSIGNED NULL,
    asset_id      BIGINT UNSIGNED NULL,
    note          VARCHAR(255) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_vrc_supplier_effective (supplier_id, effective_on),
    CONSTRAINT fk_vrc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='§ 79/79a ZDPH — odpočet při registraci a jeho snížení při zrušení registrace (ř. 45)';
