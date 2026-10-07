<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Název pracoviště ČSSZ podle kódu (`CIS_PRACOVIST`), pro HZUPN
 * `dokument/nazevOSSZ` (nejvýš 30 znaků).
 *
 * Název se nikdy nevymýšlí: kód, který v připnutém číselníku není, vrací
 * `null` a prvek se do věty nevypíše (v XSD je nepovinný).
 */
final class CsszWorkplaceCatalog
{
    public const MAX_NAME_LENGTH = 30;

    /** @var array<int,string>|null */
    private static ?array $names = null;

    public static function nameFor(int $code): ?string
    {
        $name = self::names()[$code] ?? null;
        if ($name === null) {
            return null;
        }
        $name = trim($name);

        return $name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH ? null : $name;
    }

    /** @return array<int,string> */
    private static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }
        $path = dirname(__DIR__, 5) . '/resources/payroll/cssz-workplaces/workplaces.json';
        $names = [];
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded) && is_array($decoded['workplaces'] ?? null)) {
            foreach ($decoded['workplaces'] as $code => $name) {
                if (is_string($name) && ctype_digit((string) $code)) {
                    $names[(int) $code] = $name;
                }
            }
        }

        return self::$names = $names;
    }
}
