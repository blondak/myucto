-- MyÚčto.cz — Výchozí dimenze odběratele/dodavatele a zakázky (Firma → Dimenze)
--
-- Karta klienta (jeden záznam `clients` slouží v roli odběratele i dodavatele)
-- a zakázka (`projects`) nesou pro každý typ dimenze nejvýš jednu výchozí hodnotu.
-- Editory dokladů jimi předvyplní prázdné dimenze hlavičky a účtování
-- (DimensionStamper) je doplní dokladům, které hodnotu typu nemají — i těm z importu,
-- vytěžení, opakovaných faktur a automatizací. Přednost má zakázka před klientem;
-- dimenze uvedená na dokladu vždy vyhrává.
--
-- ## Proč vlastní tabulka, ne `document_dimensions`
--
-- `document_dimensions` drží dimenze DOKLADŮ: polymorfní odkaz bez FK, úklid při
-- smazání dokladu ručně (DimensionService::forgetDocument), `valueInUse()` ji bere
-- jako použití hodnoty v účetnictví. Výchozí hodnota je nastavení číselníku, ne
-- analytika dokladu. Tady má každý řádek skutečný FK na klienta nebo zakázku
-- s ON DELETE CASCADE, takže smazání klienta/zakázky (i firmy) výchozí dimenze
-- uklidí samo a sirotci nevzniknou.
--
-- `supplier_id` je firma klienta (zakázka firmu nemá, dědí ji přes clients.supplier_id);
-- nese se kvůli tenant predikátu. Hodnota smí být firemní i globální (skupina firem) —
-- viditelnost hlídá DimensionService::normalize() při uložení a resolver při čtení.
-- Smazání hodnoty (jde jen u nepoužité) výchozí nastavení odstraní (CASCADE).
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS dimension_defaults (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dimdef_client_type (client_id, dimension_type_id),
    UNIQUE KEY uq_dimdef_project_type (project_id, dimension_type_id),
    KEY idx_dimdef_supplier (supplier_id),
    KEY idx_dimdef_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_dimdef_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimdef_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimdef_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimdef_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_dimdef_owner CHECK ((client_id IS NULL) <> (project_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
