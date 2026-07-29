<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `AGENTS.md` → i18n: „Vždy doplň OBĚ locale (cs.json i en.json)."
 *
 * Pravidlo tam bylo odjakživa, spustitelný guard k němu ale ne — a rozešlo se to.
 * Při zavedení guardu chybělo v angličtině 18 klíčů, z toho 9 kvůli tomu, že celý
 * blok `bank_payment` + `payment_provenance` seděl v `en.json` pod `invoice.`,
 * zatímco kód (i čeština) používá `purchase_invoice.`. Anglickému uživateli se na
 * detailu přijaté faktury zobrazovaly syrové klíče.
 *
 * Přesně tenhle druh chyby projde review i typovou kontrolou: JSON je validní,
 * `vue-tsc` mlčí, chybějící překlad se pozná až v běžícím UI v druhém jazyce.
 *
 * Guard porovnává MNOŽINY CEST, ne hodnoty — překlad se pochopitelně liší, ale
 * struktura musí být identická v obou souborech.
 */
final class LocaleParityGuardTest extends TestCase
{
    private const LOCALES = ['cs', 'en'];

    public function testBothLocalesHaveIdenticalKeySets(): void
    {
        $keys = [];
        foreach (self::LOCALES as $locale) {
            $keys[$locale] = $this->flatten($this->load($locale));
        }

        $missingInEn = array_keys(array_diff_key($keys['cs'], $keys['en']));
        $missingInCs = array_keys(array_diff_key($keys['en'], $keys['cs']));

        self::assertSame([], $missingInEn, $this->message('en.json', $missingInEn));
        self::assertSame([], $missingInCs, $this->message('cs.json', $missingInCs));
    }

    /**
     * Pojistka proti tomu, že guard zezelená kvůli rozbitému JSONu (oba načtou
     * prázdno → množiny se „shodují"). Počet klíčů je řádově tisíce.
     */
    public function testLocaleFilesAreNonTrivial(): void
    {
        foreach (self::LOCALES as $locale) {
            self::assertGreaterThan(
                1000,
                count($this->flatten($this->load($locale))),
                $locale . '.json má podezřele málo klíčů — načetl se celý?',
            );
        }
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/web/src/i18n/' . $locale . '.json';
        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, $locale . '.json není validní JSON: ' . json_last_error_msg());

        return $data;
    }

    /**
     * Tečkové cesty ke všem listům. Seznamy (`tm()`/`rt()`) se berou jako JEDEN list —
     * jejich délka se mezi jazyky legitimně lišit nemá, ale hlídat indexy by tu jen
     * přidávalo šum.
     *
     * @param array<string, mixed> $data
     * @return array<string, true>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = true;
            }
        }

        return $out;
    }

    /** @param list<string> $missing */
    private function message(string $file, array $missing): string
    {
        return sprintf(
            "V %s chybí %d klíč(ů) — doplň překlad, nebo klíč smaž z druhé locale:\n  %s",
            $file,
            count($missing),
            implode("\n  ", array_slice($missing, 0, 30)),
        );
    }
}
