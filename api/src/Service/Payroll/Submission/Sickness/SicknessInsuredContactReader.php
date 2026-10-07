<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Telefon a e-mail pojištěnce z karty osoby pro `pojistenec/kontakt` v NEMPRI.
 *
 * Kontakt je v XSD nepovinný, a tak se nikdy nehádá: z aktivních kontaktů
 * daného typu se vezme primární, a když primární není, jediný aktivní.
 * Víc kandidátů bez primárního nebo hodnota, která nesplní tvar z XSD, znamená
 * „bez kontaktu“, ne chybu podání.
 */
final readonly class SicknessInsuredContactReader
{
    public function __construct(
        private Connection $db,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /** @return array{phone:?string,email:?string} */
    public function forEmployee(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, contact_type, contact_value_ciphertext, contact_value_hash, is_primary
               FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
              ORDER BY id',
        );
        $statement->execute([$supplierId, $employeeId]);
        $byType = ['email' => [], 'phone' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['contact_type'];
            if (isset($byType[$type])) {
                $byType[$type][] = $row;
            }
        }

        return [
            'phone' => self::phone($this->pick($supplierId, $byType['phone'], PayrollSensitiveField::CONTACT_PHONE)),
            'email' => self::email($this->pick($supplierId, $byType['email'], PayrollSensitiveField::CONTACT_EMAIL)),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function pick(int $supplierId, array $rows, PayrollSensitiveField $field): ?string
    {
        $primary = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['is_primary'] === 1,
        ));
        $candidates = $primary !== [] ? $primary : $rows;
        if (count($candidates) !== 1) {
            return null;
        }
        $row = $candidates[0];
        $plaintext = $this->sensitiveData->reveal(
            (string) $row['contact_value_ciphertext'],
            $field,
            $supplierId,
            (int) $row['id'],
            PayrollRevealPurpose::SUBMISSION_CSSZ_SICKNESS,
        );
        $hash = $this->sensitiveData->lookupHash($plaintext, $field, $supplierId);
        if (!hash_equals((string) $row['contact_value_hash'], $hash)) {
            throw new \RuntimeException('Otisk kontaktu osoby neodpovídá ciphertextu.');
        }

        return $plaintext;
    }

    /** XSD `CtKontakt/telefon`: nejvýš 33 znaků, bez závorek a s jednotlivými mezerami. */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $phone = trim((string) preg_replace('/[()]+/', '', $value));
        $phone = (string) preg_replace('/ {2,}/', ' ', $phone);

        return $phone !== '' && mb_strlen($phone, 'UTF-8') <= 33
            && preg_match('/^[0-9A-Za-z\-,.+\'\/\\\\]+( +[0-9A-Za-z\-,.+\'\/\\\\]+)*$/D', $phone) === 1
            ? $phone
            : null;
    }

    /** XSD `CtKontakt/email`: `[^@]+@[^.]+\..+`, nejvýš 250 znaků. */
    public static function email(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $email = trim($value);

        return mb_strlen($email, 'UTF-8') <= 250
            && preg_match('/^[^@]+@[^.]+\..+$/D', $email) === 1
            ? $email
            : null;
    }
}
