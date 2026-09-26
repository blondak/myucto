<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtecí dotazy importu registrací. Jen SELECTy — veškeré zápisy jdou přes
 * služby a repozitáře domény (osoba, vztah, karta, zákonná evidence, identita).
 *
 * Párování podle rodného čísla a OIČ jde přes slepý index (`value_hash`), takže
 * se nic nedešifruje: stačí porovnat otisk hodnoty ze souboru s uloženým.
 */
final class RegistrationImportLookup
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<int> */
    public function employeesByPersonExternalIdHash(int $supplierId, string $environment, string $hash): array
    {
        return $this->ids(
            'SELECT DISTINCT employee_id
               FROM payroll_person_external_ids
              WHERE supplier_id = ? AND environment = ? AND identifier_type = "ik_mpsv"
                AND value_hash = ? AND valid_to IS NULL
              ORDER BY employee_id',
            [$supplierId, $environment, $hash],
        );
    }

    /** @return list<int> */
    public function employeesByIdentifierHash(int $supplierId, string $identifierType, string $hash): array
    {
        return $this->ids(
            'SELECT DISTINCT employee_id
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND identifier_type = ? AND value_hash = ?
              ORDER BY employee_id',
            [$supplierId, $identifierType, $hash],
        );
    }

    /**
     * @return list<array{
     *   id:int,employee_id:int,code:string,relation_type:string,status:string,
     *   is_primary:bool,start_date:?string,actual_start_date:?string,
     *   end_date:?string,row_version:int
     * }>
     */
    public function employments(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status, is_primary,
                    start_date, actual_start_date, end_date, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ? AND is_legacy_projection = 0
              ORDER BY start_date, id'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'is_primary' => (bool) $row['is_primary'],
                'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
                'actual_start_date' => $row['actual_start_date'] === null ? null : (string) $row['actual_start_date'],
                'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
                'row_version' => (int) $row['row_version'],
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function employment(int $supplierId, int $employmentId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $employeeId = $statement->fetchColumn();
        if ($employeeId === false) {
            return null;
        }
        foreach ($this->employments($supplierId, (int) $employeeId) as $row) {
            if ($row['id'] === $employmentId) {
                return $row;
            }
        }

        return null;
    }

    public function employeeName(int $supplierId, int $employeeId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT full_name FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId]);
        $name = $statement->fetchColumn();

        return is_string($name) ? $name : null;
    }

    /**
     * @return array{id:int,street_line:string,city:string,postal_code:string,country_code:string,effective_from:string,effective_to:?string}|null
     */
    public function addressAt(int $supplierId, int $employeeId, string $addressType, string $onDate): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, street_line, city, postal_code, country_code, effective_from, effective_to
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ? AND address_type = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $addressType, $onDate, $onDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'street_line' => (string) $row['street_line'],
            'city' => (string) $row['city'],
            'postal_code' => (string) $row['postal_code'],
            'country_code' => (string) $row['country_code'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => $row['effective_to'] === null ? null : (string) $row['effective_to'],
        ];
    }

    public function hasAddressType(int $supplierId, int $employeeId, string $addressType): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ? AND address_type = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $addressType]);

        return $statement->fetchColumn() !== false;
    }

    public function healthInsurerAt(int $supplierId, int $employeeId, string $onDate): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT insurer_code
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $code = $statement->fetchColumn();

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function defaultOfficeId(int $supplierId): ?int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT default_office_id FROM payroll_employer_settings WHERE supplier_id = ?'
        );
        $statement->execute([$supplierId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Všechny variabilní symboly ČSSZ, které firma u svých mzdových účtáren
     * vede: aktuální ostrý, testovací i historické verze registrace.
     *
     * @return list<string>
     */
    public function variableSymbols(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT social_security_variable_symbol AS vs FROM payroll_offices
              WHERE supplier_id = ? AND social_security_variable_symbol IS NOT NULL
             UNION
             SELECT test_social_security_variable_symbol FROM payroll_offices
              WHERE supplier_id = ? AND test_social_security_variable_symbol IS NOT NULL
             UNION
             SELECT social_security_variable_symbol FROM payroll_office_registration_versions
              WHERE supplier_id = ?'
        );
        $statement->execute([$supplierId, $supplierId, $supplierId]);
        $symbols = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $normalized = self::variableSymbol((string) $value);
            if ($normalized !== null) {
                $symbols[$normalized] = true;
            }
        }

        return array_map(strval(...), array_keys($symbols));
    }

    /**
     * Osoby se shodným jménem, příjmením a datem narození v kterékoli verzi identity.
     * Slouží jen tam, kde věta žádný identifikátor nenese (formulář hlášení větve B).
     *
     * @return list<int>
     */
    public function employeesByNameAndBirthDate(
        int $supplierId,
        string $firstName,
        string $lastName,
        string $birthDate,
    ): array {
        return $this->ids(
            'SELECT DISTINCT employee_id
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND birth_date = ?
                AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?)
              ORDER BY employee_id',
            [$supplierId, $birthDate, $firstName, $lastName],
        );
    }

    /** VS bez oddělovačů a úvodních nul; `null`, když v něm žádná číslice není. */
    public static function variableSymbol(string $value): ?string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $value), '0');

        return $digits === '' ? null : $digits;
    }

    /**
     * @param list<mixed> $params
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
