<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Export\ClosingPackageService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nabídka částí uzávěrkového balíčku na klientovi musí odpovídat tomu, co server umí.
 *
 * Vzniklo z reálné vady: frontend v `ALL_PARTS` neměl `statement_notes`, přestože server
 * ji má i v `REQUIRED_PARTS`. Uživatel ji tedy nemohl vybrat, balíček ji nikdy
 * nevygeneroval — a protože se stav vyhodnocuje jen nad VYŽÁDANÝMI částmi, skončil jako
 * „hotovo". Závěrka přitom bez přílohy podle § 18/1 písm. c) ZoÚ úplná není.
 *
 * Sedí-li jen jeden ze seznamů, chyba je tichá: nic nespadne, jen v balíčku chybí
 * dokument. Proto kontrakt, ne komentář.
 */
#[Group('architecture')]
final class ClosingPackagePartsContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path, $relative . ' musí existovat.');

        return (string) file_get_contents($path);
    }

    /** Každou část, kterou server umí vygenerovat, musí jít na klientovi vybrat. */
    public function testFrontendOffersEveryPartTheServerCanBuild(): void
    {
        $page = $this->read('web/src/pages/accounting/ClosingPackage.vue');
        $types = $this->read('web/src/api/reports.ts');

        foreach (ClosingPackageService::ALL_PARTS as $part) {
            self::assertStringContainsString(
                "'" . $part . "'",
                $page,
                "Část „{$part}" . '" server umí, ale ve výběru na stránce balíčku není — nikdy se nevygeneruje.',
            );
            self::assertStringContainsString(
                "'" . $part . "'",
                $types,
                "Část „{$part}" . '" chybí v typu ClosingPackagePart.',
            );
        }
    }

    /** Povinné jádro musí být na obou stranách stejné, jinak si stav balíčku odporuje. */
    public function testRequiredPartsMatchBetweenServerAndClient(): void
    {
        $types = $this->read('web/src/api/reports.ts');

        foreach (ClosingPackageService::REQUIRED_PARTS as $part) {
            self::assertStringContainsString(
                "'" . $part . "'",
                $types,
                "Povinná část „{$part}" . '" chybí v CLOSING_PACKAGE_REQUIRED_PARTS na klientovi.',
            );
        }
    }

    /** Každá část musí mít český i anglický popisek — jinak se v UI vypíše syrový klíč. */
    public function testEveryPartHasALabelInBothLocales(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $json = $this->read("web/src/i18n/{$locale}.json");
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $labels = $data['accounting']['closing_package']['parts'] ?? [];
            self::assertIsArray($labels);

            foreach (ClosingPackageService::ALL_PARTS as $part) {
                self::assertArrayHasKey(
                    $part,
                    $labels,
                    "Část „{$part}" . "\" nemá popisek v {$locale}.json.",
                );
            }
        }
    }
}
