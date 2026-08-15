<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Povolené kombinace příznaků podání a formulářů (kap. 9 pravidel podání,
 * Obr. 28 — sedmnáct řádků).
 *
 * Kombinace určuje, které části podání smí být přítomné pro daný typ podání
 * a daný typ součásti. Řádné podání musí nést všechny tři části; opravné smí
 * nést libovolnou neprázdnou podmnožinu; stornující nesmí nést žádnou.
 *
 * Tabulka je zapsaná doslova, ne odvozená z pravidla. Odvození by vypadalo
 * úsporněji, ale kdyby ČSSZ jednu kombinaci ubrala nebo přidala, rozdíl proti
 * dokumentu by nebyl vidět. Otisk tabulky se proto pinuje stejně jako
 * u ostatních katalogů modulu.
 */
final class JmhzSubmissionFlagMatrix
{
    public const MATRIX_SHA256 =
        'cb8302584ee1844169e8e066c242c04f2d63c643d57f9a64e595e826a087f7f3';

    public const TYPE_REGULAR = 'R';
    public const TYPE_AMENDMENT = 'O';
    public const TYPE_CANCELLATION = 'S';

    /**
     * Sloupce řádku: typ podání · souhrnná část · pojistná část · typ součásti.
     * `null` znamená „neuvedena".
     *
     * @var list<array{0:string,1:bool,2:bool,3:?string}>
     */
    private const ROWS = [
        [self::TYPE_REGULAR, true, true, self::TYPE_REGULAR],
        [self::TYPE_AMENDMENT, false, false, self::TYPE_REGULAR],
        [self::TYPE_AMENDMENT, true, false, self::TYPE_REGULAR],
        [self::TYPE_AMENDMENT, false, true, self::TYPE_REGULAR],
        [self::TYPE_AMENDMENT, true, true, self::TYPE_REGULAR],
        [self::TYPE_AMENDMENT, false, false, self::TYPE_AMENDMENT],
        [self::TYPE_AMENDMENT, true, false, self::TYPE_AMENDMENT],
        [self::TYPE_AMENDMENT, false, true, self::TYPE_AMENDMENT],
        [self::TYPE_AMENDMENT, true, true, self::TYPE_AMENDMENT],
        [self::TYPE_AMENDMENT, true, false, null],
        [self::TYPE_AMENDMENT, false, true, null],
        [self::TYPE_AMENDMENT, true, true, null],
        [self::TYPE_AMENDMENT, false, false, self::TYPE_CANCELLATION],
        [self::TYPE_AMENDMENT, true, false, self::TYPE_CANCELLATION],
        [self::TYPE_AMENDMENT, false, true, self::TYPE_CANCELLATION],
        [self::TYPE_AMENDMENT, true, true, self::TYPE_CANCELLATION],
        [self::TYPE_CANCELLATION, false, false, null],
    ];

    public static function rowCount(): int
    {
        return count(self::ROWS);
    }

    /**
     * Otisk celé tabulky. Slouží k tomu, aby se změna povolených kombinací
     * projevila jako rozdíl otisku, ne jako tichá změna chování.
     */
    public static function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'jmhz-submission-flag-matrix.v1',
            'source' => 'Pravidla podání JMHZ a související procesy 1.4.4, obr. 28',
            'rows' => self::ROWS,
        ]));
    }

    public static function isAllowed(
        string $submissionType,
        bool $summaryPresent,
        bool $pvpojPresent,
        ?string $formType,
    ): bool {
        foreach (self::ROWS as $row) {
            if ($row[0] === $submissionType
                && $row[1] === $summaryPresent
                && $row[2] === $pvpojPresent
                && $row[3] === $formType
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ověří celé podání. Typ součásti se posuzuje po jednotlivých typech, které
     * se v podání vyskytují — jedno opravné hlášení smí nést jak opravu, tak
     * storno, ale každá kombinace musí být povolená sama o sobě.
     *
     * @param list<string> $formTypes typy součástí individualizované části
     */
    public static function assertAllowed(
        string $submissionType,
        bool $summaryPresent,
        bool $pvpojPresent,
        array $formTypes,
    ): void {
        if (!in_array(
            $submissionType,
            [self::TYPE_REGULAR, self::TYPE_AMENDMENT, self::TYPE_CANCELLATION],
            true,
        )) {
            throw new JmhzXmlException(
                'jmhz_submission_type_unknown',
                "Typ podání {$submissionType} není v pravidlech JMHZ definovaný.",
            );
        }
        $distinct = $formTypes === [] ? [null] : array_values(array_unique($formTypes));
        foreach ($distinct as $formType) {
            if (self::isAllowed($submissionType, $summaryPresent, $pvpojPresent, $formType)) {
                continue;
            }
            throw new JmhzXmlException(
                'jmhz_flag_combination_unsupported',
                sprintf(
                    'Kombinace příznaků není povolená: podání %s, souhrnná část %s,'
                        . ' pojistná část %s, součást %s.',
                    $submissionType,
                    $summaryPresent ? 'uvedena' : 'neuvedena',
                    $pvpojPresent ? 'uvedena' : 'neuvedena',
                    $formType ?? 'neuvedena',
                ),
            );
        }
    }
}
