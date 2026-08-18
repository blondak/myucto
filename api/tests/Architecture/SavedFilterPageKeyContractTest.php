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
        $used = self::frontendPageKeys('useSavedFilters');

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
     * Stejný whitelist platí i pro `table.<page_key>` preference sloupců a hustoty:
     * {@see \MyInvoice\Action\UserSettings\UserPreferenceAction::validPrefKey()} bere
     * povolené klíče z téhož `PAGE_KEYS`.
     *
     * Bez tohohle testu se to rozešlo tiše: `useTablePrefs('payroll-documents')`
     * a další mzdové stránky ve whitelistu nebyly, takže PUT preference vracel 422
     * a výběr sloupců i hustota se po reloadu vždy vrátily na výchozí. Na obrazovce
     * to nevypadá jako chyba, jen jako by si aplikace nic nepamatovala.
     */
    public function testEveryFrontendTablePrefsPageKeyIsWhitelisted(): void
    {
        $used = self::frontendPageKeys('useTablePrefs');

        self::assertNotEmpty(
            $used,
            'Ve web/src se nenašlo ani jedno volání useTablePrefs — sken je rozbitý, ne kód.'
        );

        foreach ($used as $key => $files) {
            self::assertContains(
                $key,
                SavedFilterAction::PAGE_KEYS,
                sprintf(
                    "page_key '%s' (%s) chybí v SavedFilterAction::PAGE_KEYS — uložení nastavení "
                        . 'sloupců na té stránce skončí na 422 invalid_pref_key.',
                    $key,
                    implode(', ', $files),
                ),
            );
        }
    }

    /**
     * Opačný směr, ale jen pro mzdové klíče (`payroll-*`).
     *
     * Globálně opačný směr netestovatelný je — whitelist legitimně obsahuje
     * klíče stránek, které teprve vznikají, a klíče používané odjinud než
     * z `pages/`. Mzdová sekce je ale uzavřená: každý její klíč tu vznikl
     * kvůli konkrétní obrazovce. Osiřelý klíč proto znamená, že se stránka
     * přejmenovala nebo zanikla, a uživatelům té obrazovky se tiše přestaly
     * načítat uložené předvolby — na obrazovce to nevypadá jako chyba, jen
     * jako by si aplikace nic nepamatovala. Přesně to je důvod pro bránu.
     */
    public function testEveryPayrollWhitelistedPageKeyIsUsedByFrontend(): void
    {
        $used = self::frontendPageKeys('useTablePrefs')
            + self::frontendPageKeys('useSavedFilters');

        $payrollKeys = array_values(array_filter(
            SavedFilterAction::PAGE_KEYS,
            static fn (string $key): bool => str_starts_with($key, 'payroll-'),
        ));
        self::assertNotEmpty($payrollKeys, 'Ve whitelistu nezbyl žádný mzdový page_key.');

        foreach ($payrollKeys as $key) {
            self::assertArrayHasKey(
                $key,
                $used,
                sprintf(
                    "Mzdový page_key '%s' je ve whitelistu, ale žádná stránka ve web/src ho "
                        . 'nepoužívá — buď osiřel po přejmenování obrazovky (a uživatelé té '
                        . 'obrazovky tiše přišli o uložené sloupce a hustotu), nebo ho někdo '
                        . 'zaregistroval dopředu a obrazovku nedodělal.',
                    $key,
                ),
            );
        }
    }

    /**
     * Literály z `<composable>('<key>', …)` napříč web/src.
     *
     * @return array<string, list<string>> page_key => soubory, které ho používají
     */
    private static function frontendPageKeys(string $composable): array
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
            if ($src === false || !str_contains($src, $composable)) {
                continue;
            }
            // Jen literálový první argument; dynamický klíč by stejně nešlo staticky ověřit.
            if (preg_match_all('/' . preg_quote($composable, '/') . "\(\s*'([^']+)'/", $src, $m) === 0) {
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
