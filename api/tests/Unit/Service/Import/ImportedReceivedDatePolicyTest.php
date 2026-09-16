<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportedReceivedDatePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Datum přijetí u importovaného přijatého dokladu (migrace 1848).
 *
 * Zákaznický nález: „Pro import přijatých pdf dokladů jsme použili AI import, ovšem do
 * pole Datum přijetí se nám vkládá aktuální datum importu." Pravidlo teď žije v jedné
 * třídě, kterou volají VŠECHNY importní kanály, takže se nemůžou rozejít.
 *
 * Testy jsou pure-logic — `$today` se předává, takže nic nezávisí na dni běhu.
 */
final class ImportedReceivedDatePolicyTest extends TestCase
{
    private const TODAY = '2096-09-16';

    /** Výchozí režim bere datum vystavení dokladu, ne dnešek. */
    public function testDocumentModeUsesIssueDate(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            '2096-06-30',
            '2096-06-15',
            self::TODAY,
        );

        self::assertSame('2096-06-30', $result['date']);
        self::assertFalse($result['fell_back']);
    }

    /**
     * Vystavení má přednost před DUZP. DUZP bývá DŘÍV (plnění 30. 6., doklad vystavený
     * 2. 7.) a doklad nelze držet dřív, než vůbec vznikl — tohle je rozdíl proti
     * původnímu chování ISDOC mapperu, který preferoval DUZP.
     */
    public function testIssueDateWinsOverEarlierTaxDate(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            '2096-07-02',
            '2096-06-30',
            self::TODAY,
        );

        self::assertSame('2096-07-02', $result['date']);
    }

    /** Přepínač zpět na dosavadní chování: datum přijetí = den importu. */
    public function testImportDayModeReturnsToday(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_IMPORT_DAY,
            '2096-06-30',
            '2096-06-15',
            self::TODAY,
        );

        self::assertSame(self::TODAY, $result['date']);
        self::assertFalse($result['fell_back'], 'Vědomá volba firmy není náhradní řešení.');
    }

    /**
     * Doklad bez čitelného data vystavení spadne na DUZP — pořád je to údaj Z DOKLADU,
     * takže to není `fell_back`.
     */
    public function testUnreadableIssueDateFallsBackToTaxDate(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            '',
            '2096-06-15',
            self::TODAY,
        );

        self::assertSame('2096-06-15', $result['date']);
        self::assertFalse($result['fell_back']);
    }

    /**
     * ŽÁDNÉ čitelné datum (nečitelné PDF, ISDOC bez IssueDate) → zbyde dnešek, ale
     * MUSÍ to být vidět. Bez příznaku by uživatel nepoznal, že jde o náhradu.
     */
    #[DataProvider('unreadableDates')]
    public function testNoReadableDateFallsBackToTodayVisibly(mixed $issue, mixed $tax): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            $issue,
            $tax,
            self::TODAY,
        );

        self::assertSame(self::TODAY, $result['date']);
        self::assertTrue($result['fell_back']);
        self::assertNotSame('', ImportedReceivedDatePolicy::fallbackWarning());
    }

    /** @return array<string, array{0:mixed, 1:mixed}> */
    public static function unreadableDates(): array
    {
        return [
            'obojí null'        => [null, null],
            'prázdné řetězce'   => ['', ''],
            'nesmysl'           => ['neuvedeno', 'N/A'],
            'špatný formát'     => ['30.06.2096', '2096/06/30'],
            'pole místo data'   => [['2096-06-30'], null],
        ];
    }

    /** Budoucí datum na dokladu (překlep dodavatele) se nedosazuje — ořez na dnešek. */
    public function testFutureIssueDateIsClampedToToday(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            '2099-01-01',
            null,
            self::TODAY,
        );

        self::assertSame(self::TODAY, $result['date']);
        self::assertFalse($result['fell_back'], 'Doklad datum nesl, jen bylo mimo — není to náhrada.');
    }

    /** Datum s časem (ISDOC nese občas timestamp) se ořízne na den. */
    public function testDateTimeIsTruncatedToDay(): void
    {
        $result = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            '2096-06-30T14:25:00',
            null,
            self::TODAY,
        );

        self::assertSame('2096-06-30', $result['date']);
    }

    /** Neznámý režim (poškozená hodnota v DB) se chová jako výchozí, ne jako dnešek. */
    public function testUnknownModeBehavesAsDefault(): void
    {
        $result = ImportedReceivedDatePolicy::resolve('nonsense', '2096-06-30', null, self::TODAY);

        self::assertSame('2096-06-30', $result['date']);
    }

    public function testDefaultModeIsDocumentDate(): void
    {
        self::assertSame(
            ImportedReceivedDatePolicy::MODE_DOCUMENT,
            ImportedReceivedDatePolicy::DEFAULT_MODE,
        );
        self::assertSame(
            ['issue_date', 'import_date'],
            ImportedReceivedDatePolicy::MODES,
        );
    }
}
