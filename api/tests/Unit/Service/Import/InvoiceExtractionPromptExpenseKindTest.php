<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * §DM „AI import" — kontrakt extrakčního promptu pro `expense_kind`.
 *
 * PIN na poučení z `a013406f`: promptu se tehdy posílaly holé kódy účtů bez názvů a model si
 * význam domyslel (u účtu 065 si vymyslel „Pojistné", ačkoli to jsou Dluhové cenné papíry).
 * Tady je analogie ta, že enum hodnoty jsou anglické tokeny — „small_asset" ani „material"
 * samy o sobě NEŘÍKAJÍ, že PHM je materiál a ne drobný majetek. Význam proto musí být
 * v promptu vypsaný; jinak se ta chyba jen zopakuje na jiném poli.
 */
final class InvoiceExtractionPromptExpenseKindTest extends TestCase
{
    public function testSystemPromptDeclaresExpenseKindOnItems(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        self::assertStringContainsString('"expense_kind"', $prompt);
        self::assertStringContainsString('"expense_kind_confidence"', $prompt);
        self::assertStringContainsString('"expense_kind_reasoning"', $prompt);
    }

    /** Každá hodnota enumu musí mít v promptu český název i význam, ne jen holý token. */
    public function testEveryExpenseKindValueShipsWithNameAndMeaning(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        $expected = [
            'service' => 'Služba',
            'material' => 'Spotřební materiál',
            'small_asset' => 'Drobný majetek',
            'fixed_asset' => 'Dlouhodobý majetek',
        ];
        foreach ($expected as $value => $name) {
            self::assertMatchesRegularExpression(
                '/"' . preg_quote($value, '/') . '"\s*=\s*' . preg_quote($name, '/') . '/u',
                $prompt,
                "Hodnota „{$value}" . '" musí mít v promptu vypsaný název i význam, ne jen token.',
            );
        }
    }

    /** Past z reálných dat: účetní vede PHM na 501.100 (materiál), NE na 501.200. */
    public function testPromptPinsFuelToMaterial(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        self::assertStringContainsString('PHM je "material", NIKDY "small_asset"', $prompt);
    }

    /** Dodavatel sám nestačí — Alza prodá notebook i dopravu na jedné faktuře. */
    public function testPromptPinsPerLineClassificationNotPerVendor(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        self::assertStringContainsString('ROZHODUJE POVAHA ŘÁDKU, NE DODAVATEL', $prompt);
        self::assertStringContainsString('NIKDY to není "small_asset"', $prompt);
    }

    /** „Radši null než tip naslepo" — bez tohohle model tipuje vždy. */
    public function testPromptInstructsNullWhenUnsure(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        self::assertStringContainsString('expense_kind: null', $prompt);
        self::assertStringContainsString('neúčtuje automaticky', $prompt);
    }

    public function testJsonSchemaConstrainsExpenseKindToEnum(): void
    {
        $item = InvoiceExtractionPrompt::invoiceJsonSchema()['properties']['items']['items']['properties'];

        self::assertSame(
            ['service', 'material', 'small_asset', 'fixed_asset', null],
            $item['expense_kind']['enum'],
        );
        self::assertSame(['string', 'null'], $item['expense_kind']['type']);
        self::assertSame(0, $item['expense_kind_confidence']['minimum']);
        self::assertSame(1, $item['expense_kind_confidence']['maximum']);
        self::assertSame(300, $item['expense_kind_reasoning']['maxLength']);
    }

    /**
     * InvoiceExtractionPrompt se sám deklaruje jako 1:1 zrcadlo inline promptu v
     * AnthropicClientu. Zrcadlo, které se rozejde, je horší než žádné — u Anthropicu (výchozí
     * provider) by se `expense_kind` tiše nevracel a §DM by přes AI import nefungoval.
     */
    public function testAnthropicInlinePromptMirrorsExpenseKindRules(): void
    {
        $file = (new ReflectionClass(\MyInvoice\Service\Import\AnthropicClient::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertStringContainsString('"expense_kind"', $source);
        self::assertStringContainsString('"expense_kind_confidence"', $source);
        self::assertStringContainsString('"expense_kind_reasoning"', $source);
        self::assertStringContainsString('ROZHODUJE POVAHA ŘÁDKU, NE DODAVATEL', $source);
        foreach (['service', 'material', 'small_asset', 'fixed_asset'] as $value) {
            self::assertStringContainsString('`"' . $value . '"`', $source);
        }
    }
}
