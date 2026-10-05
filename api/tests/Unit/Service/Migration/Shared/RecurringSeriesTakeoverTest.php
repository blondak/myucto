<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use DateTimeImmutable;
use MyInvoice\Service\Invoice\DescriptionPlaceholders;
use MyInvoice\Service\Migration\Shared\RecurringSeriesTakeover;
use PHPUnit\Framework\TestCase;

final class RecurringSeriesTakeoverTest extends TestCase
{
    private const MONTHS = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    /** Nájem vystavovaný 2. den měsíce na následující měsíc. */
    private static function rent(int $clientId, string $issue, float $price, string $client = 'Nájemce s.r.o.'): array
    {
        $next = (new DateTimeImmutable($issue))->modify('first day of next month');
        return [
            'client_id' => $clientId, 'client_name' => $client, 'project_id' => null,
            'issue_date' => $issue, 'tax_date' => $issue,
            'due_date' => (new DateTimeImmutable($issue))->modify('+18 days')->format('Y-m-d'),
            'currency_id' => 1, 'language' => 'cs', 'payment_method' => 'bank_transfer',
            'reverse_charge' => 0, 'prices_include_vat' => 0, 'discount_percent' => 0,
            'items' => [[
                'description' => 'Nájem na měsíc ' . self::MONTHS[(int) $next->format('n')] . ' ' . $next->format('Y') . ' + zabezpečení',
                'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => $price, 'vat_rate_id' => 1,
                'vat_classification_code' => null, 'revenue_account_code' => null,
            ]],
        ];
    }

    private static function services(int $clientId, string $issue, float $price): array
    {
        $inv = self::rent($clientId, $issue, $price);
        $inv['items'][0]['description'] = 'Vyúčtování služeb za měsíc ' . self::MONTHS[(int) substr($issue, 5, 2)] . ' ' . substr($issue, 0, 4);
        return $inv;
    }

    public function testMonthlyRentBecomesPausedTemplateWithShiftedPlaceholders(): void
    {
        $invoices = [];
        foreach (['2026-07-02', '2026-08-02', '2026-09-02', '2026-10-02'] as $d) {
            $invoices[] = self::rent(7, $d, 20300.0);
        }
        $series = RecurringSeriesTakeover::detect($invoices, new DateTimeImmutable('2026-10-02'));

        self::assertCount(1, $series);
        self::assertSame(1, $series[0]['months']);
        self::assertSame(4, $series[0]['count']);
        $t = RecurringSeriesTakeover::template($series[0]);
        self::assertSame('monthly', $t['frequency']);
        self::assertSame('paused', $t['status']);
        self::assertFalse($t['auto_issue']);
        self::assertFalse($t['auto_send_email']);
        self::assertSame('2026-11-02', $t['next_run_date']);
        self::assertSame(2, $t['day_of_month']);
        self::assertSame(18, $t['payment_due_days']);
        self::assertSame('Nájem na měsíc {MMMM+1} {YYYY+1M} + zabezpečení', $t['items'][0]['description']);
        // Generátor ze šablony v listopadu fakturuje prosinec, v prosinci leden dalšího roku.
        self::assertSame('Nájem na měsíc prosinec 2026 + zabezpečení', DescriptionPlaceholders::apply($t['items'][0]['description'], new DateTimeImmutable('2026-11-02')));
        self::assertSame('Nájem na měsíc leden 2027 + zabezpečení', DescriptionPlaceholders::apply($t['items'][0]['description'], new DateTimeImmutable('2026-12-02')));
    }

    public function testVariableAmountsEndedSeriesAndShortSeriesAreSkipped(): void
    {
        $invoices = [
            // Vyúčtování služeb — měsíčně, ale pokaždé jiná částka.
            self::services(1, '2026-07-16', 812.5), self::services(1, '2026-08-16', 640.1), self::services(1, '2026-09-16', 733.0),
            // Nájem, který skončil v květnu.
            self::rent(2, '2026-03-02', 5000.0), self::rent(2, '2026-04-02', 5000.0), self::rent(2, '2026-05-02', 5000.0),
            // Jen dva měsíční doklady.
            self::rent(3, '2026-09-02', 9000.0), self::rent(3, '2026-10-02', 9000.0),
        ];

        self::assertSame([], RecurringSeriesTakeover::detect($invoices, new DateTimeImmutable('2026-10-02')));
    }

    public function testPeriodComesFromTextNotIssueDate(): void
    {
        // Dva nájmy vystavené v jednom měsíci (dobíhání) — řada podle období v textu drží.
        $invoices = [
            self::rent(5, '2026-06-02', 1000.0),
            self::rent(5, '2026-07-02', 1000.0),
            self::rent(5, '2026-07-30', 1000.0),
            self::rent(5, '2026-09-02', 1000.0),
        ];
        $invoices[2]['items'][0]['description'] = 'Nájem na měsíc září 2026 + zabezpečení';
        $invoices[3]['items'][0]['description'] = 'Nájem na měsíc říjen 2026 + zabezpečení';

        $series = RecurringSeriesTakeover::detect($invoices, new DateTimeImmutable('2026-09-10'));

        self::assertCount(1, $series);
        self::assertSame(4, $series[0]['count']);
    }

    public function testQuarterlyRomanQuarter(): void
    {
        $invoices = [];
        foreach (['2026-01-02' => 'I.', '2026-04-02' => 'II.', '2026-07-02' => 'III.', '2026-10-02' => 'IV.'] as $d => $q) {
            $inv = self::rent(9, $d, 1935.0);
            $inv['items'][0]['description'] = "Nájem za {$q} čtvrtletí 2026";
            $invoices[] = $inv;
        }
        $series = RecurringSeriesTakeover::detect($invoices, new DateTimeImmutable('2026-10-02'));

        self::assertCount(1, $series);
        $t = RecurringSeriesTakeover::template($series[0]);
        self::assertSame('quarterly', $t['frequency']);
        self::assertSame('2027-01-02', $t['next_run_date']);
        self::assertSame('Nájem za {Q}. čtvrtletí {YYYY}', $t['items'][0]['description']);
        self::assertSame('Nájem za 1. čtvrtletí 2027', DescriptionPlaceholders::apply($t['items'][0]['description'], new DateTimeImmutable('2027-01-02')));
    }

    public function testTemplatizeNumericPeriodsAndYears(): void
    {
        $ref = new DateTimeImmutable('2026-03-17');
        $text = RecurringSeriesTakeover::templatize('Nájem 3/2026 - 2/2027, pojištění 2026, čp. 4062/3a, splatno 1.4.2026', $ref);

        self::assertSame('Nájem {M}/{YYYY} - {M+11}/{YYYY+11M}, pojištění {YYYY}, čp. 4062/3a, splatno 1.4.2026', $text);
        self::assertSame('Nájem 3/2027 - 2/2028, pojištění 2027, čp. 4062/3a, splatno 1.4.2026', DescriptionPlaceholders::apply($text, new DateTimeImmutable('2027-03-17')));
    }
}
