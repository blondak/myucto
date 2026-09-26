<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

/**
 * Postavení v zaměstnání (REGZEC `job/@relat`, ID 10249).
 *
 * Zdroj je číselník „CIS Klasif. postavení v zaměstn" (NKPZ, ČSÚ) vložený
 * v připnutém datovém slovníku JMHZ, tedy tentýž balík jako ostatní číselníky
 * registrace. Číselník je hierarchický (1 → 11 → 111 → 1111), EDV 1.4.0.6 ale
 * výslovně říká „musí jít o 4místný kód, kratší nejsou akceptovány", takže
 * nabídka i kontrola pracují jen s listy o čtyřech číslicích. U druhu
 * činnosti 15 a 16 je číselník podle EDV omezený na 1341 a 1342.
 */
final class PayrollRegistrationEmploymentStatusCodebook
{
    private const CODEBOOK_KEY = 'klasif_postaveni_v_zamestn';

    /** Omezení číselníku podle druhu činnosti (EDV 1.4.0.6, ID 10249). */
    private const ACTIVITY_RESTRICTIONS = [
        '15' => ['1341', '1342'],
        '16' => ['1341', '1342'],
    ];

    /** @var array<string,string>|null kód → název */
    private static ?array $entries = null;

    /** @return list<array{code:string,label:string}> */
    public static function options(): array
    {
        $options = [];
        foreach (self::entries() as $code => $label) {
            $options[] = ['code' => $code, 'label' => $label];
        }

        return $options;
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::entries());
    }

    /**
     * Kódy povolené pro druh činnosti; `null` = bez omezení.
     *
     * @return list<string>|null
     */
    public static function restrictedFor(?string $activityCode): ?array
    {
        return $activityCode === null
            ? null
            : (self::ACTIVITY_RESTRICTIONS[$activityCode] ?? null);
    }

    /**
     * Návrh z evidence: druh vztahu a doba určitá. Jen předvyplnění, účetní
     * ho ve formuláři potvrdí nebo přepíše.
     */
    public static function suggest(
        ?string $relationType,
        ?string $activityCode,
        bool $fixedTerm,
    ): ?string {
        if ($activityCode === '15' || $activityCode === '16') {
            return $fixedTerm ? '1342' : '1341';
        }

        return match ($relationType) {
            'employment', 'small_scale_employment' => $fixedTerm ? '1112' : '1111',
            'dpc' => $fixedTerm ? '1212' : '1211',
            'dpp' => $fixedTerm ? '1222' : '1221',
            default => null,
        };
    }

    /** @return array<string,string> */
    private static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $catalog = new JmhzCodebookCatalog((new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));
        $entries = [];
        foreach ($catalog->entries(self::CODEBOOK_KEY) as $entry) {
            $code = $entry['item_code'] ?? null;
            $label = $entry['label'] ?? null;
            if (!is_string($code) || !is_string($label)) {
                throw new \UnexpectedValueException(
                    'Připnutý číselník postavení v zaměstnání má neplatnou položku.',
                );
            }
            if (preg_match('/^\d{4}$/D', $code) === 1) {
                $entries[$code] = trim($label);
            }
        }
        if ($entries === []) {
            throw new \UnexpectedValueException(
                'Připnutý číselník postavení v zaměstnání nemá žádný čtyřmístný kód.',
            );
        }
        ksort($entries, SORT_STRING);
        self::$entries = $entries;

        return $entries;
    }
}
