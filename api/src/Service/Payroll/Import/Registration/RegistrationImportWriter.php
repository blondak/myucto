<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Zápis jedné naplánované věty ({@see RegistrationImportPlanner}) do evidence.
 *
 * Každá skupina jde TOUTÉŽ cestou jako karta, na které se údaj běžně edituje
 * (stejně jako {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1MasterDataWriter}):
 * založení osoby, vztah, podmínky, identita, karta osoby, zákonná evidence,
 * identifikátory ČSSZ. Vlastní SQL do cizích tabulek tu není.
 *
 * Věta je jedna transakce. Hlavní krok (založení, ukončení) musí projít, jinak
 * se nezapíše nic. Doplňkové skupiny (adresa, podmínky, pojišťovna,
 * identifikátory…) mají vlastní savepoint: když jedna neprojde kontrolou karty,
 * zbytek se zapíše a důvod se vrátí ve zprávě. Opakovaný import pak dopíše
 * jen to, co chybí.
 */
final class RegistrationImportWriter
{
    private const COVERAGE_FIELDS = [
        'jurisdiction',
        'foreign_country_code',
        'jurisdiction_evidence_reference',
        'insurer_status',
        'insurer_code',
        'insurer_evidence_reference',
        'health_evidence_document_id',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly LicenseCapacityGate $license,
        private readonly PayrollPersonCreateService $people,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly RegistrationImportLookup $lookup,
    ) {}

    /**
     * @param array<string,mixed> $plan
     * @return array{status:string,message:?string,employee_id:?int,employment_id:?int,operations:list<string>}
     */
    public function apply(
        int $supplierId,
        string $environment,
        array $plan,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        /** @var RegistrationRecord $record */
        $record = $plan['_record'];
        /** @var array<string,mixed> $steps */
        $steps = $plan['_steps'];
        $employeeId = $plan['_employee_id'];
        $employmentId = $plan['_employment_id'];
        $operations = [];
        $notes = [];
        $officeId ??= $this->lookup->defaultOfficeId($supplierId);

        $this->atomic('registration_import_record', function () use (
            $supplierId,
            $environment,
            $plan,
            $record,
            $steps,
            $officeId,
            $userId,
            $ip,
            $userAgent,
            &$employeeId,
            &$employmentId,
            &$operations,
            &$notes,
        ): void {
            if (is_array($steps['create_person'])) {
                $input = $steps['create_person'];
                $workload = $input['workload'] ?? null;
                unset($input['workload']);
                if (is_array($workload)) {
                    $input['weekly_hours'] = $workload['weekly_hours'];
                }
                $person = $this->license->mutatePayrollEmployees(
                    fn (): array => $this->people->create(
                        $supplierId,
                        $input + ['office_id' => $officeId],
                        $userId,
                        $ip,
                        $userAgent,
                    ),
                );
                $employeeId = (int) $person['id'];
                $employmentId = $this->lookup->employments($supplierId, $employeeId)[0]['id']
                    ?? throw new \LogicException('Nově založená osoba nemá pracovní vztah.');
                $operations[] = 'person_created';
            } elseif (is_array($steps['create_employment'])) {
                $employmentId = $this->createEmployment(
                    $supplierId,
                    (int) $employeeId,
                    $steps['create_employment'],
                    $officeId,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $operations[] = 'employment_created';
            }
            $employeeId = $employeeId === null ? null : (int) $employeeId;
            $employmentId = $employmentId === null ? null : (int) $employmentId;
            $decisive = $record->decisiveDate() ?? date('Y-m-d');

            if ($steps['terms'] !== [] && $employmentId !== null) {
                $this->optional('Podmínky vztahu', $notes, $operations, 'terms', fn () => $this->writeTerms(
                    $supplierId,
                    $employmentId,
                    $steps['terms'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if ($steps['identity_facts'] !== [] && $employeeId !== null) {
                $this->optional('Údaje o narození a občanství', $notes, $operations, 'identity_facts', fn () => $this->writeIdentityFacts(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['identity_facts'],
                ));
            }
            if (($steps['addresses'] !== [] || $steps['birth_surname'] !== null) && $employeeId !== null) {
                $this->optional('Adresa a rodné příjmení', $notes, $operations, 'address', fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['addresses'],
                    $steps['birth_surname'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['health_insurer']) && $employeeId !== null) {
                $this->optional('Zdravotní pojišťovna', $notes, $operations, 'health_insurer', fn () => $this->writeHealthInsurer(
                    $supplierId,
                    $employeeId,
                    (string) $steps['health_insurer']['code'],
                    (string) $steps['health_insurer']['on'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_string($steps['activate_on']) && $employmentId !== null) {
                $this->optional('Nástup', $notes, $operations, 'activated', fn () => $this->transition(
                    $supplierId,
                    $employmentId,
                    'active',
                    $steps['activate_on'],
                    match (true) {
                        $record->isCsszExport() => 'Nástup podle exportu zaměstnanců ČSSZ a měsíčního hlášení v téže dávce.',
                        $record->isJmhzDerived() => 'Nástup podle importovaných měsíčních hlášení JMHZ.',
                        default => 'Nástup podle importované registrace ČSSZ.',
                    },
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['terminate']) && $employmentId !== null) {
                $target = (string) $steps['terminate']['target'];
                $this->transition(
                    $supplierId,
                    $employmentId,
                    $target,
                    (string) $steps['terminate']['on'],
                    $target === 'ended'
                        ? 'Skončení podle importovaného odhlášení ČSSZ.'
                        : 'Nenastoupení podle importované registrace ČSSZ.',
                    $userId,
                    $ip,
                    $userAgent,
                );
                $operations[] = $target === 'ended' ? 'terminated' : 'no_show';
            }
            $identifiers = $steps['identifiers'];
            if (($identifiers['person'] !== null || $identifiers['employment'] !== null) && $employmentId !== null) {
                $this->optional('Identifikátory ČSSZ', $notes, $operations, 'identifiers', fn () => $this->writeIdentifiers(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $record,
                    (string) $plan['_file_sha256'],
                    $identifiers['person'],
                    $identifiers['employment'],
                    $userId,
                ));
            }
        });

        return [
            'status' => 'applied',
            'message' => $notes === [] ? null : 'Zapsáno, ale část údajů se zapsat nepodařila: ' . implode(' ', $notes),
            'employee_id' => $employeeId === null ? null : (int) $employeeId,
            'employment_id' => $employmentId === null ? null : (int) $employmentId,
            'operations' => $operations,
        ];
    }

    /** @param array{relation_type:string,planned_start_on:string,workload?:?array{workload_basis_points:int,weekly_hours:string}} $input */
    private function createEmployment(
        int $supplierId,
        int $employeeId,
        array $input,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): int {
        $hasPrimary = false;
        foreach ($this->lookup->employments($supplierId, $employeeId) as $row) {
            if ($row['is_primary'] && in_array($row['status'], ['planned', 'preregistered', 'active', 'suspended'], true)) {
                $hasPrimary = true;
            }
        }
        [$activityCode, $detailCode] = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($input['relation_type']);
        $created = $this->employments->create(
            $supplierId,
            $employeeId,
            $this->employmentValidator->create([
                'code' => '',
                'relation_type' => $input['relation_type'],
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => $officeId,
                    'effective_from' => $input['planned_start_on'],
                    'planned_start_on' => $input['planned_start_on'],
                    'contract_signed_on' => null,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => null,
                    'weekly_hours' => $input['workload']['weekly_hours'] ?? '40.00',
                    'workload_basis_points' => $input['workload']['workload_basis_points'] ?? 10_000,
                    'work_place' => null,
                    'regular_workplace' => null,
                    'jmhz_workplace_municipality_code' => null,
                    'jmhz_workplace_country_code' => null,
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => null,
                    'activity_code' => $activityCode,
                    'jmhz_relationship_detail_code' => $detailCode,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => !$hasPrimary,
                    'change_reason' => 'Založeno importem registrace ČSSZ.',
                ],
            ]),
            $userId,
            $ip,
            $userAgent,
        );

        return (int) $created['id'];
    }

    /** @param array<string,string> $changes */
    private function writeTerms(
        int $supplierId,
        int $employmentId,
        array $changes,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek, do které by šlo zapsat.');
        $body = self::termsBody($current);
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        if (($body['jmhz_workplace_municipality_code'] ?? null) === null) {
            $body['jmhz_workplace_country_code'] = null;
        }
        $body['effective_from'] = (string) $current['effective_from'];
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');

        $this->employments->correctTerms(
            $supplierId,
            $employmentId,
            $this->employmentValidator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            ),
            (int) $row['row_version'],
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * Uložená verze podmínek jako vstup validátoru — `correctTerms()` bere
     * cílový stav, vynechané pole by se vyprázdnilo. Tvar je shodný
     * s PayrollRegistrationA1MasterDataWriter::termsBody(). Veřejné, aby ho
     * import měsíčních hlášení volal místo vlastní kopie.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function termsBody(
        array $current,
        string $changeReason = 'Údaje z importované registrace ČSSZ.',
    ): array {
        $keys = [
            'office_id', 'contract_signed_on', 'planned_start_on', 'actual_start_on',
            'fixed_term_end_on', 'weekly_hours', 'workload_basis_points', 'work_place',
            'regular_workplace', 'jmhz_workplace_municipality_code', 'jmhz_workplace_country_code',
            'jmhz_external_codebook_overlay_key', 'jmhz_external_codebook_manifest_sha256',
            'jmhz_apz_contribution_status', 'jmhz_apz_instrument_code',
            'jmhz_functional_benefits_status', 'jmhz_temporary_assignment_status',
            'jmhz_orchard_discount_eligible', 'jmhz_specific_legal_fact_applies',
            'jmhz_ozp_employment_support_applies', 'jmhz_deep_mining_work_applies',
            'cz_isco_code', 'activity_code', 'jmhz_relationship_detail_code',
            'social_insurance_participation', 'health_insurance_participation', 'tax_regime',
            'other_withholding_eligibility', 'foreign_legislation_country_code',
            'a1_certificate_until', 'risky_work', 'social_employer_rate_category',
            'social_employer_rate_category_evidence', 'social_part_time_discount_reason',
            'social_part_time_discount_evidence', 'social_part_time_discount_notified_on',
            'is_primary',
        ];
        $body = [];
        foreach ($keys as $key) {
            $body[$key] = $current[$key] ?? null;
        }
        $override = $current['leave_entitlement_weeks_override'] ?? null;
        $body['leave_entitlement_weeks_override'] = $override === null ? null : (int) $override;
        $probable = $current['probable_hourly_earning_minor'] ?? null;
        $body['probable_hourly_earning_minor'] = $probable === null ? null : (int) $probable;
        $body['probable_earning_rationale'] = $current['probable_earning_rationale'] ?? null;
        $body['change_reason'] = $changeReason;

        return $body;
    }

    /** @param array<string,string> $facts */
    private function writeIdentityFacts(int $supplierId, int $employeeId, string $onDate, array $facts): void
    {
        $identity = $this->registrations->identityAt($supplierId, $employeeId, min($onDate, date('Y-m-d')))
            ?? $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'))
            ?? throw new \DomainException(
                'Osoba nemá evidovanou identitu, do které by šly údaje o narození zapsat. Doplňte je na kartě osoby.',
            );
        $merged = [];
        foreach ([
            'title_prefix', 'title_suffix', 'birth_date', 'birth_place',
            'birth_country_code', 'citizenship_country_code', 'sex',
        ] as $field) {
            $value = $facts[$field] ?? $identity[$field] ?? null;
            $merged[$field] = $value === null ? null : (string) $value;
        }
        $this->identities->saveIdentityFacts(
            $supplierId,
            $employeeId,
            (int) $identity['id'],
            (int) $identity['row_version'],
            $merged,
        );
    }

    /**
     * Adresy a rodné příjmení jdou jedním uložením karty osoby — je to jeden
     * optimistický zámek. Adresa se historizuje stejně jako v zápisu z A1:
     * verze pokrývající rozhodný den se uzavře a od něj platí nová.
     *
     * @param array<string,array<string,string>> $addresses
     */
    private function writePersonCard(
        int $supplierId,
        int $employeeId,
        string $onDate,
        array $addresses,
        ?string $birthSurname,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $current = $this->profiles->get($supplierId, $employeeId)
            ?? throw new \DomainException('Osobní karta zaměstnance nebyla nalezena.');
        $rows = [];
        foreach ($addresses as $type => $address) {
            $rows = array_merge($rows, $this->addressRows(
                $current['addresses'],
                $type,
                $address['on'] ?? $onDate,
                $address,
            ));
        }
        $identity = [];
        if ($birthSurname !== null) {
            $version = $this->covering($current['identity_history'], min($onDate, date('Y-m-d')), null)
                ?? ($current['identity_history'][0] ?? null);
            if ($version !== null) {
                $identity[] = [
                    'id' => $version['id'],
                    'full_name' => $version['full_name'],
                    'first_name' => $version['first_name'],
                    'last_name' => $version['last_name'],
                    'birth_surname' => $birthSurname,
                    'effective_from' => $version['effective_from'],
                    'effective_to' => $version['effective_to'],
                ];
            }
        }
        if ($rows === [] && $identity === []) {
            return;
        }

        $payload = [
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? min($onDate, date('Y-m-d')),
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $rows,
            'contacts' => [],
            'identifiers' => [],
            'accounts' => [],
        ];
        $this->profiles->save(
            $supplierId,
            $employeeId,
            $this->profileValidator->validate($payload),
            $current['row_version'],
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param list<array<string,mixed>> $stored
     * @param array<string,string> $address
     * @return list<array<string,mixed>>
     */
    private function addressRows(array $stored, string $type, string $onDate, array $address): array
    {
        $values = [
            'address_type' => $type,
            'street_line' => $address['street_line'],
            'city' => $address['city'],
            'postal_code' => $address['postal_code'],
            'country_code' => $address['country_code'],
        ];
        $covering = $this->covering($stored, $onDate, $type);
        if ($covering === null) {
            foreach ($stored as $row) {
                if (($row['address_type'] ?? null) === $type && (string) $row['effective_from'] > $onDate) {
                    throw new \DomainException(
                        'Osoba má evidovanou adresu platnou až od pozdějšího data, novou verzi před ní import '
                        . 'nezaloží. Opravte adresu na kartě osoby.',
                    );
                }
            }

            return [$values + ['id' => null, 'effective_from' => $onDate, 'effective_to' => null]];
        }
        if ((string) $covering['effective_from'] === $onDate) {
            return [$values + [
                'id' => $covering['id'],
                'effective_from' => $onDate,
                'effective_to' => $covering['effective_to'],
            ]];
        }

        return [
            [
                'id' => $covering['id'],
                'address_type' => $type,
                'effective_from' => $covering['effective_from'],
                'effective_to' => $this->previousDay($onDate),
            ],
            $values + ['id' => null, 'effective_from' => $onDate, 'effective_to' => $covering['effective_to']],
        ];
    }

    /**
     * Změna pojišťovny je nová verze zákonné evidence od prvního dne měsíce
     * (evidence se vede po celých měsících). Starší verze se uzavře koncem
     * předchozího měsíce, takže historie odvodů zůstává, jak byla.
     */
    private function writeHealthInsurer(
        int $supplierId,
        int $employeeId,
        string $insurerCode,
        string $onDate,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $monthStart = substr($onDate, 0, 7) . '-01';
        $view = $this->statutory->editorView($supplierId, $employeeId, $onDate)
            ?? throw new \DomainException('Zákonná evidence zaměstnance nebyla nalezena.');
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        $rows = $sections['health_coverages'];
        $covering = $this->covering($rows, $monthStart, null);
        if ($covering === null) {
            if ($rows !== []) {
                throw new \DomainException(
                    "Zdravotní pojištění k {$monthStart} v evidenci vedené není a navazuje na jiné období. "
                    . 'Zapište pojišťovnu ručně v zákonné evidenci osoby.',
                );
            }
            $rows[] = [
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'jurisdiction_evidence_reference' => null,
                'insurer_status' => 'verified',
                'insurer_code' => $insurerCode,
                'insurer_evidence_reference' => null,
                'health_evidence_document_id' => null,
                'effective_from' => $monthStart,
                'effective_to' => null,
                'evidence_note' => null,
            ];
        } elseif ((string) $covering['effective_from'] >= $monthStart) {
            foreach ($rows as $index => $row) {
                if ((int) $row['id'] === (int) $covering['id']) {
                    $rows[$index]['insurer_code'] = $insurerCode;
                    $rows[$index]['insurer_status'] = 'verified';
                }
            }
        } else {
            $next = ['id' => null];
            foreach (self::COVERAGE_FIELDS as $field) {
                $next[$field] = $covering[$field] ?? null;
            }
            $next['insurer_code'] = $insurerCode;
            $next['insurer_status'] = 'verified';
            $next['insurer_evidence_reference'] = null;
            $next['health_evidence_document_id'] = null;
            $next['effective_from'] = $monthStart;
            $next['effective_to'] = $covering['effective_to'];
            $next['evidence_note'] = null;
            foreach ($rows as $index => $row) {
                if ((int) $row['id'] === (int) $covering['id']) {
                    $rows[$index]['effective_to'] = $this->previousDay($monthStart);
                }
            }
            $rows[] = $next;
        }
        $sections['health_coverages'] = $rows;

        $this->statutory->save(
            $supplierId,
            $employeeId,
            ['sections' => $sections],
            $onDate,
            $userId,
            $ip,
            $userAgent,
        );
    }

    private function transition(
        int $supplierId,
        int $employmentId,
        string $target,
        string $effectiveOn,
        string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $this->employments->transition(
            $supplierId,
            $employmentId,
            $target,
            (int) $row['row_version'],
            $effectiveOn,
            $note,
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * Identifikátory platí od nástupu, nebo od data účinnosti věty, pokud je
     * pozdější. Den vyhotovení věty se nepoužívá: OIČ i ID PPV označují vztah
     * od jeho začátku a měsíce mezi nástupem a vyhotovením by jinak zůstaly
     * v měsíčním hlášení bez identifikátorů.
     */
    private function writeIdentifiers(
        int $supplierId,
        string $environment,
        int $employmentId,
        RegistrationRecord $record,
        string $fileSha256,
        ?string $personIdentifier,
        ?string $employmentIdentifier,
        ?int $userId,
    ): void {
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $validFrom = (string) ($row['start_date'] ?? $record->decisiveDate() ?? date('Y-m-d'));
        if ($record->effectiveOn !== null && $record->effectiveOn > $validFrom) {
            $validFrom = $record->effectiveOn;
        }
        if ($row['end_date'] !== null && $validFrom > $row['end_date']) {
            $validFrom = (string) $row['end_date'];
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            $environment,
            $personIdentifier,
            $employmentIdentifier,
            $validFrom,
            'jmhz-registration-import:' . $fileSha256 . ':' . $record->sequence,
            true,
            $userId,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function covering(array $rows, string $onDate, ?string $addressType): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($addressType !== null && ($row['address_type'] ?? null) !== $addressType) {
                continue;
            }
            $from = (string) $row['effective_from'];
            $to = ($row['effective_to'] ?? null) === null ? null : (string) $row['effective_to'];
            if ($from > $onDate || ($to !== null && $to < $onDate)) {
                continue;
            }
            if ($best === null || $from > (string) $best['effective_from']) {
                $best = $row;
            }
        }

        return $best;
    }

    private function previousDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param list<string> $notes
     * @param list<string> $operations
     * @param callable():void $work
     */
    private function optional(string $label, array &$notes, array &$operations, string $operation, callable $work): void
    {
        try {
            $this->atomic('registration_import_step', $work);
            $operations[] = $operation;
        } catch (\Exception $e) {
            $notes[] = "{$label}: {$e->getMessage()}";
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function atomic(string $name, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $name);
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            }
            throw $e;
        }
    }
}
