<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payment\IbanValidator;

/**
 * @phpstan-type IdentityInput array{id:?int,full_name:string,birth_surname_present:bool,birth_surname:?string,effective_from:string,effective_to:?string}
 * @phpstan-type AddressInput array{id:?int,address_type:string,address_present:bool,street_line:?string,city:?string,postal_code:?string,country_code:?string,effective_from:string,effective_to:?string}
 * @phpstan-type ContactInput array{id:?int,contact_type:string,value:?string,is_primary:bool,is_active:bool}
 * @phpstan-type IdentifierInput array{id:?int,identifier_type:string,value:?string}
 * @phpstan-type AccountInput array{id:?int,label:string,bank_account:?string,allocation_basis_points:int,effective_from:string,effective_to:?string,is_active:bool}
 * @phpstan-type ProfileInput array{
 *   profile_status:string,
 *   payout_method:string,
 *   cash_allocation_basis_points:int,
 *   payout_effective_on:string,
 *   secure_delivery_channel:string,
 *   identity_history:list<IdentityInput>,
 *   addresses:list<AddressInput>,
 *   contacts:list<ContactInput>,
 *   identifiers:list<IdentifierInput>,
 *   accounts:list<AccountInput>
 * }
 */
final class PayrollPersonProfileValidator
{
    private const PROFILE_STATUSES = ['legacy', 'setup', 'ready'];
    private const PAYOUT_METHODS = ['cash', 'bank', 'mixed'];
    private const DELIVERY_CHANNELS = ['portal', 'paper'];
    private const ADDRESS_TYPES = ['residence', 'mailing'];
    private const CONTACT_TYPES = ['email', 'phone'];
    private const IDENTIFIER_TYPES = ['birth_number', 'ecp', 'vcp', 'foreign_tax_identifier'];

    public function __construct(private readonly IbanValidator $ibanValidator) {}

    /**
     * @param array<string,mixed> $input
     * @return ProfileInput
     */
    public function validate(array $input): array
    {
        $identityHistory = $this->identityHistory($input['identity_history'] ?? []);
        $addresses = $this->addresses($input['addresses'] ?? []);
        $contacts = $this->contacts($input['contacts'] ?? []);
        $identifiers = $this->identifiers($input['identifiers'] ?? []);
        $accounts = $this->accounts($input['accounts'] ?? []);

        $identityIntervals = [];
        foreach ($identityHistory as $row) {
            $identityIntervals[] = [
                'group' => 'identity',
                'effective_from' => $row['effective_from'],
                'effective_to' => $row['effective_to'],
            ];
        }
        $addressIntervals = [];
        foreach ($addresses as $row) {
            $addressIntervals[] = [
                'group' => $row['address_type'],
                'effective_from' => $row['effective_from'],
                'effective_to' => $row['effective_to'],
            ];
        }
        $this->assertNoIntervalOverlap($identityIntervals);
        $this->assertNoIntervalOverlap($addressIntervals);

        return [
            'profile_status' => $this->enum(
                $input['profile_status'] ?? null,
                self::PROFILE_STATUSES,
                'profile_status',
            ),
            'payout_method' => $this->enum(
                $input['payout_method'] ?? null,
                self::PAYOUT_METHODS,
                'payout_method',
            ),
            'cash_allocation_basis_points' => $this->integer(
                $input['cash_allocation_basis_points'] ?? null,
                'cash_allocation_basis_points',
                0,
                10000,
            ),
            'payout_effective_on' => $this->date(
                $input['payout_effective_on'] ?? null,
                'payout_effective_on',
            ),
            'secure_delivery_channel' => $this->enum(
                $input['secure_delivery_channel'] ?? null,
                self::DELIVERY_CHANNELS,
                'secure_delivery_channel',
            ),
            'identity_history' => $identityHistory,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'identifiers' => $identifiers,
            'accounts' => $accounts,
        ];
    }

    /** @return list<IdentityInput> */
    private function identityHistory(mixed $value): array
    {
        $rows = $this->list($value, 'identity_history');
        $result = [];
        foreach ($rows as $index => $row) {
            $id = $this->optionalId($row['id'] ?? null, "identity_history.{$index}.id");
            $birthSurnamePresent = array_key_exists('birth_surname', $row)
                && $row['birth_surname'] !== null;
            $birthSurname = $birthSurnamePresent
                ? $this->nullableText(
                    $row['birth_surname'],
                    "identity_history.{$index}.birth_surname",
                    191,
                )
                : null;
            if ($birthSurname !== null) {
                $this->rejectMaskPlaceholder(
                    $birthSurname,
                    "identity_history.{$index}.birth_surname",
                );
            }
            $result[] = [
                'id' => $id,
                'full_name' => $this->text(
                    $row['full_name'] ?? null,
                    "identity_history.{$index}.full_name",
                    191,
                ),
                'birth_surname_present' => $birthSurnamePresent,
                'birth_surname' => $birthSurname,
                'effective_from' => $this->date(
                    $row['effective_from'] ?? null,
                    "identity_history.{$index}.effective_from",
                ),
                'effective_to' => $this->nullableDate(
                    $row['effective_to'] ?? null,
                    "identity_history.{$index}.effective_to",
                ),
            ];
            $this->assertInterval($result[array_key_last($result)], "identity_history.{$index}");
            if ($result[array_key_last($result)]['effective_from'] > date('Y-m-d')) {
                throw new \InvalidArgumentException(
                    "identity_history.{$index}.effective_from nesmí být v budoucnosti."
                );
            }
        }

        return $result;
    }

    /** @return list<AddressInput> */
    private function addresses(mixed $value): array
    {
        $rows = $this->list($value, 'addresses');
        $result = [];
        foreach ($rows as $index => $row) {
            $id = $this->optionalId($row['id'] ?? null, "addresses.{$index}.id");
            $addressKeys = ['street_line', 'city', 'postal_code', 'country_code'];
            $presentCount = count(array_filter(
                $addressKeys,
                static fn (string $key): bool => array_key_exists($key, $row),
            ));
            if ($presentCount !== 0 && $presentCount !== count($addressKeys)) {
                throw new \InvalidArgumentException(
                    "addresses.{$index}: při změně adresy musí být poslané všechny její části."
                );
            }
            $addressPresent = $presentCount === count($addressKeys);
            if ($id === null && !$addressPresent) {
                throw new \InvalidArgumentException("Nová adresa vyžaduje všechny části adresy.");
            }
            $street = $addressPresent
                ? $this->text($row['street_line'], "addresses.{$index}.street_line", 255)
                : null;
            $city = $addressPresent
                ? $this->text($row['city'], "addresses.{$index}.city", 128)
                : null;
            $postalCode = $addressPresent
                ? $this->text($row['postal_code'], "addresses.{$index}.postal_code", 24)
                : null;
            $countryCode = $addressPresent
                ? $this->countryCode($row['country_code'], "addresses.{$index}.country_code")
                : null;
            foreach ([$street, $city, $postalCode] as $addressPart) {
                if ($addressPart !== null) {
                    $this->rejectMaskPlaceholder($addressPart, "addresses.{$index}");
                }
            }
            $result[] = [
                'id' => $id,
                'address_type' => $this->enum(
                    $row['address_type'] ?? null,
                    self::ADDRESS_TYPES,
                    "addresses.{$index}.address_type",
                ),
                'address_present' => $addressPresent,
                'street_line' => $street,
                'city' => $city,
                'postal_code' => $postalCode,
                'country_code' => $countryCode,
                'effective_from' => $this->date(
                    $row['effective_from'] ?? null,
                    "addresses.{$index}.effective_from",
                ),
                'effective_to' => $this->nullableDate(
                    $row['effective_to'] ?? null,
                    "addresses.{$index}.effective_to",
                ),
            ];
            $this->assertInterval($result[array_key_last($result)], "addresses.{$index}");
            if ($result[array_key_last($result)]['effective_from'] > date('Y-m-d')) {
                throw new \InvalidArgumentException(
                    "addresses.{$index}.effective_from nesmí být v budoucnosti."
                );
            }
        }

        return $result;
    }

    /** @return list<ContactInput> */
    private function contacts(mixed $value): array
    {
        $rows = $this->list($value, 'contacts');
        $result = [];
        $primary = [];
        foreach ($rows as $index => $row) {
            $id = $this->optionalId($row['id'] ?? null, "contacts.{$index}.id");
            $type = $this->enum(
                $row['contact_type'] ?? null,
                self::CONTACT_TYPES,
                "contacts.{$index}.contact_type",
            );
            $contactValue = array_key_exists('value', $row)
                ? $this->nullableText($row['value'], "contacts.{$index}.value", 191)
                : null;
            if ($id === null && $contactValue === null) {
                throw new \InvalidArgumentException("Nový kontakt vyžaduje hodnotu.");
            }
            if ($contactValue !== null) {
                $this->rejectMaskPlaceholder($contactValue, "contacts.{$index}.value");
                if ($type === 'email' && filter_var($contactValue, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException("contacts.{$index}.value není platný e-mail.");
                }
                if ($type === 'phone' && preg_match('/^\+?[0-9][0-9 ()\/.-]{4,39}$/', $contactValue) !== 1) {
                    throw new \InvalidArgumentException("contacts.{$index}.value není platný telefon.");
                }
            }
            $isPrimary = $this->boolean($row['is_primary'] ?? false, "contacts.{$index}.is_primary");
            $isActive = $this->boolean($row['is_active'] ?? true, "contacts.{$index}.is_active");
            if ($isPrimary && $isActive) {
                if (isset($primary[$type])) {
                    throw new \InvalidArgumentException("Kontakty typu {$type} mají více aktivních primárních hodnot.");
                }
                $primary[$type] = true;
            }
            $result[] = [
                'id' => $id,
                'contact_type' => $type,
                'value' => $contactValue,
                'is_primary' => $isPrimary,
                'is_active' => $isActive,
            ];
        }

        return $result;
    }

    /** @return list<IdentifierInput> */
    private function identifiers(mixed $value): array
    {
        $rows = $this->list($value, 'identifiers');
        $result = [];
        $types = [];
        foreach ($rows as $index => $row) {
            $type = $this->enum(
                $row['identifier_type'] ?? null,
                self::IDENTIFIER_TYPES,
                "identifiers.{$index}.identifier_type",
            );
            if (isset($types[$type])) {
                throw new \InvalidArgumentException("Identifikátor typu {$type} je v požadavku vícekrát.");
            }
            $types[$type] = true;
            $id = $this->optionalId($row['id'] ?? null, "identifiers.{$index}.id");
            $plaintext = $this->nullableText(
                $row['value'] ?? null,
                "identifiers.{$index}.value",
                191,
            );
            if ($id === null && $plaintext === null) {
                throw new \InvalidArgumentException("Nový identifikátor {$type} vyžaduje hodnotu.");
            }
            if ($plaintext !== null) {
                $plaintext = $this->normalizeIdentifier($plaintext, $type);
            }
            $result[] = [
                'id' => $id,
                'identifier_type' => $type,
                'value' => $plaintext,
            ];
        }

        return $result;
    }

    /** @return list<AccountInput> */
    private function accounts(mixed $value): array
    {
        $rows = $this->list($value, 'accounts');
        $result = [];
        foreach ($rows as $index => $row) {
            $id = $this->optionalId($row['id'] ?? null, "accounts.{$index}.id");
            $plaintext = $this->nullableText(
                $row['bank_account'] ?? null,
                "accounts.{$index}.bank_account",
                191,
            );
            if ($id === null && $plaintext === null) {
                throw new \InvalidArgumentException("Nový bankovní účet vyžaduje bank_account.");
            }
            if ($plaintext !== null) {
                $plaintext = $this->normalizeBankAccount($plaintext);
            }
            $result[] = [
                'id' => $id,
                'label' => $this->text($row['label'] ?? null, "accounts.{$index}.label", 128),
                'bank_account' => $plaintext,
                'allocation_basis_points' => $this->integer(
                    $row['allocation_basis_points'] ?? 10000,
                    "accounts.{$index}.allocation_basis_points",
                    0,
                    10000,
                ),
                'effective_from' => $this->date(
                    $row['effective_from'] ?? null,
                    "accounts.{$index}.effective_from",
                ),
                'effective_to' => $this->nullableDate(
                    $row['effective_to'] ?? null,
                    "accounts.{$index}.effective_to",
                ),
                'is_active' => $this->boolean($row['is_active'] ?? true, "accounts.{$index}.is_active"),
            ];
            $this->assertInterval($result[array_key_last($result)], "accounts.{$index}");
        }

        return $result;
    }

    /** @param list<array{group:string,effective_from:string,effective_to:?string}> $rows */
    private function assertNoIntervalOverlap(array $rows): void
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group']][] = $row;
        }
        foreach ($grouped as $groupName => $items) {
            usort(
                $items,
                static fn (array $left, array $right): int =>
                    strcmp($left['effective_from'], $right['effective_from']),
            );
            $previousTo = null;
            foreach ($items as $index => $item) {
                $from = $item['effective_from'];
                if ($index > 0 && ($previousTo === null || $from <= $previousTo)) {
                    throw new \InvalidArgumentException("Intervaly {$groupName} se překrývají.");
                }
                $previousTo = $item['effective_to'];
            }
        }
    }

    /** @param array{effective_from:string,effective_to:?string} $row */
    private function assertInterval(array $row, string $path): void
    {
        if ($row['effective_to'] !== null
            && $row['effective_to'] < $row['effective_from']
        ) {
            throw new \InvalidArgumentException("{$path}.effective_to nesmí být před effective_from.");
        }
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$path} musí být pole.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException("{$path}.{$index} musí být objekt.");
            }
            $normalized = [];
            foreach ($row as $key => $cell) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException("{$path}.{$index} musí být objekt.");
                }
                $normalized[$key] = $cell;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    private function text(mixed $value, string $path, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$path} musí být řetězec.");
        }
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > $maxLength) {
            throw new \InvalidArgumentException("{$path} je prázdné nebo příliš dlouhé.");
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new \InvalidArgumentException("{$path} obsahuje řídicí znak.");
        }

        return $normalized;
    }

    private function nullableText(mixed $value, string $path, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->text($value, $path, $maxLength);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$path} má nepodporovanou hodnotu.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if (!is_int($validated)) {
            throw new \InvalidArgumentException("{$path} musí být celé číslo {$minimum}–{$maximum}.");
        }

        return $validated;
    }

    private function optionalId(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->integer($value, $path, 1, PHP_INT_MAX);
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$path} musí být boolean.");
        }

        return $value;
    }

    private function date(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$path} musí být datum YYYY-MM-DD.");
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$path} musí být platné datum YYYY-MM-DD.");
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $path);
    }

    private function countryCode(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException("{$path} musí být dvoupísmenný kód země.");
        }

        return strtoupper($value);
    }

    private function rejectMaskPlaceholder(string $value, string $path): void
    {
        if (str_contains($value, '•') || preg_match('/\*{3,}/u', $value) === 1) {
            throw new \InvalidArgumentException("{$path} nesmí obsahovat maskovanou hodnotu.");
        }
    }

    private function normalizeIdentifier(string $value, string $type): string
    {
        $this->rejectMaskPlaceholder($value, 'identifier.value');
        $compact = strtoupper((string) preg_replace('/\s+/', '', trim($value)));

        return match ($type) {
            'birth_number' => $this->normalizeBirthNumber($compact),
            'ecp' => $this->numericIdentifier($compact, 'EČP', 9, 10),
            'vcp' => $this->numericIdentifier($compact, 'VČP', 9, 9),
            'foreign_tax_identifier' => $this->foreignTaxIdentifier($compact),
            default => throw new \InvalidArgumentException('Typ identifikátoru není podporovaný.'),
        };
    }

    private function normalizeBirthNumber(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        if (!preg_match('/^\d{9,10}$/', $digits)) {
            throw new \InvalidArgumentException('Rodné číslo musí mít 9 nebo 10 číslic.');
        }

        $yearPart = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        foreach ([70, 50, 20] as $offset) {
            if ($month > $offset) {
                $month -= $offset;
                break;
            }
        }
        $year = strlen($digits) === 9 || $yearPart >= 54
            ? 1900 + $yearPart
            : 2000 + $yearPart;
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('Rodné číslo neobsahuje platné datum narození.');
        }
        $birthDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($birthDate > new \DateTimeImmutable('today')) {
            throw new \InvalidArgumentException('Rodné číslo nesmí obsahovat budoucí datum narození.');
        }
        if (strlen($digits) === 9) {
            if ($year >= 1954) {
                throw new \InvalidArgumentException('Devítimístné rodné číslo je přípustné jen před rokem 1954.');
            }
        } else {
            $number = (int) $digits;
            $legacyException = $year < 1985
                && ((int) substr($digits, 0, 9)) % 11 === 10
                && (int) substr($digits, -1) === 0;
            if ($number % 11 !== 0 && !$legacyException) {
                throw new \InvalidArgumentException('Rodné číslo neprošlo kontrolou modulo 11.');
            }
        }

        return substr($digits, 0, 6) . '/' . substr($digits, 6);
    }

    private function numericIdentifier(
        string $value,
        string $label,
        int $minimumLength,
        int $maximumLength,
    ): string
    {
        if (preg_match(
            '/^\d{' . $minimumLength . ',' . $maximumLength . '}$/',
            $value,
        ) !== 1) {
            $length = $minimumLength === $maximumLength
                ? (string) $minimumLength
                : "{$minimumLength} až {$maximumLength}";
            throw new \InvalidArgumentException(
                "{$label} musí obsahovat {$length} číslic."
            );
        }

        return $value;
    }

    private function foreignTaxIdentifier(string $value): string
    {
        if (preg_match('/^[A-Z]{2}:[A-Z0-9][A-Z0-9.\/-]{2,29}$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Zahraniční daňový identifikátor musí mít formát CC:HODNOTA.'
            );
        }

        return $value;
    }

    private function normalizeBankAccount(string $raw): string
    {
        $this->rejectMaskPlaceholder($raw, 'bank_account');
        $value = strtoupper(trim($raw));
        $compact = (string) preg_replace('/\s+/', '', $value);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $compact) === 1) {
            if (!$this->ibanValidator->isValid($compact)) {
                throw new \InvalidArgumentException('IBAN není platný.');
            }
            return $compact;
        }

        if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $compact, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Český účet musí mít formát [předčíslí-]číslo/kód banky.'
            );
        }
        $prefix = $match[1];
        if (($prefix !== '' && !$this->validCzechAccountPart($prefix, 6))
            || !$this->validCzechAccountPart($match[2], 10)
        ) {
            throw new \InvalidArgumentException('Český bankovní účet neprošel kontrolou modulo 11.');
        }
        $base = ltrim($match[2], '0');
        if ($base === '') {
            throw new \InvalidArgumentException('Číslo bankovního účtu nesmí být nulové.');
        }
        $normalizedPrefix = ltrim($prefix, '0');

        return ($normalizedPrefix === '' ? '' : $normalizedPrefix . '-')
            . $base . '/' . $match[3];
    }

    private function validCzechAccountPart(string $value, int $length): bool
    {
        $weights = $length === 6
            ? [10, 5, 8, 4, 2, 1]
            : [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $digits = str_pad($value, $length, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        return $sum % 11 === 0;
    }
}
