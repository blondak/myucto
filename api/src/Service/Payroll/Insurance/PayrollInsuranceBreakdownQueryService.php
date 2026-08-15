<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Insurance;

use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;

/**
 * MZ-10-W07 / MZ-11-W07 — „jak vzniklo sociální a zdravotní pojistné".
 *
 * Daň a čistá mzda svůj rozklad mají, pojistné do teď ne: účetní viděl jen
 * výslednou částku a neměl jak ověřit, odkud se vzala. Bez toho za výpočet
 * nemůže převzít odpovědnost a při kontrole nemá čím argumentovat.
 *
 * ODKUD DATA: výhradně z `payroll_statutory_results` (+ osoby a vztahy), tedy
 * z NEMĚNNÉHO výsledku, který uložil `PayrollRunStatutoryResultPersister` v témže
 * běhu, jaký vydal výslednou částku. Žádný přepočet, žádné čtení aktuální sady
 * pravidel: kdyby vysvětlení vznikalo znovu, dřív nebo později se s uloženým
 * výsledkem rozejde a začne lhát — a to je horší než žádné vysvětlení.
 *
 * Run snapshot revize (`payroll_run_revisions.result_snapshot_json`) tutéž osobu
 * nese taky, ale je to odvozená kopie pro obrazovky. Zdrojem pravdy je výsledková
 * tabulka — má vlastní hash, sadu pravidel i stav a je to ta, na kterou se odkazují
 * odvody a platby.
 *
 * FAIL-CLOSED: chybí-li revizi výsledková sada (spočtena starší verzí modulu),
 * vrací se `available:false` a důvod větou. Prázdná karta ani dopočet odhadem ne.
 * Zároveň platí kontrolní součet — mezikroky MUSÍ dát tutéž částku jako uložený
 * výsledek, jinak rozklad neprojde vůbec.
 */
final class PayrollInsuranceBreakdownQueryService
{
    /**
     * Důvody, proč rozklad není k dispozici. Je to smluvní číselník s klientem
     * (`web/src/api/payrollInsurance.ts`) — každý důvod má na obrazovce vlastní
     * větu, takže přidání hodnoty tady bez věty tam je tichá regrese.
     *
     * @var list<string>
     */
    public const UNAVAILABLE_REASONS = [
        'result_set_missing',
        'schema_unsupported',
        'person_missing',
    ];

    private const SOCIAL = 'social_insurance';
    private const HEALTH = 'health_insurance';

    public function __construct(
        private readonly PayrollRunRepository $runs,
        private readonly PayrollStatutoryResultRepository $results,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function breakdown(int $supplierId, int $revisionId, int $employeeId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize i osoba musí mít kladná ID.',
            );
        }
        $revision = $this->runs->revision($supplierId, $revisionId);
        if ($revision === null) {
            throw new \OutOfBoundsException('Mzdová revize nebyla nalezena.');
        }
        $input = self::object($revision['input_snapshot'] ?? null, 'input_snapshot');
        $employee = $this->frozenEmployee($input, $employeeId);

        return [
            'revision' => [
                'id' => (int) $revision['id'],
                'run_id' => (int) $revision['run_id'],
                'revision_no' => (int) $revision['revision_no'],
                'revision_kind' => (string) ($revision['revision_kind'] ?? ''),
                'status' => (string) ($revision['status'] ?? ''),
            ],
            'person' => [
                'employee_id' => $employeeId,
                'full_name' => (string) ($employee['full_name'] ?? ''),
            ],
            'social' => $this->social($supplierId, $revisionId, $employeeId),
            'health' => $this->health($supplierId, $revisionId, $employeeId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function social(int $supplierId, int $revisionId, int $employeeId): array
    {
        $set = $this->results->find($supplierId, $revisionId, self::SOCIAL);
        if ($set === null) {
            return self::unavailable('result_set_missing');
        }
        if ((string) ($set['schema_version'] ?? '') !== 'payroll-social-result.v1') {
            return self::unavailable('schema_unsupported');
        }
        $person = $this->personRow($set, $employeeId);
        if ($person === null) {
            return self::unavailable('person_missing');
        }
        $result = self::object($person['result_snapshot'] ?? null, 'social.person.result_snapshot');
        $month = self::object($set['result_snapshot'] ?? null, 'social.result_snapshot');

        $participating = self::nonNegativeInt($result, 'participating_assessment_base_minor_units');
        $capped = self::nonNegativeInt($result, 'capped_assessment_base_minor_units');
        $contributionStep = self::step($result['contribution_step'] ?? null, 'social.contribution_step');
        $discountStep = self::step($result['discount_step'] ?? null, 'social.discount_step');
        $beforeDiscount = self::optionalNonNegativeInt(
            $result,
            'employee_contribution_before_discount_minor_units',
        );
        $discount = self::optionalNonNegativeInt($result, 'working_pensioner_discount_minor_units');
        $contribution = self::optionalNonNegativeInt($result, 'employee_contribution_minor_units');
        $status = (string) ($person['result_status'] ?? 'manual_review');

        if ($status === 'calculated') {
            $this->assertEmployeeSocialReconciles(
                $capped,
                $contributionStep,
                $beforeDiscount,
                $discountStep,
                $discount,
                $contribution,
            );
        }

        $employerStep = self::step(
            $month['employer_contribution_step'] ?? null,
            'social.employer_contribution_step',
        );

        return [
            'available' => true,
            'unavailable_reason' => null,
            'status' => $status,
            'calculation_date' => (string) ($month['calculation_date'] ?? ''),
            'ruleset_id' => (string) ($set['ruleset_id'] ?? ''),
            'ruleset_hash' => (string) ($set['ruleset_hash'] ?? ''),
            'jurisdiction' => (string) ($result['jurisdiction'] ?? ''),
            'jurisdiction_evidence_reference' => self::nullableString(
                $result,
                'jurisdiction_evidence_reference',
            ),
            'working_pensioner_discount_evidence_reference' => self::nullableString(
                $result,
                'working_pensioner_discount_evidence_reference',
            ),
            'assessment_base' => [
                'participating_minor' => $participating,
                'capped_minor' => $capped,
                'year_to_date_before_month_minor' => self::nonNegativeInt(
                    $result,
                    'year_to_date_assessment_base_before_month_minor_units',
                ),
                'annual_maximum_reduction_minor' => max(0, $participating - $capped),
                'annual_maximum_applied' => $status === 'calculated' && $capped < $participating,
            ],
            'employee' => [
                'contribution_step' => $contributionStep,
                'before_discount_minor' => $beforeDiscount,
                'discount_step' => $discountStep,
                'working_pensioner_discount_minor' => $discount,
                'contribution_minor' => $contribution,
            ],
            /*
             * Pojistné zaměstnavatele NENÍ osobní veličina: počítá se jednou
             * ze součtu vyměřovacích základů všech zaměstnanců a zaokrouhluje
             * se až na tomto součtu. Rozpad na osobu by byl vymyšlený, proto se
             * vydává tak, jak vznikl — za celou firmu a měsíc.
             */
            'employer' => [
                'scope' => 'company_month',
                'contribution_step' => $employerStep,
                'assessment_base_minor' => self::optionalNonNegativeInt(
                    $month,
                    'capped_assessment_base_minor_units',
                ),
                'contribution_before_discount_minor' => self::optionalNonNegativeInt(
                    $month,
                    'employer_contribution_before_discount_minor_units',
                ),
                'part_time_discount_base_minor' => self::optionalNonNegativeInt(
                    $month,
                    'part_time_discount_assessment_base_minor_units',
                ),
                'part_time_discount_step' => self::step(
                    $month['part_time_discount_step'] ?? null,
                    'social.part_time_discount_step',
                ),
                'part_time_discount_minor' => self::optionalNonNegativeInt(
                    $month,
                    'part_time_discount_minor_units',
                ),
                'contribution_minor' => self::optionalNonNegativeInt(
                    $month,
                    'employer_contribution_minor_units',
                ),
            ],
            'relationships' => $this->socialRelationships($person),
            'issues' => self::strings($result['issues'] ?? [], 'social.issues'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function health(int $supplierId, int $revisionId, int $employeeId): array
    {
        $set = $this->results->find($supplierId, $revisionId, self::HEALTH);
        if ($set === null) {
            return self::unavailable('result_set_missing');
        }
        if ((string) ($set['schema_version'] ?? '') !== 'payroll-health-result.v1') {
            return self::unavailable('schema_unsupported');
        }
        $person = $this->personRow($set, $employeeId);
        if ($person === null) {
            return self::unavailable('person_missing');
        }
        $result = self::object($person['result_snapshot'] ?? null, 'health.person.result_snapshot');
        $month = self::object($set['result_snapshot'] ?? null, 'health.result_snapshot');

        $assessmentBase = self::nonNegativeInt($result, 'assessment_base_minor_units');
        $effectiveMinimum = self::nonNegativeInt($result, 'effective_minimum_minor_units');
        $standardStep = self::step(
            $result['standard_contribution_step'] ?? null,
            'health.standard_contribution_step',
        );
        $topUpStep = self::step($result['minimum_top_up_step'] ?? null, 'health.minimum_top_up_step');
        $standard = self::optionalNonNegativeInt($result, 'standard_contribution_minor_units');
        $employeeStandard = self::optionalNonNegativeInt(
            $result,
            'employee_standard_contribution_minor_units',
        );
        $employerStandard = self::optionalNonNegativeInt(
            $result,
            'employer_standard_contribution_minor_units',
        );
        $employeeTopUp = self::optionalNonNegativeInt($result, 'employee_minimum_top_up_minor_units');
        $employerTopUp = self::optionalNonNegativeInt($result, 'employer_minimum_top_up_minor_units');
        $employee = self::optionalNonNegativeInt($result, 'employee_contribution_minor_units');
        $employer = self::optionalNonNegativeInt($result, 'employer_contribution_minor_units');
        $total = self::optionalNonNegativeInt($result, 'total_contribution_minor_units');
        $status = (string) ($person['result_status'] ?? 'manual_review');

        if ($status === 'calculated') {
            $this->assertHealthReconciles(
                $standardStep,
                $standard,
                $employeeStandard,
                $employerStandard,
                $topUpStep,
                $employeeTopUp,
                $employerTopUp,
                $employee,
                $employer,
                $total,
            );
        }

        $insurerCode = self::nullableString($result, 'insurer_code');

        return [
            'available' => true,
            'unavailable_reason' => null,
            'status' => $status,
            'calculation_date' => (string) ($month['calculation_date'] ?? ''),
            'ruleset_id' => (string) ($set['ruleset_id'] ?? ''),
            'ruleset_hash' => (string) ($set['ruleset_hash'] ?? ''),
            'jurisdiction' => (string) ($result['jurisdiction'] ?? ''),
            'jurisdiction_evidence_reference' => self::nullableString(
                $result,
                'jurisdiction_evidence_reference',
            ),
            'insurer' => [
                'status' => (string) ($result['insurer_status'] ?? ''),
                'code' => $insurerCode,
                'evidence_reference' => self::nullableString($result, 'insurer_evidence_reference'),
            ],
            'assessment_base' => [
                'this_employer_minor' => $assessmentBase,
                'other_employers_minor' => self::nonNegativeInt(
                    $result,
                    'other_employer_assessment_base_minor_units',
                ),
                'combined_minor' => self::nonNegativeInt(
                    $result,
                    'combined_assessment_base_minor_units',
                ),
            ],
            'minimum' => [
                'statutory_monthly_minor' => self::nonNegativeInt(
                    $result,
                    'statutory_monthly_minimum_minor_units',
                ),
                'effective_minor' => $effectiveMinimum,
                'employment_calendar_days' => self::nonNegativeInt(
                    $result,
                    'employment_calendar_days',
                ),
                'excluded_calendar_days' => self::nonNegativeInt(
                    $result,
                    'minimum_excluded_calendar_days',
                ),
                'applicable_calendar_days' => self::nonNegativeInt(
                    $result,
                    'minimum_applicable_calendar_days',
                ),
                /*
                 * Nesmí se odvozovat jen z existence mezikroku: revize bez
                 * uložených kroků by dopočet ZATAJILA, ačkoli ho v částkách nese.
                 * Základ dopočtu bez kroku ale neznáme — vrací se null a obrazovka
                 * o něm mlčí, místo aby ukázala nulu jako by žádný nebyl.
                 */
                'top_up_applied' => $topUpStep !== null
                    || (($employeeTopUp ?? 0) + ($employerTopUp ?? 0)) > 0,
                'top_up_base_minor' => $topUpStep['input_minor_units'] ?? null,
                'top_up_responsibility' => (string) ($result['top_up_responsibility'] ?? ''),
                'top_up_employer_selection' => (string) ($result['top_up_employer_selection'] ?? ''),
                'top_up_responsibility_evidence_reference' => self::nullableString(
                    $result,
                    'top_up_responsibility_evidence_reference',
                ),
                'selected_top_up_employer_evidence_reference' => self::nullableString(
                    $result,
                    'selected_top_up_employer_evidence_reference',
                ),
                'reduction_evidence' => self::rows(
                    $result['minimum_reduction_evidence'] ?? [],
                    'health.minimum_reduction_evidence',
                ),
                'ppz_counted' => (bool) ($result['ppz_counted'] ?? false),
            ],
            'contribution' => [
                /*
                 * `not_recorded` = revize spočtená dřív, než se sazba a způsob
                 * zaokrouhlení začaly ukládat. Dopočítat je z dnešní sady pravidel
                 * nelze — popisovaly by jiný výpočet než ten, který dal částku.
                 *
                 * `not_applicable` = pojistné nevzniklo (bez účasti, cizí režim).
                 * Krok chybí právem a tvrdit „neuložilo se" by byl planý poplach.
                 */
                'rate_source' => $this->rateSource($standardStep, $standard, $status),
                'standard_step' => $standardStep,
                'standard_minor' => $standard,
                'employee_standard_minor' => $employeeStandard,
                'employer_standard_minor' => $employerStandard,
                'top_up_step' => $topUpStep,
                'employee_top_up_minor' => $employeeTopUp,
                'employer_top_up_minor' => $employerTopUp,
                'employee_minor' => $employee,
                'employer_minor' => $employer,
                'total_minor' => $total,
            ],
            'relationships' => $this->healthRelationships($person),
            'other_employer_evidence' => self::rows(
                $result['other_employer_evidence'] ?? [],
                'health.other_employer_evidence',
            ),
            /*
             * Rozpad podle pojišťoven je firemní veličina — právě podle něj se
             * odvádí a právě ten musí účetní odsouhlasit s přehledy. Osoba do něj
             * patří jedním kódem (`is_person_insurer`), víc pojišťoven u jedné
             * osoby v jednom měsíci model nezná.
             */
            'insurer_liabilities' => $this->insurerLiabilities($month, $insurerCode),
            'issues' => self::strings($result['issues'] ?? [], 'health.issues'),
        ];
    }

    /**
     * Rozlišuje „krok se neuložil" od „krok nevznikl". Bez toho by osoba bez
     * účasti na pojištění hlásila chybějící sazbu, ačkoli žádná neexistuje —
     * planý poplach, který účetní naučí varování ignorovat.
     *
     * @param array<string,mixed>|null $standardStep
     */
    private function rateSource(?array $standardStep, ?int $standard, string $status): string
    {
        if ($standardStep !== null) {
            return 'persisted';
        }
        if ($status !== 'calculated') {
            return 'persisted';
        }

        return ($standard ?? 0) === 0 ? 'not_applicable' : 'not_recorded';
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array<string,mixed>>
     */
    private function socialRelationships(array $person): array
    {
        $result = [];
        foreach (self::rows($person['relationships'] ?? [], 'social.relationships') as $row) {
            $snapshot = self::object(
                $row['result_snapshot'] ?? null,
                'social.relationship.result_snapshot',
            );
            $participation = self::object(
                $snapshot['participation'] ?? null,
                'social.relationship.participation',
            );
            $result[] = [
                'employment_id' => self::positiveInt($row, 'employment_id'),
                'relationship_reference' => (string) ($snapshot['relationship_id'] ?? ''),
                'kind' => (string) ($snapshot['kind'] ?? ''),
                'result_status' => (string) ($row['result_status'] ?? ''),
                'participation_status' => (string) ($participation['status'] ?? ''),
                'participation_income_minor' => self::nonNegativeInt(
                    $participation,
                    'participation_income_minor_units',
                ),
                'group_income_minor' => self::nonNegativeInt(
                    $participation,
                    'group_income_minor_units',
                ),
                'threshold_minor' => self::optionalNonNegativeInt(
                    $participation,
                    'threshold_minor_units',
                ),
                'reason_codes' => self::strings(
                    $participation['reason_codes'] ?? [],
                    'social.relationship.reason_codes',
                ),
                'assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'assessment_base_minor_units',
                ),
                'capped_assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'capped_assessment_base_minor_units',
                ),
                'included_participation_components' => self::strings(
                    $snapshot['included_participation_components'] ?? [],
                    'social.relationship.included_participation_components',
                ),
                'excluded_participation_components' => self::strings(
                    $snapshot['excluded_participation_components'] ?? [],
                    'social.relationship.excluded_participation_components',
                ),
                'included_assessment_base_components' => self::strings(
                    $snapshot['included_assessment_base_components'] ?? [],
                    'social.relationship.included_assessment_base_components',
                ),
                'excluded_assessment_base_components' => self::strings(
                    $snapshot['excluded_assessment_base_components'] ?? [],
                    'social.relationship.excluded_assessment_base_components',
                ),
                'part_time_employer_discount' => (string) (
                    $snapshot['part_time_employer_discount'] ?? ''
                ),
                'employer_rate_category' => (string) ($snapshot['employer_rate_category'] ?? ''),
                'annual_maximum_allocation_order' => self::optionalPositiveInt(
                    $snapshot,
                    'annual_maximum_allocation_order',
                ),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array<string,mixed>>
     */
    private function healthRelationships(array $person): array
    {
        $result = [];
        foreach (self::rows($person['relationships'] ?? [], 'health.relationships') as $row) {
            $snapshot = self::object(
                $row['result_snapshot'] ?? null,
                'health.relationship.result_snapshot',
            );
            $participation = self::object(
                $snapshot['participation'] ?? null,
                'health.relationship.participation',
            );
            $result[] = [
                'employment_id' => self::positiveInt($row, 'employment_id'),
                'relationship_reference' => (string) ($snapshot['relationship_id'] ?? ''),
                'kind' => (string) ($snapshot['kind'] ?? ''),
                'result_status' => (string) ($row['result_status'] ?? ''),
                'participation_status' => (string) ($participation['status'] ?? ''),
                'relationship_income_minor' => self::nonNegativeInt(
                    $participation,
                    'relationship_income_minor_units',
                ),
                'group_income_minor' => self::nonNegativeInt(
                    $participation,
                    'group_income_minor_units',
                ),
                'threshold_minor' => self::optionalNonNegativeInt(
                    $participation,
                    'threshold_minor_units',
                ),
                'reason_codes' => self::strings(
                    $participation['reason_codes'] ?? [],
                    'health.relationship.reason_codes',
                ),
                'assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'assessment_base_minor_units',
                ),
                'participating_assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'participating_assessment_base_minor_units',
                ),
                'included_participation_components' => self::strings(
                    $snapshot['included_participation_components'] ?? [],
                    'health.relationship.included_participation_components',
                ),
                'excluded_participation_components' => self::strings(
                    $snapshot['excluded_participation_components'] ?? [],
                    'health.relationship.excluded_participation_components',
                ),
                'included_assessment_base_components' => self::strings(
                    $snapshot['included_assessment_base_components'] ?? [],
                    'health.relationship.included_assessment_base_components',
                ),
                'excluded_assessment_base_components' => self::strings(
                    $snapshot['excluded_assessment_base_components'] ?? [],
                    'health.relationship.excluded_assessment_base_components',
                ),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $month
     * @return list<array<string,mixed>>
     */
    private function insurerLiabilities(array $month, ?string $personInsurerCode): array
    {
        $result = [];
        foreach (self::rows($month['insurer_liabilities'] ?? [], 'health.insurer_liabilities') as $row) {
            $code = (string) ($row['insurer_code'] ?? '');
            $result[] = [
                'insurer_code' => $code,
                'is_person_insurer' => $personInsurerCode !== null && $code === $personInsurerCode,
                'person_count' => self::nonNegativeInt($row, 'person_count'),
                'assessment_base_minor' => self::nonNegativeInt($row, 'assessment_base_minor_units'),
                'employee_minor' => self::nonNegativeInt($row, 'employee_contribution_minor_units'),
                'employer_minor' => self::nonNegativeInt($row, 'employer_contribution_minor_units'),
                'total_minor' => self::nonNegativeInt($row, 'total_contribution_minor_units'),
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int =>
                strcmp((string) $left['insurer_code'], (string) $right['insurer_code']),
        );

        return $result;
    }

    /**
     * Rozklad, který nedá výslednou částku, není vysvětlení — je to druhý, tichý
     * výpočet. Radši spadneme, než abychom účetnímu ukázali čísla, která nesedí.
     *
     * @param array<string,mixed>|null $contributionStep
     * @param array<string,mixed>|null $discountStep
     */
    private function assertEmployeeSocialReconciles(
        int $cappedBase,
        ?array $contributionStep,
        ?int $beforeDiscount,
        ?array $discountStep,
        ?int $discount,
        ?int $contribution,
    ): void {
        if ($beforeDiscount === null || $discount === null || $contribution === null) {
            throw new \DomainException(
                'Vypočtený sociální výsledek nemá všechny částky pojistného zaměstnance.',
            );
        }
        if ($beforeDiscount - $discount !== $contribution) {
            throw new \DomainException(
                'Rozklad sociálního pojištění nedává uloženou částku pojistného zaměstnance.',
            );
        }
        if ($contributionStep === null) {
            if ($beforeDiscount !== 0) {
                throw new \DomainException(
                    'Sociální pojistné bez mezikroku výpočtu nesmí být nenulové.',
                );
            }

            return;
        }
        self::assertStepRoundsTo($contributionStep, $cappedBase, $beforeDiscount, 'sociálního');
        if ($discountStep !== null) {
            self::assertStepInput($discountStep, $cappedBase, 'slevy pro pracujícího důchodce');
        } elseif ($discount !== 0) {
            throw new \DomainException(
                'Sleva pro pracujícího důchodce bez mezikroku výpočtu nesmí být nenulová.',
            );
        }
    }

    /**
     * @param array<string,mixed>|null $standardStep
     * @param array<string,mixed>|null $topUpStep
     */
    private function assertHealthReconciles(
        ?array $standardStep,
        ?int $standard,
        ?int $employeeStandard,
        ?int $employerStandard,
        ?array $topUpStep,
        ?int $employeeTopUp,
        ?int $employerTopUp,
        ?int $employee,
        ?int $employer,
        ?int $total,
    ): void {
        foreach ([
            $standard,
            $employeeStandard,
            $employerStandard,
            $employeeTopUp,
            $employerTopUp,
            $employee,
            $employer,
            $total,
        ] as $amount) {
            if ($amount === null) {
                throw new \DomainException(
                    'Vypočtený zdravotní výsledek nemá všechny částky pojistného.',
                );
            }
        }
        if ($employeeStandard + $employerStandard !== $standard) {
            throw new \DomainException(
                'Podíly zaměstnance a zaměstnavatele nedávají uložené zdravotní pojistné.',
            );
        }
        if ($employeeStandard + $employeeTopUp !== $employee
            || $employerStandard + $employerTopUp !== $employer
            || $employee + $employer !== $total
        ) {
            throw new \DomainException(
                'Rozklad zdravotního pojištění nedává uložené částky pojistného.',
            );
        }
        if ($standardStep !== null) {
            self::assertRounding($standardStep, $standard, 'zdravotního');
        } elseif ($standard !== 0) {
            /*
             * Starší revize krok neuchovala. To se nesmí zamlčet ani dopočítat —
             * konzument dostane `rate_source: not_recorded` a řekne to větou.
             */
            return;
        }
        if ($topUpStep !== null) {
            self::assertRounding($topUpStep, $employeeTopUp + $employerTopUp, 'dopočtu do minima');
        } elseif ($employeeTopUp + $employerTopUp !== 0) {
            throw new \DomainException(
                'Dopočet do minimálního vyměřovacího základu bez mezikroku nesmí být nenulový.',
            );
        }
    }

    /** @param array<string,mixed> $step */
    private static function assertStepRoundsTo(
        array $step,
        int $expectedInput,
        int $amount,
        string $context,
    ): void {
        self::assertStepInput($step, $expectedInput, $context);
        self::assertRounding($step, $amount, $context);
    }

    /** @param array<string,mixed> $step */
    private static function assertStepInput(array $step, int $expectedInput, string $context): void
    {
        if (self::nonNegativeInt($step, 'input_minor_units') !== $expectedInput) {
            throw new \DomainException(
                "Mezikrok {$context} pojistného nevychází z uloženého vyměřovacího základu.",
            );
        }
    }

    /**
     * Uložené pojistné je vždy krok zaokrouhlený nahoru na celé koruny. Kdyby to
     * neplatilo, rozklad by ukazoval jiné číslo než výsledek.
     *
     * @param array<string,mixed> $step
     */
    private static function assertRounding(array $step, int $amount, string $context): void
    {
        $raw = self::nonNegativeInt($step, 'output_minor_units');
        if ($amount % 100 !== 0 || $amount < $raw || $amount - $raw >= 100) {
            throw new \DomainException(
                "Zaokrouhlení {$context} pojistného neodpovídá uložené částce.",
            );
        }
    }

    /**
     * @param array<string,mixed> $set
     * @return array<string,mixed>|null
     */
    private function personRow(array $set, int $employeeId): ?array
    {
        foreach (self::rows($set['people'] ?? [], 'statutory_result.people') as $person) {
            if (self::positiveInt($person, 'employee_id') === $employeeId) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function frozenEmployee(array $input, int $employeeId): array
    {
        foreach (self::rows($input['people'] ?? null, 'input_snapshot.people') as $person) {
            $employee = self::object($person['employee'] ?? null, 'input_snapshot.person.employee');
            if (self::positiveInt($employee, 'id') === $employeeId) {
                return $employee;
            }
        }

        throw new \OutOfBoundsException('Osoba není součástí této mzdové revize.');
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $reason): array
    {
        if (!in_array($reason, self::UNAVAILABLE_REASONS, true)) {
            throw new \LogicException('Nepodporovaný důvod nedostupnosti rozkladu.');
        }

        return ['available' => false, 'unavailable_reason' => $reason];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function step(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        $step = self::object($value, $field);
        $rate = self::object($step['rate'] ?? null, "{$field}.rate");

        return [
            'label' => (string) ($step['label'] ?? ''),
            'input_minor_units' => self::nonNegativeInt($step, 'input_minor_units'),
            'rate' => [
                'decimal' => (string) ($rate['decimal'] ?? ''),
                'numerator' => self::nonNegativeInt($rate, 'numerator'),
                'denominator' => self::positiveInt($rate, 'denominator'),
                'scale' => self::nonNegativeInt($rate, 'scale'),
            ],
            'unrounded_numerator' => self::nonNegativeInt($step, 'unrounded_numerator'),
            'unrounded_denominator' => self::positiveInt($step, 'unrounded_denominator'),
            'rounding_mode' => (string) ($step['rounding_mode'] ?? ''),
            'output_minor_units' => self::nonNegativeInt($step, 'output_minor_units'),
        ];
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException("{$field} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = self::object($row, "{$field}.{$index}");
        }

        return $result;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException("{$field} musí obsahovat jen texty.");
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$field} musí být text nebo null.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$field} musí být celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value < 0) {
            throw new \UnexpectedValueException("{$field} musí být nezáporné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function optionalNonNegativeInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::nonNegativeInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function optionalPositiveInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::positiveInt($row, $field);
    }
}
