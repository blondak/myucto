<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Bližší určení pracovněprávního vztahu (ID 10502).
 *
 * Číselník „CIS Bližší určení pracovněprávního vztahu" má tři hodnoty:
 * 1 = žádné, 2 = výkon trestu odnětí svobody / zabezpečovací detence,
 * 3 = pracovní vztah specifické skupiny. Volit mezi nimi jde jen u druhu
 * činnosti 1 až 9; u ostatních je bližší určení „žádné".
 *
 * Dvě různé otázky, dvě metody:
 * - {@see modeForActivity()} říká, co vede EVIDENCE pracovního vztahu.
 *   U dohod (A–J, T–ZC) a u 15, 16 evidence bližší určení nevede, protože
 *   z něj pro měsíční hlášení nic neplyne (formulář `bezPriznaku`).
 * - {@see requireForActivity()} říká, co jde do REGZEC. Tam je 10502 podle
 *   EDV 1.4.0.6 povinné u všech akcí A1–A4 varianty OST i SPEC, tedy i
 *   u dohod. Premier i PAMICA posílají u DPP a DPČ `relDetail="1"` a ČSSZ
 *   takové registrace přijala. Jen u druhu činnosti 10 se údaj neuvádí.
 */
final class PayrollRegistrationRelationshipDetailPolicy
{
    public const MODE_FORBIDDEN = 'forbidden';
    public const MODE_SELECT = 'select';
    public const MODE_FIXED_NONE = 'fixed_none';

    /** Hodnota „žádné bližší určení". */
    public const NONE = '1';

    /** Činnosti, u kterých evidence bližší určení nevede. */
    private const WITHOUT_EVIDENCE_DETAIL = [
        '15', '16',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
        'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC',
    ];

    /** Druh činnosti 10: datová věta A*-10 atribut 10502 nemá vůbec. */
    private const WITHOUT_REGZEC_DETAIL = ['10'];

    /**
     * Hodnota 10502 pro REGZEC (A1–A4).
     *
     * U činností, kde evidence bližší určení nevede, se doplní „žádné" (1);
     * vyplněná jiná hodnota je vada. U druhu činnosti 10 se nesmí uvést nic.
     */
    public static function requireForActivity(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): ?string {
        // Výjimky tu zůstávají: volající (business matice) je chytá a překládá
        // na sbíranou vadu, takže uložení rozpracovaného profilu tím nepadá.
        $detail = PayrollRegistrationFieldVocabulary::label(
            'employment.relationship_detail_code',
        );
        $where = PayrollRegistrationFieldVocabulary::describe(
            'employment.relationship_detail_code',
        );
        $filled = $relationshipDetailCode !== null && $relationshipDetailCode !== '';
        if (in_array($activityCode, self::WITHOUT_REGZEC_DETAIL, true)) {
            if ($filled) {
                self::forbidden($activityCode, $relationshipDetailCode);
            }
            return null;
        }
        if (in_array($activityCode, self::WITHOUT_EVIDENCE_DETAIL, true)) {
            if ($filled && $relationshipDetailCode !== self::NONE) {
                throw new \InvalidArgumentException(
                    $detail . " musí být u druhu činnosti „{$activityCode}“ "
                        . 'hodnota 1 (žádné), teď je '
                        . "„{$relationshipDetailCode}“. " . $where,
                );
            }
            return self::NONE;
        }
        if (!$filled) {
            throw new \InvalidArgumentException(
                $detail . " chybí — u druhu činnosti „{$activityCode}“ ho ČSSZ "
                    . 'vyžaduje. ' . $where,
            );
        }
        if (preg_match('/^[1-9]$/D', $activityCode) === 1) {
            if (!in_array($relationshipDetailCode, ['1', '2', '3'], true)) {
                throw new \InvalidArgumentException(
                    $detail . ' musí být 1 (žádné), 2 (výkon trestu odnětí '
                        . 'svobody nebo zabezpečovací detence) nebo 3 '
                        . '(pracovní vztah specifické skupiny), teď je '
                        . "„{$relationshipDetailCode}“. " . $where,
                );
            }
            return $relationshipDetailCode;
        }
        if ($relationshipDetailCode !== self::NONE) {
            throw new \InvalidArgumentException(
                $detail . " musí být u druhu činnosti „{$activityCode}“ "
                    . 'hodnota 1 (žádné), teď je '
                    . "„{$relationshipDetailCode}“. " . $where,
            );
        }

        return self::NONE;
    }

    /**
     * Hodnota bližšího určení v EVIDENCI pracovního vztahu (podmínky vztahu).
     *
     * Na rozdíl od REGZEC evidence u dohod a u 15, 16 nevede nic; do podání
     * se tam „1" doplní až v {@see requireForActivity()}.
     */
    public static function requireForEvidence(
        string $activityCode,
        ?string $relationshipDetailCode,
    ): ?string {
        if (in_array($activityCode, self::WITHOUT_EVIDENCE_DETAIL, true)) {
            if ($relationshipDetailCode !== null && $relationshipDetailCode !== '') {
                self::forbidden($activityCode, $relationshipDetailCode);
            }
            return null;
        }

        return self::requireForActivity($activityCode, $relationshipDetailCode);
    }

    private static function forbidden(string $activityCode, string $value): never
    {
        /*
         * Tady se pole MAŽE, nepřidává. Obecná věta „údaj doplňte
         * na …" by si s tím protiřečila — hláška by v jedné větě
         * říkala nechte prázdné a zároveň jděte to vyplnit.
         */
        $place = PayrollRegistrationFieldVocabulary::where(
            'employment.relationship_detail_code',
        );
        throw new \InvalidArgumentException(
            PayrollRegistrationFieldVocabulary::label(
                'employment.relationship_detail_code',
            ) . " se u druhu činnosti „{$activityCode}“ "
                . "nevyplňuje, teď je „{$value}“. "
                . ($place === null
                    ? 'Vymažte ho přímo v tomhle formuláři.'
                    : "Vymažte ho na {$place}."),
        );
    }

    /** Co o bližším určení vede evidence pracovního vztahu. */
    public static function modeForActivity(string $activityCode): string
    {
        if (in_array($activityCode, self::WITHOUT_REGZEC_DETAIL, true)
            || in_array($activityCode, self::WITHOUT_EVIDENCE_DETAIL, true)
        ) {
            return self::MODE_FORBIDDEN;
        }

        return preg_match('/^[1-9]$/D', $activityCode) === 1
            ? self::MODE_SELECT
            : self::MODE_FIXED_NONE;
    }
}
