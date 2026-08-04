<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollDocumentEmployerSnapshotProvider
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(int $supplierId): PayrollDocumentEmployerSnapshot
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma mzdového dokumentu není platná.');
        }
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Snapshot zaměstnavatele vyžaduje aktivní transakci.',
            );
        }

        $supplierStatement = $pdo->prepare(
            'SELECT COALESCE(NULLIF(TRIM(supplier.display_name), ""),
                            TRIM(supplier.company_name)) AS employer_name,
                    TRIM(supplier.ic) AS identification_number,
                    TRIM(supplier.dic) AS tax_identification_number,
                    TRIM(supplier.street) AS street_line,
                    TRIM(supplier.city) AS city,
                    TRIM(supplier.zip) AS postal_code,
                    UPPER(TRIM(country.iso2)) AS country_code,
                    TRIM(country.name_cs) AS country_name,
                    TRIM(supplier.email) AS supplier_email,
                    NULLIF(TRIM(supplier.phone), "") AS supplier_phone
               FROM supplier
               JOIN countries country ON country.id = supplier.country_id
              WHERE supplier.id = ?
              FOR UPDATE'
        );
        $supplierStatement->execute([$supplierId]);
        $supplierRow = $supplierStatement->fetch(PDO::FETCH_ASSOC);
        if ($supplierRow === false) {
            throw new \DomainException(
                'Zaměstnavatel mzdového dokumentu neexistuje.',
            );
        }
        $supplier = self::row($supplierRow, 'zaměstnavatele');

        $settingsStatement = $pdo->prepare(
            'SELECT NULLIF(TRIM(payroll_contact_name), "") AS payroll_contact_name,
                    NULLIF(TRIM(payroll_contact_email), "") AS payroll_contact_email,
                    NULLIF(TRIM(payroll_contact_phone), "") AS payroll_contact_phone
               FROM payroll_employer_settings
              WHERE supplier_id = ?
              FOR UPDATE'
        );
        $settingsStatement->execute([$supplierId]);
        $settingsRow = $settingsStatement->fetch(PDO::FETCH_ASSOC);
        if ($settingsRow === false) {
            throw new \DomainException(
                'Pro mzdový dokument chybí nastavení zaměstnavatele.',
            );
        }
        $settings = self::row($settingsRow, 'nastavení zaměstnavatele');

        $employerName = $this->requiredText($supplier, 'employer_name');
        $issuerName = $this->nullableText($settings, 'payroll_contact_name')
            ?? $employerName;
        $issuerEmail = $this->nullableText($settings, 'payroll_contact_email')
            ?? $this->requiredText($supplier, 'supplier_email');
        $issuerPhone = $this->nullableText($settings, 'payroll_contact_phone')
            ?? $this->requiredText($supplier, 'supplier_phone');

        return new PayrollDocumentEmployerSnapshot(
            name: $employerName,
            identificationNumber: $this->requiredText(
                $supplier,
                'identification_number',
            ),
            taxIdentificationNumber: $this->requiredText(
                $supplier,
                'tax_identification_number',
            ),
            streetLine: $this->requiredText($supplier, 'street_line'),
            city: $this->requiredText($supplier, 'city'),
            postalCode: $this->requiredText($supplier, 'postal_code'),
            countryCode: $this->requiredText($supplier, 'country_code'),
            countryName: $this->requiredText($supplier, 'country_name'),
            issuerName: $issuerName,
            issuerEmail: $issuerEmail,
            issuerPhone: $issuerPhone,
        );
    }

    /** @param array<string,mixed> $row */
    private function requiredText(array $row, string $field): string
    {
        return $this->nullableText($row, $field)
            ?? throw new \DomainException(
                "Zaměstnavatel nemá pro mzdový dokument vyplněné pole {$field}.",
            );
    }

    /** @param array<string,mixed> $row */
    private function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(
                "Pole zaměstnavatele {$field} není text.",
            );
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databázový řádek {$label} není objekt.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový řádek {$label} má neplatný klíč.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
