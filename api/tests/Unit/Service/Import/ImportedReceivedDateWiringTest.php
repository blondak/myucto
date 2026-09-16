<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spustitelná brána proti návratu „datum přijetí = den importu".
 *
 * Nález byl v tom, že KAŽDÝ importní kanál si datum přijetí řešil sám: ISDOC ho bral
 * z dokladu, ale AI extrakce, iDoklad i Fakturoid psaly natvrdo `date('Y-m-d')`, přestože
 * datum vystavení měly v téže payloadě po ruce. Pravidlo teď žije v jedné třídě
 * ({@see \MyInvoice\Service\Import\ImportedReceivedDatePolicy}).
 *
 * Chování AI cesty se nedá levně odehrát end-to-end (privátní `createDraft()` za LLM
 * bránou), proto tahle brána hlídá aspoň ZAPOJENÍ na úrovni zdrojáku — jinak by se
 * hardcoded dnešek mohl tiše vrátit do kanálu, který nikdo netestuje.
 *
 * Bankovní výpis ({@see \MyInvoice\Action\Bank\BankStatementAction}) tu ZÁMĚRNĚ není:
 * doklad tam vzniká z pohybu na účtu, žádné datum vystavení ještě neexistuje a
 * `received_at` se rovná datu zaúčtování pohybu, kterým se plní i `issue_date`.
 */
final class ImportedReceivedDateWiringTest extends TestCase
{
    /** @return array<string, array{0:string}> */
    public static function importChannels(): array
    {
        return [
            'AI extrakce z PDF'      => ['AiPdfExtractor.php'],
            'ISDOC / Pohoda XML'     => ['IsdocToPurchaseInvoiceMapper.php'],
            'iDoklad API'            => ['IdokladImportService.php'],
            'Fakturoid API'          => ['FakturoidImportService.php'],
        ];
    }

    /** Každý kanál zakládající přijatý doklad musí datum přijetí brát ze sdílené politiky. */
    #[DataProvider('importChannels')]
    public function testChannelUsesSharedPolicy(string $file): void
    {
        self::assertStringContainsString(
            'ImportedReceivedDatePolicy::resolve(',
            self::source($file),
            $file . ' nesmí řešit datum přijetí po svém — pravidlo je sdílené.',
        );
    }

    /**
     * JÁDRO NÁLEZU: žádný importní kanál nesmí razítkovat datum přijetí dneškem.
     * Den importu je legitimní jen jako vědomá volba firmy, kterou vrací politika.
     */
    #[DataProvider('importChannels')]
    public function testChannelDoesNotStampToday(string $file): void
    {
        self::assertDoesNotMatchRegularExpression(
            "/'received_at'\s*=>\s*date\(/",
            self::source($file),
            $file . ': datum přijetí se nesmí plnit dnem importu natvrdo.',
        );
    }

    /**
     * Importovaný doklad si musí držet `received_at_source = 'import'`. Jen díky tomu
     * {@see \MyInvoice\Service\Report\VatLedgerService} NEposune období nároku na odpočet
     * (§ 73 odst. 1 písm. a) — kdyby sem spadlo 'manual', změna evidenčního údaje by
     * tiše přepsala daňové období dokladu.
     */
    #[DataProvider('importChannels')]
    public function testChannelKeepsImportSource(string $file): void
    {
        self::assertMatchesRegularExpression(
            "/'received_at_source'\s*=>\s*'import'/",
            self::source($file),
            $file . ": importovaný doklad nesmí být označen jako vědomé zadání účetní.",
        );
    }

    private static function source(string $file): string
    {
        $path = dirname(__DIR__, 4) . '/src/Service/Import/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
