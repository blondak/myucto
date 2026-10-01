-- MyÚčto.cz — dimenze zvolené už při nahrání dokladu do příchozích (Účtování podle dimenzí, F5).
--
-- Zaměstnanec nebo klient na portálu nahrává účtenku „svého" střediska. Volba se uloží
-- k podání a při zpracování (vytěžení i ruční přepis) se propíše do hlavičky vzniklé
-- přijaté faktury (document_dimensions, item_no 0) — jen u typu, který faktura ještě
-- explicitně nemá. Před výchozími hodnotami dodavatele a zakázky má přednost, protože
-- ty se na doklad neukládají a doplňují se až při zaúčtování.
--
-- Jde jen o předvolbu: smazání hodnoty dimenze ji zahodí (CASCADE), nebrání mu.
--
-- Idempotence: nativní MariaDB IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_invoice_submission_dimensions (
    submission_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (submission_id, dimension_type_id),
    KEY idx_pisd_supplier (supplier_id, submission_id),
    KEY idx_pisd_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_pisd_submission FOREIGN KEY (submission_id)
        REFERENCES purchase_invoice_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pisd_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_pisd_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
