<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentJmhzActivityFamily
{
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    /**
     * Druhy činnosti formuláře `cinnostKS` (kontrola 343 ČSSZ: K, N, O, P, Q,
     * R, S). Společník, jednatel nebo člen orgánu se nevykazuje jen kódem S:
     * prokurista je P, člen kolektivního orgánu Q, likvidátor R. Přijatá
     * hlášení jiného mzdového systému nesou u takového vztahu P s kódem ELDP
     * P++ a ČSSZ je přijala. M (pěstoun) sem nepatří, má vlastní formulář.
     */
    public const CORPORATE_BODY_ACTIVITY_CODES = ['K', 'N', 'O', 'P', 'Q', 'R', 'S'];

    public static function isCorporateBodyActivity(mixed $activityCode): bool
    {
        return in_array($activityCode, self::CORPORATE_BODY_ACTIVITY_CODES, true);
    }

    public static function appliesTo(string $relationType): bool
    {
        return in_array(
            $relationType,
            ['employment', 'small_scale_employment', 'dpc', 'dpp', 'partner_dependent', 'statutory_body'],
            true,
        );
    }

    /**
     * Druh činnosti pro ČSSZ u PRVNÍHO vztahu daného druhu u zaměstnavatele.
     *
     * Zakládaný zaměstnanec u firmy žádný jiný vztah nemá, takže „první
     * pracovní poměr" (1), „dohoda o pracovní činnosti" (A), „dohoda o
     * provedení práce" (T) i „člen statutárního orgánu" (S) jsou jednoznačné.
     * Dřív pole zůstalo prázdné a chybějící kód se poznal až na obrazovce
     * registrace nebo při sestavování měsíčního hlášení — tedy ve chvíli, kdy
     * už běží lhůta. Je to NÁVRH při založení, účetní ho může přepsat.
     *
     * @return array{0:?string,1:?string} [activity_code, relationship_detail_code]
     */
    public static function firstRelationDefaults(string $relationType): array
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => ['1', '1'],
            'dpc' => ['A', null],
            'dpp' => ['T', null],
            'partner_dependent', 'statutory_body' => ['S', '1'],
            default => [null, null],
        };
    }

    /**
     * Druh činnosti pro DALŠÍ vztah téhož druhu u zaměstnavatele.
     *
     * Kód nese pořadí souběžného vztahu: druhý pracovní poměr u téhož
     * zaměstnavatele je „2", druhá DPČ „B", druhá DPP „U". Dostane se první
     * kód řady, který žádný jiný živý vztah osoby nepoužívá — po skončení
     * prvního poměru tak nový zase dostane „1". Kód mimo řadu (vedle „1"
     * třeba „10") pořadí neovlivní. Je to NÁVRH, účetní ho může přepsat.
     *
     * @param list<string> $usedCodes kódy živých souběžných vztahů osoby
     * @return array{0:?string,1:?string} [activity_code, relationship_detail_code]
     */
    public static function nextRelationDefaults(string $relationType, array $usedCodes): array
    {
        [$first, $detail] = self::firstRelationDefaults($relationType);
        $sequence = match ($relationType) {
            'employment', 'small_scale_employment' => array_map(strval(...), range(1, 9)),
            'dpc' => range('A', 'J'),
            'dpp' => self::DPP_ACTIVITY_CODES,
            // Společník a člen orgánu pořadí v kódu nenesou.
            default => null,
        };
        if ($sequence === null) {
            return [$first, $detail];
        }
        foreach ($sequence as $code) {
            if (!in_array($code, $usedCodes, true)) {
                return [$code, $detail];
            }
        }

        return [null, null];
    }

    public static function matches(
        string $relationType,
        string $activityCode,
        ?string $relationshipDetailCode,
    ): bool {
        return match ($relationType) {
            'employment', 'small_scale_employment' => preg_match('/^[1-9]$/D', $activityCode) === 1
                && $relationshipDetailCode === '1',
            'dpc' => preg_match('/^[A-J]$/D', $activityCode) === 1
                && $relationshipDetailCode === null,
            'dpp' => in_array($activityCode, self::DPP_ACTIVITY_CODES, true)
                && $relationshipDetailCode === null,
            'partner_dependent', 'statutory_body' => self::isCorporateBodyActivity($activityCode)
                && $relationshipDetailCode === '1',
            default => false,
        };
    }
}
