<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;

/**
 * Případ dávky ze schválené absence.
 *
 * ## Proč
 *
 * Lhůta NEMPRI podle § 97 odst. 2 zák. č. 187/2006 Sb. běží od 15. dne
 * neschopnosti bez ohledu na to, jestli si toho někdo všiml. Dokud případ
 * zakládala jen účetní rukou, byla hlídaná jen ta lhůta, na kterou už někdo
 * myslel — a schválená neschopnost v absencích vyrobila náhradu mzdy, ale
 * žádný případ. Schválení absence druhu, ze kterého plyne dávka, proto případ
 * založí, naváže na existující, nebo prodlouží navazující.
 *
 * ## Co se nesmí stát
 *
 * Schválení absence je mzdový krok a nesmí spadnout kvůli podání: chybí-li
 * firmě kód OSSZ nebo vznikla událost mimo ochrannou lhůtu, absence se schválí
 * a výsledek řekne, proč případ nevznikl a kde se to opraví. Výjimky se tu
 * proto nepropouštějí dál, jen pojmenují.
 *
 * Prostředí je vždy `production`: povinnost z § 97 plní jen ostré podání,
 * testovací prostředí ČSSZ žádnou lhůtu nesplní.
 */
final readonly class SicknessCaseFromAbsenceService
{
    public const ENVIRONMENT = 'production';

    /**
     * Druh absence → druh dávky. Karanténa je nemocenské (§ 26 zák.
     * č. 187/2006 Sb. ji vede spolu s neschopností).
     *
     * @var array<string,SicknessBenefitKind>
     */
    private const KIND_BY_ABSENCE = [
        'dpn' => SicknessBenefitKind::Nem,
        'quarantine' => SicknessBenefitKind::Nem,
        'ocr' => SicknessBenefitKind::Ose,
        'long_term_care' => SicknessBenefitKind::Dlo,
        'ppm' => SicknessBenefitKind::Ppm,
        'paternity' => SicknessBenefitKind::Opp,
    ];

    public function __construct(
        private PayrollSicknessCaseRepository $cases,
        private PayrollAbsenceRepository $absences,
        private SicknessCaseService $caseService,
        private SicknessDeadlinePolicy $deadlines,
    ) {}

    public static function benefitKindFor(string $absenceType): ?SicknessBenefitKind
    {
        return self::KIND_BY_ABSENCE[$absenceType] ?? null;
    }

    /**
     * @param array<string,mixed> $absence schválená absence
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     *         `null` = z absence dávka neplyne (i neschopnost, která zatím
     *         nepřesáhla okno náhrady mzdy podle § 192 ZP)
     */
    public function onApproved(int $supplierId, array $absence, ?int $userId): ?array
    {
        $kind = self::benefitKindFor((string) ($absence['absence_type'] ?? ''));
        if ($kind === null) {
            return null;
        }
        $absenceId = (int) $absence['id'];
        $employmentId = (int) $absence['employment_id'];
        $from = (string) $absence['date_from'];
        $to = (string) $absence['date_to'];

        try {
            $linked = $this->cases->findByAbsence($supplierId, self::ENVIRONMENT, $absenceId);
            if ($linked !== null) {
                return $this->result('linked', $linked, $kind);
            }

            $contiguous = $this->cases->contiguousOpenCase(
                $supplierId,
                self::ENVIRONMENT,
                $employmentId,
                $kind->value,
                $from,
            );
            if ($contiguous !== null) {
                $this->caseService->update(
                    $supplierId,
                    self::ENVIRONMENT,
                    (int) $contiguous['id'],
                    (int) $contiguous['row_version'],
                    ['incapacity_to' => $to],
                );

                return $this->result('extended', $contiguous, $kind);
            }

            $overlapping = $this->cases->overlappingForEmployment(
                $supplierId,
                self::ENVIRONMENT,
                $employmentId,
                $kind->value,
                $from,
                $to,
            );
            if ($overlapping !== []) {
                $existing = $this->caseService->requireCase(
                    $supplierId,
                    self::ENVIRONMENT,
                    (int) $overlapping[0]['id'],
                );
                if ($existing['absence_id'] === null) {
                    $this->cases->update(
                        $supplierId,
                        self::ENVIRONMENT,
                        (int) $existing['id'],
                        (int) $existing['row_version'],
                        ['absence_id' => $absenceId],
                    );
                }

                return $this->result('linked', $existing, $kind);
            }

            // § 26 odst. 1 a § 97 odst. 2 zák. č. 187/2006 Sb.: prvních 14 dnů
            // neschopnosti kryje náhrada mzdy (§ 192 ZP), dávka ani NEMPRI z nich
            // nevzniká. Případ se proto zakládá až za událost delší než okno —
            // i tehdy, když ji přes 14. den dotáhne teprve navazující absence;
            // začátek se pak bere od první z nich.
            $chain = $kind === SicknessBenefitKind::Nem
                ? $this->absences->contiguousChainStart($supplierId, $absenceId)
                : null;
            $eventFrom = $chain['date_from'] ?? $from;
            if (!$this->deadlines->nempriRequired($kind, $eventFrom, $to, $chain['carried_days'] ?? 0)) {
                return null;
            }
            $caseAbsenceId = $chain['id'] ?? $absenceId;

            if ($userId === null || $userId <= 0) {
                return $this->skipped(
                    $kind,
                    'sickness_case_actor_missing',
                    'Případ dávky se zakládá jménem účetní, která absenci schválila. Založte ho ručně v Podání → Dávky nemocenského pojištění.',
                );
            }
            $input = [
                'incapacity_from' => $eventFrom,
                'incapacity_to' => $to,
            ];
            if ($kind === SicknessBenefitKind::Ose && ($absence['lone_carer'] ?? false)) {
                $input['lone_caregiver'] = true;
            }
            $created = $this->caseService->create(
                $supplierId,
                self::ENVIRONMENT,
                $employmentId,
                $kind->value,
                $input,
                $userId,
            );
            $this->cases->update(
                $supplierId,
                self::ENVIRONMENT,
                (int) $created['id'],
                (int) $created['row_version'],
                ['absence_id' => $caseAbsenceId],
            );

            return $this->result('created', $created, $kind);
        } catch (SicknessException $exception) {
            return $this->skipped($kind, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * Zrušená absence zruší i případ, ze kterého se ještě nic nepodalo.
     * Případ s připraveným nebo odeslaným podáním zůstává: ČSSZ o něm už ví
     * a zrušit ho jde jen opravným podáním.
     *
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     */
    public function onCancelled(int $supplierId, int $absenceId): ?array
    {
        $case = $this->cases->findByAbsence($supplierId, self::ENVIRONMENT, $absenceId);
        if ($case === null) {
            return null;
        }
        $kind = SicknessBenefitKind::from((string) $case['benefit_kind']);
        if ($case['status'] === SicknessCaseStatus::Cancelled->value) {
            return $this->result('cancelled', $case, $kind);
        }
        if ($case['status'] !== SicknessCaseStatus::Draft->value
            || $case['nempri_submission_id'] !== null
            || $case['hzupn_submission_id'] !== null
        ) {
            return [
                'outcome' => 'kept',
                'case_id' => (int) $case['id'],
                'benefit_kind' => $kind->value,
                'nempri_due_on' => null,
                'reason_code' => 'sickness_case_has_submission',
                'message' => 'Z případu dávky už bylo připravené nebo odeslané podání, takže '
                    . 'se se zrušením absence nezrušil. Zkontrolujte ho v Podání → Dávky '
                    . 'nemocenského pojištění a případně podejte opravné podání.',
            ];
        }
        $this->caseService->recordReceipt(
            $supplierId,
            self::ENVIRONMENT,
            (int) $case['id'],
            'cancelled',
            null,
            null,
        );

        return $this->result('cancelled', $case, $kind);
    }

    /**
     * @param array<string,mixed> $case
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}
     */
    private function result(string $outcome, array $case, SicknessBenefitKind $kind): array
    {
        $due = null;
        if ($kind !== SicknessBenefitKind::Vpm) {
            try {
                $due = $this->deadlines->forNempri(
                    $kind,
                    (string) $case['incapacity_from'],
                    $case['incapacity_to'] === null ? null : (string) $case['incapacity_to'],
                    null,
                    (bool) ($case['lone_caregiver'] ?? false),
                )->dueOn;
            } catch (SicknessException) {
                $due = null;
            }
        }

        return [
            'outcome' => $outcome,
            'case_id' => (int) $case['id'],
            'benefit_kind' => $kind->value,
            'nempri_due_on' => $due,
            'reason_code' => null,
            'message' => null,
        ];
    }

    /** @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string} */
    private function skipped(SicknessBenefitKind $kind, string $code, string $message): array
    {
        return [
            'outcome' => 'skipped',
            'case_id' => null,
            'benefit_kind' => $kind->value,
            'nempri_due_on' => null,
            'reason_code' => $code,
            'message' => $message,
        ];
    }
}
