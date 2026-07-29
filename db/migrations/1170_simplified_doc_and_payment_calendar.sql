-- 1170: § 30–30a ZDPH zjednodušený daňový doklad + § 31/31a splátkový a platební kalendář
--
-- Obojí mělo v repozitáři nula výskytů, přestože jde o běžné doklady: zjednodušený doklad
-- se vystavuje při pokladním prodeji (a pokladnu systém má) a platební kalendář je typický
-- u nájmů a leasingů.
--
-- ── § 30 zjednodušený daňový doklad ─────────────────────────────────────────────────
-- Lze vystavit, když celková částka ZA PLNĚNÍ VČETNĚ DANĚ není vyšší než 10 000 Kč.
-- Nemusí obsahovat označení a DIČ odběratele, jednotkovou cenu, základ daně ani výši daně
-- (§ 30a odst. 1) — proto se eviduje příznakem, ne jiným typem dokladu: je to pořád
-- faktura, jen s méně náležitostmi.
--
-- Podstatné jsou VÝJIMKY (§ 30 odst. 2): zjednodušený doklad NELZE vystavit u dodání
-- zboží do jiného členského státu osvobozeného s nárokem na odpočet, u prodeje zboží na
-- dálku a u plnění v režimu přenesené daňové povinnosti. Tam odběratel své identifikační
-- údaje na dokladu POTŘEBUJE — bez nich plnění nelze vykázat v souhrnném ani kontrolním
-- hlášení. Vynucuje se při vystavení, ne v konceptu (viz IssueInvoiceAction).
--
-- ── § 31 / § 31a splátkový a platební kalendář ──────────────────────────────────────
-- Kalendář je SÁM O SOBĚ daňovým dokladem, pokud obsahuje náležitosti daňového dokladu
-- a rozpis plateb na předem stanovené období. Nevystavuje se tedy doklad ke každé splátce
-- — to je celý smysl institutu a důvod, proč nestačí evidovat opakovanou fakturaci.
--
-- Platební kalendář (§ 31a) navíc NEMUSÍ obsahovat den uskutečnění plnění ani den přijetí
-- úplaty: platí se předem, takže v okamžiku vystavení ještě nenastaly.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = 1;

-- `payment_calendar` je vlastní typ dokladu, ne příznak: má jiné náležitosti (rozpis
-- plateb místo položek plnění) a jiná pravidla pro DUZP, takže by se s běžnou fakturou
-- ve výkazech choval jinak.
ALTER TABLE invoices
    MODIFY invoice_type ENUM('invoice','proforma','credit_note','cancellation','tax_document','penalty','payment_calendar') NOT NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS is_simplified TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'zjednodušený daňový doklad podle § 30 ZDPH (do 10 000 Kč vč. daně)'
        AFTER reverse_charge;

-- Rozpis plateb kalendáře. Samostatná tabulka, ne položky faktury: položky nesou plnění
-- (co se dodává), kdežto tohle je harmonogram ÚHRAD (kdy a kolik) — sloučit by znamenalo,
-- že se rozpis plateb objeví ve výkazech jako plnění.
CREATE TABLE IF NOT EXISTS invoice_payment_schedule (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id   INT UNSIGNED NOT NULL,
    invoice_id    BIGINT UNSIGNED NOT NULL,
    due_on        DATE NOT NULL COMMENT 'den, ke kterému je platba stanovena',
    base_amount   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    vat_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_amount  DECIMAL(14,2) NOT NULL,
    note          VARCHAR(255) NULL,
    order_index   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ips_invoice (supplier_id, invoice_id, order_index),
    CONSTRAINT fk_ips_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_ips_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='§ 31/31a ZDPH — rozpis plateb splátkového a platebního kalendáře';
