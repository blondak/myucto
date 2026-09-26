<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Kam se nález měsíčního hlášení opravuje.
 *
 * Každý kód, který mzdová podání vydávají (výjimka, blokátor, nález
 * připravenosti), tu má druh nápravy — místo v aplikaci, kam UI účetní
 * pošle. Popisek kódu je v `web/src/i18n/{cs,en}.json` pod
 * `payroll.jmhz_gate.codes.<kód>`. Že žádný kód nezůstal bez obojího, hlídá
 * `JmhzCodeCatalogCoverageTest`: dřív UI u padesáti kódů psalo „neznámá
 * blokace" a posílalo na podporu, přestože server věděl, co chybí.
 */
final class JmhzBlockerCatalog
{
    /** @var list<string> */
    public const KINDS = [
        'employment_terms',
        'employment_profile',
        'employment_identity',
        'employee_identity',
        'statutory_evidence',
        'dependants',
        'absences',
        'averages',
        'time',
        'runs',
        'components',
        'ordinary_evidence',
        'employer_annual',
        'office',
        'annual_settlement',
        'takeover',
        'workplace',
        'correction',
        'submission',
        'retry',
        'manual',
        'support',
    ];

    /**
     * Druh nápravy podle kódu, případně s polem, na které se má doskočit.
     *
     * @var array<string,string|array{0:string,1:string}>
     */
    private const REMEDIATION = [
    ];

    /** @return array{kind:string,field:?string} */
    public static function remediation(string $code, string $entityType = ''): array
    {
        $entry = self::REMEDIATION[$code] ?? null;
        if (is_array($entry)) {
            return ['kind' => $entry[0], 'field' => $entry[1]];
        }
        if (is_string($entry)) {
            return ['kind' => $entry, 'field' => null];
        }

        return ['kind' => self::fallbackKind($entityType), 'field' => null];
    }

    public static function knows(string $code): bool
    {
        return isset(self::REMEDIATION[$code]);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::REMEDIATION);
    }

    private static function fallbackKind(string $entityType): string
    {
        return match ($entityType) {
            'employment' => 'employment_terms',
            'person', 'employee' => 'employee_identity',
            'component' => 'components',
            'office' => 'office',
            'run', 'revision' => 'runs',
            default => 'retry',
        };
    }
}
