<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Číselníky ČSSZ, ze kterých se plní kódované prvky žádosti o dávku v NEMPRI25.
 *
 * XSD je u všech čtyř prvků jen `StCiselnik` (1 až 3 znaky 0-9 a A-Z), takže
 * vymyšlený kód připnutým schématem projde a odmítne ho až územní správa.
 * Hodnoty proto žijí tady, přesně podle zdroje:
 *
 * - `duvodOtcovske` — výčet přímo v definici datové věty (DV NEMPRI25 v1,
 *   20260309): OTC otec dítěte, ZEM otec zemřelého dítěte, PEC převzetí do péče.
 * - `duvodPece` (PPM) — číselník CIS_DUVPREVZETI.
 * - `kodRodVztah` (OSE) — číselník CIS_RODVZTAH.
 * - `kodVztah` (DLO) — číselník CIS_VZTAH, vztah podle § 41a odst. 4
 *   zák. č. 187/2006 Sb.
 *
 * Číselníky jsou z www.cssz.gov.cz/web/cz/ciselniky (platnost od 1. 1. 2025,
 * bez konce platnosti). Přijatá podání PAMICA ukládají tytéž položky jako
 * pořadí v číselníku ČSSZ (1 = PL u ošetřovného, 3 = syn/dcera u DLO,
 * 1 = OTC u otcovské), tedy ve stejném pořadí, jaké drží konstanty níže.
 *
 * Ošetřovné a dlouhodobé ošetřovné sdílejí jeden sloupec `relationship_code`,
 * ale každé má JINÝ číselník — „1“ znamená u DLO manžela, u ošetřovného nic.
 * Proto se platnost kódu vždy posuzuje podle druhu dávky
 * ({@see self::relationshipCodes()}).
 */
final class NempriCodebook
{
    public const SOURCE = 'https://www.cssz.gov.cz/web/cz/ciselniky';

    /** `duvodOtcovske` — DV NEMPRI25 v1 (20260309). */
    public const PATERNITY_REASONS = ['OTC', 'ZEM', 'PEC'];

    /** `duvodPece` — CIS_DUVPREVZETI. */
    public const MATERNITY_CARE_REASONS = ['DOH', 'ONE', 'ROZ', 'UMR'];

    /** `kodRodVztah` u ošetřovného — CIS_RODVZTAH. */
    public const FAMILY_RELATIONSHIPS = ['PL', 'MA', 'RP', 'SDO', 'SO', 'TCH', 'JIN'];

    /** `kodVztah` u dlouhodobého ošetřovného — CIS_VZTAH (1 až 29). */
    public const CARE_RELATIONSHIPS = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        '21', '22', '23', '24', '25', '26', '27', '28', '29',
    ];

    /**
     * Kódy vztahu ošetřované osoby, které daný druh dávky přijímá. Prázdný
     * seznam = druh dávky vztah vůbec nenese.
     *
     * @return list<string>
     */
    public static function relationshipCodes(SicknessBenefitKind $kind): array
    {
        return match ($kind) {
            SicknessBenefitKind::Ose => self::FAMILY_RELATIONSHIPS,
            SicknessBenefitKind::Dlo => self::CARE_RELATIONSHIPS,
            default => [],
        };
    }

    /**
     * Kontrola všech tří kódovaných sloupců případu proti druhu dávky.
     *
     * Hodnota `null` projde vždy — jestli je prvek povinný, rozhoduje
     * {@see SicknessXmlValidator}; tady jde jen o to, aby do věty nešel kód
     * mimo číselník nebo kód jiného druhu dávky.
     */
    public static function assertValid(
        SicknessBenefitKind $kind,
        ?string $relationshipCode,
        ?string $paternityReason,
        ?string $maternityCareReason,
    ): void {
        if ($relationshipCode !== null) {
            $allowed = self::relationshipCodes($kind);
            if ($allowed === []) {
                throw new SicknessException(
                    'nempri_relationship_code_not_in_kind',
                    'Vztah ošetřované osoby se vyplňuje jen u ošetřovného a dlouhodobého '
                    . 'ošetřovného. U tohoto druhu dávky pole nechte prázdné.',
                );
            }
            if (!in_array($relationshipCode, $allowed, true)) {
                throw new SicknessException(
                    'nempri_relationship_code_invalid',
                    $kind === SicknessBenefitKind::Dlo
                        ? 'Vztah ošetřované osoby u dlouhodobého ošetřovného musí být kód 1 až 29 '
                            . 'z číselníku ČSSZ CIS_VZTAH. Vyberte ho v případu dávky ze seznamu.'
                        : 'Vztah ošetřované osoby u ošetřovného musí být kód z číselníku ČSSZ '
                            . 'CIS_RODVZTAH (PL, MA, RP, SDO, SO, TCH, JIN). Vyberte ho v případu '
                            . 'dávky ze seznamu.',
                );
            }
        }
        if ($paternityReason !== null) {
            if ($kind !== SicknessBenefitKind::Opp) {
                throw new SicknessException(
                    'nempri_paternity_reason_not_in_kind',
                    'Důvod otcovské se vyplňuje jen u otcovské.',
                );
            }
            if (!in_array($paternityReason, self::PATERNITY_REASONS, true)) {
                throw new SicknessException(
                    'nempri_paternity_reason_invalid',
                    'Důvod otcovské musí být OTC (otec dítěte), ZEM (otec zemřelého dítěte) '
                    . 'nebo PEC (převzetí dítěte do péče). Vyberte ho v případu dávky ze seznamu.',
                );
            }
        }
        if ($maternityCareReason !== null) {
            if ($kind !== SicknessBenefitKind::Ppm) {
                throw new SicknessException(
                    'nempri_maternity_care_reason_not_in_kind',
                    'Důvod převzetí dítěte do péče se vyplňuje jen u peněžité pomoci v mateřství.',
                );
            }
            if (!in_array($maternityCareReason, self::MATERNITY_CARE_REASONS, true)) {
                throw new SicknessException(
                    'nempri_maternity_care_reason_invalid',
                    'Důvod převzetí dítěte do péče musí být kód z číselníku ČSSZ CIS_DUVPREVZETI '
                    . '(DOH, ONE, ROZ, UMR). Vyberte ho v případu dávky ze seznamu.',
                );
            }
        }
    }
}
