-- MyÚčto.cz — MZ-08-W07: evidence tuzemských pracovních cest a klasifikované cestovní náhrady.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_business_trips (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  employee_id               BIGINT UNSIGNED NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  country_code              CHAR(2) NOT NULL DEFAULT 'CZ',
  departure_at              DATETIME NOT NULL,
  arrival_at                DATETIME NOT NULL,
  origin_place              VARCHAR(190) NOT NULL,
  destination_place         VARCHAR(190) NOT NULL,
  purpose                   VARCHAR(255) NOT NULL,
  transport_mode            ENUM('public_transport','company_vehicle','private_vehicle','other')
                            NOT NULL DEFAULT 'public_transport',
  meal_rate_band_1_minor    BIGINT UNSIGNED NULL,
  meal_rate_band_2_minor    BIGINT UNSIGNED NULL,
  meal_rate_band_3_minor    BIGINT UNSIGNED NULL,
  advance_minor             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  settlement_period_start   DATE NOT NULL,
  status                    ENUM('draft','approved','settled','cancelled') NOT NULL DEFAULT 'draft',
  entitlement_total_minor   BIGINT UNSIGNED NULL,
  exempt_total_minor        BIGINT UNSIGNED NULL,
  taxable_total_minor       BIGINT UNSIGNED NULL,
  ruleset_id                VARCHAR(190) NULL,
  calculation_json          LONGTEXT NULL CHECK (
    calculation_json IS NULL OR JSON_VALID(calculation_json)
  ),
  calculation_hash          BINARY(32) NULL,
  row_version               INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                BIGINT UNSIGNED NULL,
  approved_by               BIGINT UNSIGNED NULL,
  approved_at               DATETIME NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_business_trip_supplier_id (supplier_id, id),
  KEY idx_payroll_business_trip_period
    (supplier_id, settlement_period_start, status),
  KEY idx_payroll_business_trip_employment
    (supplier_id, employment_id, departure_at),
  CONSTRAINT fk_payroll_business_trip_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_business_trip_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_business_trip_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_business_trip_approved_by
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_business_trip_interval CHECK (arrival_at > departure_at),
  CONSTRAINT chk_payroll_business_trip_period
    CHECK (DAY(settlement_period_start) = 1),
  CONSTRAINT chk_payroll_business_trip_country
    CHECK (country_code REGEXP '^[A-Z]{2}$'),
  CONSTRAINT chk_payroll_business_trip_rates CHECK (
    (meal_rate_band_1_minor IS NULL OR meal_rate_band_1_minor > 0)
    AND (meal_rate_band_2_minor IS NULL OR meal_rate_band_2_minor > 0)
    AND (meal_rate_band_3_minor IS NULL OR meal_rate_band_3_minor > 0)
  ),
  CONSTRAINT chk_payroll_business_trip_settlement CHECK (
    status IN ('draft', 'cancelled')
    OR (
      entitlement_total_minor IS NOT NULL
      AND exempt_total_minor IS NOT NULL
      AND taxable_total_minor IS NOT NULL
      AND ruleset_id IS NOT NULL
      AND calculation_json IS NOT NULL
      AND calculation_hash IS NOT NULL
      AND OCTET_LENGTH(calculation_hash) = 32
      AND entitlement_total_minor = exempt_total_minor + taxable_total_minor
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_business_trip_items (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  trip_id                       BIGINT UNSIGNED NOT NULL,
  item_kind                     ENUM('transport','accommodation','incidental','private_vehicle')
                                NOT NULL,
  spent_on                      DATE NOT NULL,
  description                   VARCHAR(190) NOT NULL,
  amount_minor                  BIGINT UNSIGNED NULL,
  is_documented                 TINYINT(1) NOT NULL DEFAULT 1,
  document_reference            VARCHAR(190) NULL,
  vehicle_kind                  ENUM('car','single_track') NULL,
  distance_m                    BIGINT UNSIGNED NULL,
  consumption_ml_per_100km      INT UNSIGNED NULL,
  fuel_kind                     ENUM('petrol_95','petrol_98','diesel','electricity') NULL,
  documented_fuel_price_minor   BIGINT UNSIGNED NULL,
  sort_order                    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_business_trip_item_supplier_id (supplier_id, id),
  KEY idx_payroll_business_trip_item_trip (supplier_id, trip_id, sort_order, id),
  CONSTRAINT fk_payroll_business_trip_item_trip
    FOREIGN KEY (supplier_id, trip_id)
    REFERENCES payroll_business_trips (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_business_trip_item_documented
    CHECK (is_documented IN (0, 1)),
  CONSTRAINT chk_payroll_business_trip_item_shape CHECK (
    (
      item_kind = 'private_vehicle'
      AND amount_minor IS NULL
      AND vehicle_kind IS NOT NULL
      AND distance_m IS NOT NULL AND distance_m > 0
      AND consumption_ml_per_100km IS NOT NULL AND consumption_ml_per_100km > 0
      AND fuel_kind IS NOT NULL
      AND (documented_fuel_price_minor IS NULL OR documented_fuel_price_minor > 0)
    )
    OR (
      item_kind <> 'private_vehicle'
      AND amount_minor IS NOT NULL
      AND vehicle_kind IS NULL
      AND distance_m IS NULL
      AND consumption_ml_per_100km IS NULL
      AND fuel_kind IS NULL
      AND documented_fuel_price_minor IS NULL
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_business_trip_free_meals (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  trip_id       BIGINT UNSIGNED NOT NULL,
  meal_date     DATE NOT NULL,
  meal_count    TINYINT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_business_trip_free_meal (supplier_id, trip_id, meal_date),
  CONSTRAINT fk_payroll_business_trip_free_meal_trip
    FOREIGN KEY (supplier_id, trip_id)
    REFERENCES payroll_business_trips (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_business_trip_free_meal_count
    CHECK (meal_count BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materializace schváleného vyúčtování do mzdových vstupů.
ALTER TABLE payroll_inputs
  MODIFY COLUMN source_kind
    ENUM('manual','recurring','time','absence','import','correction','travel') NOT NULL;

ALTER TABLE payroll_inputs
  DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;

ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
    (
      recurring_component_id IS NOT NULL
      AND source_kind = 'recurring'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
      AND OCTET_LENGTH(source_snapshot_hash) = 32
    )
    OR
    (
      recurring_component_id IS NULL
      AND source_kind = 'travel'
      AND external_id LIKE 'travel:%'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
      AND OCTET_LENGTH(source_snapshot_hash) = 32
    )
    OR
    (
      recurring_component_id IS NULL
      AND source_kind NOT IN ('recurring', 'travel')
      AND (
        (
          source_snapshot_json IS NULL
          AND source_snapshot_hash IS NULL
        )
        OR
        (
          source_kind = 'manual'
          AND external_id LIKE 'quick-monthly:%'
          AND source_snapshot_json IS NOT NULL
          AND JSON_VALID(source_snapshot_json)
          AND source_snapshot_hash IS NOT NULL
          AND OCTET_LENGTH(source_snapshot_hash) = 32
        )
      )
    )
  );

-- Vazba mzdového vstupu na konkrétní pracovní cestu (tabulka z 1210).
ALTER TABLE payroll_travel_compensation_links
  ADD COLUMN IF NOT EXISTS trip_id BIGINT UNSIGNED NULL AFTER input_id;

ALTER TABLE payroll_travel_compensation_links
  DROP CONSTRAINT IF EXISTS fk_payroll_travel_link_trip;

ALTER TABLE payroll_travel_compensation_links
  ADD CONSTRAINT fk_payroll_travel_link_trip
    FOREIGN KEY (supplier_id, trip_id)
    REFERENCES payroll_business_trips (supplier_id, id) ON DELETE RESTRICT;
