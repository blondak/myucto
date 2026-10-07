<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Dohlášení údajů zaměstnance akcí REGZEC A3 („Změna").
 *
 * Zaměstnance přihlášené do 31. 3. 2026 přes ONZ zná ČSSZ bez údajů, které
 * ONZ nevedla (postavení v zaměstnání, režim, nepřetržitý provoz, místo
 * výkonu, profese, pozice, vzdělání, stát rezidence…). Zákon č. 323/2025 Sb.
 * ukládá je doplnit akcí A3; do 10009 se uvádí den, kdy podání odchází, a
 * hlásí se poslední stav údaje. U PPV, které už skončilo, se uvádí i datum
 * skončení (Všeobecné zásady REGZEC, akce 3). ČSSZ přijímá celý snímek
 * profilu i jen dohlašované údaje — Premier i PAMICA posílaly celý.
 *
 * Třída jen převádí ověřený profil A1 na deltu události; vstupní kontroly
 * profilu dělá {@see PayrollRegistrationA1SnapshotBuilder}.
 */
final class PayrollRegistrationProfileCompletion
{
    /** Celý profil A1 (co A3 připouští). */
    public const FULL = 'full';

    /** Jen údaje, které ONZ nevedla (A3-DOHL). */
    public const MINIMAL = 'minimal';

    /** Pracovní údaje, které se dohlašují vždy. */
    private const COMPLETION_EMPLOYMENT = [
        'employment_status_code',
        'work_mode_code',
        'continuous_operation',
        'prevailing_workplace_code',
        'contract_workplace',
        'workplace_city',
        'workplace_municipality_code',
        'profession_code',
        'required_education_code',
        'position_name',
        'leadership',
    ];

    public static function requireMode(mixed $mode): string
    {
        if ($mode !== self::FULL && $mode !== self::MINIMAL) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_completion_mode_invalid',
                'Rozsah dohlášení údajů musí být celý profil, nebo jen údaje, '
                    . 'které přihláška ONZ nevedla. Vyberte jednu z možností '
                    . 've formuláři.',
            );
        }

        return $mode;
    }

    /**
     * @param array<string,mixed> $identity identita osoby k rozhodnému dni
     * @param array<string,mixed> $identifiers rodné číslo, EČP…
     * @return array<string,mixed> delta události A3
     */
    public static function delta(
        PayrollRegistrationA1Snapshot $a1,
        array $identity,
        array $identifiers,
        string $mode,
        ?string $endOn,
    ): array {
        self::requireMode($mode);
        if ($a1->variant !== PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_completion_variant_unsupported',
                'Dohlášení údajů (REGZEC A3) aplikace připraví jen u běžných '
                    . 'pracovních vztahů (varianta OST). U druhu činnosti „'
                    . (string) ($a1->employment['activity_code'] ?? '')
                    . '" se dohlašovaná pole liší — podejte ho přes portál ČSSZ.',
            );
        }
        $full = $mode === self::FULL;
        $employmentKeys = $full
            ? ['actual_start_on', 'contract_start_on', ...self::COMPLETION_EMPLOYMENT, 'expected_workplaces']
            : self::COMPLETION_EMPLOYMENT;
        $employment = self::pick($a1->employment, $employmentKeys);
        if ($endOn !== null) {
            $employment['end_on'] = $endOn;
        }
        $taxResidency = $a1->taxResidency ?? [];
        $delta = [
            'birth_number' => self::text($identifiers['birth_number'] ?? null)
                ?? self::text($identifiers['ecp'] ?? null),
            'employment' => $employment,
            'facts' => self::pick(
                $a1->facts ?? [],
                $full
                    ? ['highest_education_code', 'disability_card', 'health_restrictions']
                    : ['highest_education_code'],
            ),
            'tax_residency' => ($taxResidency['country_code'] ?? null) === null
                ? null
                : [
                    'country_code' => $taxResidency['country_code'],
                    // Rezidence platí od nástupu; tak to posílají i cizí programy.
                    'changed_on' => (string) $a1->employment['actual_start_on'],
                ] + ($full
                    ? self::pick($taxResidency, ['identifier_type', 'identifier', 'residence_address'])
                    : []),
        ];
        if ($full) {
            $delta += [
                'identity' => self::pick($identity, [
                    'last_name', 'first_name', 'title_prefix', 'title_suffix',
                    'previous_surnames', 'birth_date',
                    'sex', 'citizenship_country_code',
                ]),
                'permanent_address' => self::clean($a1->permanentAddress),
                'czech_residence_address' => $a1->czechResidenceAddress === null
                    ? null
                    : self::clean($a1->czechResidenceAddress),
                'contact_address' => $a1->contactAddress === null
                    ? null
                    : self::clean($a1->contactAddress),
                'proof_identity' => $a1->proofIdentity === null
                    ? null
                    : self::clean($a1->proofIdentity),
                'pension' => $a1->pension === null ? null : self::clean($a1->pension),
                'health_insurance_code' => $a1->healthInsuranceCode,
                'foreign_worker' => $a1->foreignWorker === null
                    ? null
                    : self::clean($a1->foreignWorker),
                'foreign_legislation' => $a1->foreignLegislation === null
                    ? null
                    : self::clean($a1->foreignLegislation),
            ];
        }

        return array_filter(
            $delta,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_array($value)) {
                $value = array_is_list($value) ? $value : self::clean($value);
                if ($value === [] && $key !== 'health_restrictions') {
                    continue;
                }
            }
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function clean(array $source): array
    {
        return array_filter(
            $source,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
