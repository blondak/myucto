<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class SupportMatrix
{
    public const VERSION = '2026-08-03-v2';
    private const SUPPORTED_YEARS = [2024, 2025, 2026];

    public function supportsYear(int $year): bool
    {
        return in_array($year, self::SUPPORTED_YEARS, true);
    }

    /**
     * Produktový rozsah a aktuální implementační dostupnost se vracejí odděleně.
     * UI tak nikdy nepředstírá, že je plánovaný právní scénář už bezpečně použitelný.
     *
     * @return array{
     *   version:string,
     *   supported_years:list<int>,
     *   employment_types:list<array{key:string,status:string,available:bool,min_epic:string}>,
     *   features:list<array{key:string,status:string,available:bool,min_epic:string}>
     * }
     */
    public function all(): array
    {
        return [
            'version' => self::VERSION,
            'supported_years' => self::SUPPORTED_YEARS,
            'employment_types' => [
                ['key' => 'hpp', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-05'],
                ['key' => 'dpp', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-05'],
                ['key' => 'dpc', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-05'],
                ['key' => 'statutory_body', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-05'],
                ['key' => 'foreign_regime', 'status' => 'manual_review', 'available' => false, 'min_epic' => 'MZ-10'],
            ],
            'features' => [
                ['key' => 'module_shell', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-00'],
                ['key' => 'activation', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-00'],
                ['key' => 'persons', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-04'],
                ['key' => 'employments', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-05'],
                ['key' => 'absences', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'average_earnings', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'leave_ledger', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'dpn_compensation', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'payroll_runs', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-09'],
                ['key' => 'payslips', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-16'],
                ['key' => 'automatic_posting', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-18'],
                ['key' => 'jmhz_export', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-22'],
                ['key' => 'health_insurer_export', 'status' => 'supported', 'available' => false, 'min_epic' => 'MZ-23'],
                ['key' => 'direct_submission', 'status' => 'not_supported', 'available' => false, 'min_epic' => 'MZ-27'],
            ],
        ];
    }
}
