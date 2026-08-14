<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Pravidla pro GUID podání a jeho součástí (kap. 9 pravidel podání).
 *
 * Rozbor zadání označuje tohle za nejsnáze zaměnitelné místo celé specifikace,
 * a je to vidět na jediné dvojici: **zamítnutá součást se posílá znovu s NOVÝM
 * GUID, kdežto oprava bezvadné součásti používá PŮVODNÍ GUID s typem „O"**.
 * Záměna vede k odmítnutí, protože ČSSZ v prvním případě nemá k čemu se
 * referovat a ve druhém dostane dvě různé součásti pro tentýž vztah.
 *
 * Politika je čistá — nesahá do databáze ani negeneruje GUID. Rozhoduje jen
 * o tom, KTERÝ GUID se má použít; vyrobit ho je věcí volajícího.
 */
final class JmhzSubmissionGuidPolicy
{
    /** Vygeneruj nový GUID. */
    public const NEW_GUID = 'new_guid';

    /** Použij GUID řádného podání, ke kterému se návazné podání váže. */
    public const REGULAR_SUBMISSION_GUID = 'regular_submission_guid';

    /** Použij původní GUID součásti z řádného podání. */
    public const ORIGINAL_FORM_GUID = 'original_form_guid';

    /** Stav předchozího podání za totéž rozhodné období. */
    public const SUBMISSION_NONE = 'none';
    public const SUBMISSION_ACCEPTED = 'accepted';
    public const SUBMISSION_REJECTED = 'rejected';
    public const SUBMISSION_CANCELLED = 'cancelled';

    /** Stav součásti v dosud podaném řádném podání. */
    public const FORM_NONE = 'none';
    public const FORM_ACCEPTED = 'accepted';
    public const FORM_REJECTED = 'rejected';
    public const FORM_CANCELLED = 'cancelled';

    /**
     * GUID podání. Řádné podání dostává nový GUID vždy — i po zamítnutí
     * a po stornu, protože stornem původní GUID zaniká a nesmí se oživit.
     * Opravné i stornující podání se naopak vážou na GUID řádného podání;
     * zamítnuté opravné se posílá znovu se stejným GUID řádného.
     */
    public function forSubmission(string $submissionType, string $previousState): string
    {
        $this->assertSubmissionState($previousState);

        return match ($submissionType) {
            JmhzSubmissionFlagMatrix::TYPE_REGULAR => self::NEW_GUID,
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionFlagMatrix::TYPE_CANCELLATION => $this->requireRegular($previousState),
            default => throw new JmhzXmlException(
                'jmhz_submission_type_unknown',
                "Typ podání {$submissionType} není v pravidlech JMHZ definovaný.",
            ),
        };
    }

    /**
     * GUID součásti individualizované části.
     *
     * Součást v řádném podání dostává nový GUID. V opravném podání rozhoduje
     * OSUD původní součásti, ne typ formuláře: zamítnutá i stornovaná součást
     * se posílá s novým GUID, kdežto oprava nebo storno bezvadné součásti se
     * referuje na její původní GUID.
     */
    public function forForm(
        string $submissionType,
        string $formType,
        string $previousFormState,
    ): string {
        $this->assertFormState($previousFormState);
        if ($submissionType === JmhzSubmissionFlagMatrix::TYPE_CANCELLATION) {
            throw new JmhzXmlException(
                'jmhz_cancellation_has_no_forms',
                'Stornující podání neobsahuje součásti, takže pro ně GUID nevzniká.',
            );
        }
        if ($submissionType === JmhzSubmissionFlagMatrix::TYPE_REGULAR) {
            if ($formType !== JmhzSubmissionFlagMatrix::TYPE_REGULAR) {
                throw new JmhzXmlException(
                    'jmhz_flag_combination_unsupported',
                    'Řádné podání smí obsahovat jen součásti typu R.',
                );
            }

            return self::NEW_GUID;
        }
        if ($submissionType !== JmhzSubmissionFlagMatrix::TYPE_AMENDMENT) {
            throw new JmhzXmlException(
                'jmhz_submission_type_unknown',
                "Typ podání {$submissionType} není v pravidlech JMHZ definovaný.",
            );
        }

        return match ($formType) {
            // Nová nebo znovu podaná součást: buď v řádném podání nebyla vůbec,
            // nebo v něm byla a byla zamítnutá či stornovaná. V obou případech
            // se na co referovat nemá a GUID musí být nový.
            JmhzSubmissionFlagMatrix::TYPE_REGULAR => $this->assertNoValidOriginal(
                $previousFormState,
            ),
            // Oprava i storno bezvadné součásti se referují na její GUID.
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionFlagMatrix::TYPE_CANCELLATION => $this->requireValidOriginal(
                $previousFormState,
                $formType,
            ),
            default => throw new JmhzXmlException(
                'jmhz_form_type_unknown',
                "Typ součásti {$formType} není v pravidlech JMHZ definovaný.",
            ),
        };
    }

    private function requireRegular(string $previousState): string
    {
        if ($previousState === self::SUBMISSION_NONE) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_regular',
                'Opravné ani stornující podání nelze poslat dřív než řádné,'
                    . ' na jehož GUID se váže.',
            );
        }
        if ($previousState === self::SUBMISSION_CANCELLED) {
            throw new JmhzXmlException(
                'jmhz_regular_submission_cancelled',
                'Stornem zanikl GUID řádného podání; za období musí nejdřív vzniknout'
                    . ' nové řádné podání s novým GUID.',
            );
        }

        return self::REGULAR_SUBMISSION_GUID;
    }

    private function assertNoValidOriginal(string $previousFormState): string
    {
        if ($previousFormState === self::FORM_ACCEPTED) {
            throw new JmhzXmlException(
                'jmhz_form_already_valid',
                'Součást je v řádném podání platná; nová se pro ni nezakládá,'
                    . ' opravuje se pod původním GUID s typem O.',
            );
        }

        return self::NEW_GUID;
    }

    private function requireValidOriginal(string $previousFormState, string $formType): string
    {
        if ($previousFormState !== self::FORM_ACCEPTED) {
            throw new JmhzXmlException(
                'jmhz_form_original_not_valid',
                "Součást typu {$formType} se referuje na platnou součást řádného"
                    . ' podání; zamítnutá ani stornovaná se opravit nedá, posílá se'
                    . ' znovu s novým GUID.',
            );
        }

        return self::ORIGINAL_FORM_GUID;
    }

    private function assertSubmissionState(string $state): void
    {
        if (!in_array($state, [
            self::SUBMISSION_NONE,
            self::SUBMISSION_ACCEPTED,
            self::SUBMISSION_REJECTED,
            self::SUBMISSION_CANCELLED,
        ], true)) {
            throw new JmhzXmlException(
                'jmhz_submission_state_unknown',
                "Stav předchozího podání {$state} není definovaný.",
            );
        }
    }

    private function assertFormState(string $state): void
    {
        if (!in_array($state, [
            self::FORM_NONE,
            self::FORM_ACCEPTED,
            self::FORM_REJECTED,
            self::FORM_CANCELLED,
        ], true)) {
            throw new JmhzXmlException(
                'jmhz_form_state_unknown',
                "Stav předchozí součásti {$state} není definovaný.",
            );
        }
    }
}
