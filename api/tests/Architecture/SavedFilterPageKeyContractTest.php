<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\UserSettings\SavedFilterAction;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt page_key mezi frontendem a backendem (static source scan).
 *
 * SavedFilterAction::PAGE_KEYS je jediný zdroj pravdy a jeho docblock říká, že FE
 * literály musí sedět 1:1. Nikdo to ale nekontroloval, takže stránky skladu volaly
 * useSavedFilters('stock-items') / ('stock-documents') s klíčem, který ve whitelistu
 * nebyl — uložení pohledu tam vracelo 422 a nefungovalo od zavedení funkce.
 *
 * Rozejít se to může jen tiše: FE dostane 422 až za běhu, na obrazovce se to projeví
 * jako „uložení selhalo" bez vazby na příčinu. Proto guard, ne code review.
 *
 * Opačný směr (klíč ve whitelistu, který FE nepoužívá) je legitimní — stránka může
 * teprve vznikat nebo uložené filtry používat odjinud než z pages/ — a netestuje se.
 */
final class SavedFilterPageKeyContractTest extends TestCase
{
    public function testEveryFrontendPageKeyIsWhitelisted(): void
    {
        $used = self::frontendPageKeys();

        self::assertNotEmpty(
            $used,
            'Ve web/src se nenašlo ani jedno volání useSavedFilters — sken je rozbitý, ne kód.'
        );

        foreach ($used as $key => $files) {
            self::assertContains(
                $key,
                SavedFilterAction::PAGE_KEYS,
                sprintf(
                    "page_key '%s' (%s) chybí v SavedFilterAction::PAGE_KEYS — uložení filtru na té "
                        . 'stránce skončí na 422 invalid_page_key.',
                    $key,
                    implode(', ', $files),
                ),
            );
        }
    }

    /**
     * Literály z useSavedFilters('<key>', …) napříč web/src.
     *
     * @return array<string, list<string>> page_key => soubory, které ho používají
     */
    private static function frontendPageKeys(): array
    {
        $root = dirname(__DIR__, 3) . '/web/src';
        if (!is_dir($root)) {
            self::markTestSkipped('web/src není k dispozici (backend-only checkout).');
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if ($src === false || !str_contains($src, 'useSavedFilters')) {
                continue;
            }
            // Jen literálový první argument; dynamický klíč by stejně nešlo staticky ověřit.
            if (preg_match_all("/useSavedFilters\(\s*'([^']+)'/", $src, $m) === 0) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($root, 2)) + 1));
            foreach ($m[1] as $key) {
                $out[$key][] = $rel;
                $out[$key] = array_values(array_unique($out[$key]));
            }
        }

        return $out;
    }
}
