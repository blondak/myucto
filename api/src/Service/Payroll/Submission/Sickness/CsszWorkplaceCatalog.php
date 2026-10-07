<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Číselník pracovišť ČSSZ `C_COKR` (v XSD dokumentaci `CIS_PRACOVIST`):
 * `dokument/kodOSSZ` v NEMPRI25 i HZUPN20 a název pro HZUPN
 * `dokument/nazevOSSZ` (nejvýš 30 znaků).
 *
 * Kód mimo číselník projde XSD (jen tři číslice) a odmítne ho až územní
 * správa chybou 06. Kód 101 (ústředí) v číselníku je, ale pro e-podání se
 * nepoužívá. Název se nikdy nevymýšlí: kód mimo číselník vrací `null`
 * a prvek se do věty nevypíše (v XSD je nepovinný).
 */
final class CsszWorkplaceCatalog
{
    public const MAX_NAME_LENGTH = 30;

    /** @var array{names:array<int,string>,excluded:list<int>}|null */
    private static ?array $catalog = null;

    public static function nameFor(int $code): ?string
    {
        $name = self::catalog()['names'][$code] ?? null;
        if ($name === null) {
            return null;
        }
        $name = trim($name);

        return $name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH ? null : $name;
    }

    /**
     * Smí kód nést e-podání? Jen kód z číselníku, který ČSSZ pro e-podání
     * nevylučuje.
     */
    public static function acceptsSubmission(int $code): bool
    {
        $catalog = self::catalog();

        return isset($catalog['names'][$code]) && !in_array($code, $catalog['excluded'], true);
    }

    /** @return array{names:array<int,string>,excluded:list<int>} */
    private static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $path = dirname(__DIR__, 5) . '/resources/payroll/cssz-workplaces/workplaces.json';
        $names = [];
        $excluded = [];
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded) && is_array($decoded['workplaces'] ?? null)) {
            foreach ($decoded['workplaces'] as $code => $name) {
                if (is_string($name) && ctype_digit((string) $code)) {
                    $names[(int) $code] = $name;
                }
            }
            foreach ($decoded['not_for_epodani'] ?? [] as $code) {
                if (is_int($code)) {
                    $excluded[] = $code;
                }
            }
        }
        if ($names === []) {
            throw new \RuntimeException('Číselník pracovišť ČSSZ (workplaces.json) chybí nebo je prázdný.');
        }

        return self::$catalog = ['names' => $names, 'excluded' => $excluded];
    }
}
