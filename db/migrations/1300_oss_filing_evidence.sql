-- 1300: OSS — evidence podání podle § 110f ZDPH (čl. 63c nařízení (EU) č. 282/2011)
--
-- Archiv SAMOTNÉHO PODÁNÍ už existuje: OSS XML se od začátku archivuje do `tax_submissions`
-- (`form_code='ossei1'`, viz OssReportAction::download → TaxSubmissionArchiver). Tahle
-- migrace ho NEZDVOJUJE — přidává to, co u OSS na rozdíl od DPH/KH chybělo úplně:
-- PODKLAD, ze kterého podání vzniklo, uchovaný v podobě, kterou vyžaduje zákon.
--
-- ── Proč vlastní tabulka a ne odkaz na doklady ─────────────────────────────────────
-- § 110f odst. 2 písm. a) ZDPH: údaje se uchovávají 10 LET od konce kalendářního roku,
-- ve kterém bylo plnění uskutečněno. Faktura ani její položka tak dlouho v původní
-- podobě nevydrží — dá se editovat, stornovat i smazat, a přesně to je scénář, proti
-- kterému evidence stojí. Odkaz do `invoice_items` by tedy povinnost nesplnil: po opravě
-- dokladu by evidence tiše ukazovala jiný stav, než jaký se podal. Proto se hodnoty
-- KOPÍRUJÍ v okamžiku archivace podání a řádek je write-once (triggery níž).
--
-- ── Struktura sloupců = čl. 63c odst. 1 nařízení 282/2011 ──────────────────────────
-- § 110f odst. 1 ZDPH strukturu sám nestanoví, odkazuje na „přímo použitelný předpis
-- Evropské unie" — tím je čl. 63c prováděcího nařízení Rady (EU) č. 282/2011 ve znění
-- nařízení (EU) 2019/2026. Každý sloupec níž nese v komentáři písmeno svého bodu, aby
-- šlo doložit, odkud se vzal. Body, které dnešní datový model neumí naplnit, tabulka
-- ZÁMĚRNĚ NEMÁ — prázdný sloupec by budil dojem splněné povinnosti (viz README epicu).
--
-- ── Neúplnost se přiznává, ne dopočítává ───────────────────────────────────────────
-- `completeness_json` drží seznam bodů čl. 63c, které se u konkrétního řádku nepodařilo
-- naplnit. Export evidence ho vypisuje spolu s daty, takže při kontrole je vidět, co
-- systém doložit umí a co ne.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `oss_filing_evidence` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id`           INT UNSIGNED NOT NULL,
    `submission_id`         INT UNSIGNED NOT NULL
        COMMENT 'tax_submissions.id archivovaného OSS podání (form_code=ossei1)',
    `period_year`           SMALLINT UNSIGNED NOT NULL,
    `period_quarter`        TINYINT UNSIGNED NOT NULL,
    `seq`                   INT UNSIGNED NOT NULL COMMENT 'Pořadí záznamu v rámci podání (stabilní řazení)',

    -- čl. 63c odst. 1 písm. a)
    `consumption_country`   CHAR(2) NOT NULL COMMENT '63c(1)(a) členský stát spotřeby',
    -- čl. 63c odst. 1 písm. b)
    `supply_type`           ENUM('goods','services') NULL COMMENT '63c(1)(b) druh plnění',
    `supply_description`    TEXT NOT NULL COMMENT '63c(1)(b) popis plnění',
    `supply_quantity`       DECIMAL(14,3) NULL COMMENT '63c(1)(b) množství',
    `supply_unit`           VARCHAR(20) NULL COMMENT '63c(1)(b) měrná jednotka',
    -- čl. 63c odst. 1 písm. c)
    `supply_date`           DATE NOT NULL COMMENT '63c(1)(c) datum uskutečnění plnění',
    -- čl. 63c odst. 1 písm. d) — základ v měně DOKLADU i v měně PODÁNÍ; „indicating the
    -- currency used" znamená, že se měna musí uvést, ne že se smí ztratit přepočtem
    `taxable_amount`        DECIMAL(14,2) NOT NULL COMMENT '63c(1)(d) základ daně v měně dokladu',
    `taxable_currency`      CHAR(3) NOT NULL COMMENT '63c(1)(d) měna dokladu',
    `taxable_amount_return` DECIMAL(14,2) NOT NULL COMMENT '63c(1)(d) základ daně v měně podání',
    `return_currency`       CHAR(3) NOT NULL COMMENT '63c(1)(d) měna podání',
    `exchange_rate`         DECIMAL(18,8) NULL COMMENT 'Kurz použitý pro přepočet do měny podání',
    `exchange_rate_date`    DATE NULL COMMENT 'Datum kurzu',
    -- čl. 63c odst. 1 písm. e) — následné zvýšení/snížení základu. U nás je nositelem
    -- opravy položka opravného dokladu s vyplněným původním OSS obdobím (VetaO).
    `adjusted_period`       CHAR(6) NULL COMMENT '63c(1)(e) opravované období RRRRQn (NULL = běžné plnění)',
    -- čl. 63c odst. 1 písm. f)
    `vat_rate`              DECIMAL(5,2) NOT NULL COMMENT '63c(1)(f) použitá sazba DPH',
    `vat_rate_type`         VARCHAR(32) NULL COMMENT '63c(1)(f) typ sazby dle číselníku členských států',
    -- čl. 63c odst. 1 písm. g)
    `vat_amount`            DECIMAL(14,2) NOT NULL COMMENT '63c(1)(g) daň v měně dokladu',
    `vat_amount_return`     DECIMAL(14,2) NOT NULL COMMENT '63c(1)(g) daň v měně podání',
    -- čl. 63c odst. 1 písm. h) — úhrady dokladu; JSON, protože doklad může mít víc úhrad
    `payments_json`         JSON NULL COMMENT '63c(1)(h) [{paid_on, amount, currency}] přijaté úhrady dokladu',
    -- čl. 63c odst. 1 písm. j) — údaje uvedené na dokladu
    `invoice_id`            BIGINT UNSIGNED NULL COMMENT 'Odkaz do invoices (informativní, evidence na něm nestojí)',
    `invoice_item_id`       BIGINT UNSIGNED NULL COMMENT 'Odkaz do invoice_items (informativní)',
    `invoice_snapshot_json` JSON NOT NULL COMMENT '63c(1)(j) opsané údaje dokladu v době podání',
    -- čl. 63c odst. 1 písm. k) — podklad pro určení místa plnění
    `customer_name`         VARCHAR(255) NULL COMMENT 'Jméno odběratele (63c(1)(k) v původním znění pro služby)',
    `place_evidence_json`   JSON NOT NULL COMMENT '63c(1)(k) údaje, ze kterých se místo plnění určilo',

    -- Které body čl. 63c se u tohohle řádku naplnit nepodařilo (list písmen + důvod)
    `completeness_json`     JSON NOT NULL COMMENT 'Nenaplněné body čl. 63c a proč',

    -- § 110f odst. 2 písm. a): 10 let od KONCE kalendářního roku uskutečnění plnění
    `retain_until`          DATE NOT NULL COMMENT '§ 110f/2/a — konec povinné doby uchování',

    `captured_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `captured_by`           BIGINT UNSIGNED NULL COMMENT 'users.id',

    PRIMARY KEY (`id`),
    -- Opakované stažení téhož čtvrtletí = nové podání = nová sada záznamů. V rámci
    -- JEDNOHO podání ale musí být pořadí unikátní, jinak by dvojí zápis prošel tiše.
    UNIQUE KEY `uq_oss_filing_evidence_seq` (`supplier_id`, `submission_id`, `seq`),
    KEY `idx_oss_filing_evidence_period` (`supplier_id`, `period_year`, `period_quarter`),
    KEY `idx_oss_filing_evidence_country` (`supplier_id`, `consumption_country`, `supply_date`),
    KEY `idx_oss_filing_evidence_retention` (`retain_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Write-once ─────────────────────────────────────────────────────────────────────
-- Evidence, kterou lze přepsat, není evidence. Triggery odmítnou UPDATE i DELETE na
-- úrovni databáze, takže je neobejde ani chyba v aplikaci, ani ruční SQL. Ani jeden
-- z nich se NEDOTAZUJE vlastní tabulky (jen SIGNAL) — dotaz do `oss_filing_evidence`
-- uvnitř jejího vlastního triggeru by ji zamkl a INSERT by uvázl.
DROP TRIGGER IF EXISTS `trg_oss_filing_evidence_no_update`;
DROP TRIGGER IF EXISTS `trg_oss_filing_evidence_no_delete`;

DELIMITER //

CREATE TRIGGER `trg_oss_filing_evidence_no_update`
BEFORE UPDATE ON `oss_filing_evidence`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'OSS evidence (§ 110f ZDPH) je write-once a nelze ji měnit.';
END//

CREATE TRIGGER `trg_oss_filing_evidence_no_delete`
BEFORE DELETE ON `oss_filing_evidence`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'OSS evidence (§ 110f ZDPH) je write-once a nelze ji mazat.';
END//

DELIMITER ;
