<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Daňová konstanta, kterou nikdo nečte, je MRTVÝ KNOFLÍK.
 *
 * Číselník daňových konstant je editovatelný v adminu a ročníkově verzovaný. Když ale
 * hodnotu nikdo nečte, admin ji upraví, nic se nestane — a nikdo se to nedozví.
 * Přesně tak vypadal `spouse_income_limit` (§ 35ba odst. 1 písm. b): konstanta
 * existovala, byla v UI, a `PreFinalizeCheckService` porovnával s literálem `68000`.
 * Novelizace limitu by se neprojevila, aniž by to kdokoli poznal.
 *
 * Pro DPH, kurzy i RBAC guardy existovaly, pro daň z příjmů ne — matice F4 to označila
 * za nejlevnější zlepšení z celého dokumentu. Tohle je ono.
 *
 * Guard NEKONTROLUJE, že se konstanta používá SPRÁVNĚ — jen že se vůbec čte. To je
 * levná, mechanická kontrola, která chytí celou třídu „hodnota bez konzumenta".
 */
final class TaxConstantsUsageGuardTest extends TestCase
{
    /**
     * Klíče, které konzumenta záměrně nemají. Každý s důvodem — bez něj je to
     * pravděpodobně chyba, ne výjimka.
     *
     * @var array<string, string>
     */
    private const ALLOWED_UNUSED = [
        // Držíme kvůli úplnosti ročníkové sady a čitelnosti odvození sousedních hodnot;
        // vlastního konzumenta nemá, protože se z něj jen odvozovaly předpočítané limity.
        'social_assessment_pct' => 'vstup do předpočítaných min./max. VZ, ne runtime hodnota',
        'health_assessment_pct' => 'vstup do předpočítaných min./max. VZ, ne runtime hodnota',

        // §15/3-4: hranice 31. 12. 2020 pro strop úroků 300 000 vs 150 000 Kč. Profil
        // poplatníka nese jen BOOLEAN `mortgage_pre_2021`, ne datum obstarání bytové
        // potřeby — není tedy co s datem porovnávat a konstanta je jen čitelná kotva
        // významu toho booleanu. Skutečný konzument vznikne, až profil ponese datum;
        // vedeno jako díra v MATICE-DANE-PRIJMU.md.
        'mortgage_pre2021_cutoff' => 'profil nese boolean, ne datum obstarání — není co porovnat',
    ];

    /**
     * Roky, jejichž sady se kontrolují. Nová ročníková sada sem patří taky — jinak by
     * v ní mohl přibýt klíč, který nikdo nečte, a guard by mlčel.
     *
     * @var list<int>
     */
    private const YEARS = [2024, 2025, 2026];

    public function testEveryTaxConstantHasAConsumer(): void
    {
        $sources = $this->sourceCorpus();
        $dead = [];

        foreach (self::YEARS as $year) {
            foreach (array_keys(TaxConstants::forYear($year)) as $key) {
                if (isset(self::ALLOWED_UNUSED[$key]) || isset($dead[$key])) {
                    continue;
                }
                // Klíč se čte jako řetězec: $c['klic'], ?? $c['klic'], 'klic' => …
                if (!str_contains($sources, "'" . $key . "'") && !str_contains($sources, '"' . $key . '"')) {
                    $dead[$key] = $year;
                }
            }
        }

        $lines = [];
        foreach ($dead as $key => $year) {
            $lines[] = sprintf("%s (poprvé v sadě %d)", $key, $year);
        }

        self::assertSame([], $lines, sprintf(
            "Daňová konstanta bez konzumenta — admin ji může měnit a nic se nestane:\n  %s\n\n"
                . "Buď ji začni číst tam, kde patří, nebo (je-li to záměr) doplň ji do\n"
                . 'ALLOWED_UNUSED s důvodem. Přesně takhle byl mrtvý `spouse_income_limit`.',
            implode("\n  ", $lines),
        ));
    }

    /**
     * Allowlist se nesmí rozejít se sadou konstant — záznam na klíč, který už
     * neexistuje, kryje neexistující výjimku a mate příštího čtenáře.
     */
    public function testAllowlistHasNoStaleKeys(): void
    {
        $known = [];
        foreach (self::YEARS as $year) {
            foreach (array_keys(TaxConstants::forYear($year)) as $key) {
                $known[$key] = true;
            }
        }

        $stale = array_values(array_filter(
            array_keys(self::ALLOWED_UNUSED),
            static fn (string $key): bool => !isset($known[$key]),
        ));

        self::assertSame([], $stale, sprintf(
            "Zastaralý záznam v ALLOWED_UNUSED — klíč v konstantách není: %s",
            implode(', ', $stale),
        ));
    }

    /**
     * Frontend nesmí mít daňové limity natvrdo — jsou ROČNÍKOVÉ.
     *
     * `Stats.vue` měl registrační limity § 4a zapsané jako `2_000_000` a `2_536_500`,
     * takže nad daty roku 2024 ukazoval práh, který v roce 2024 neplatil (do 2024
     * existoval jediný limit 2 000 000 Kč). Hodnoty chodí z API; guard hlídá, že se
     * literály nevrátí.
     *
     * Fallback pro staré API je povolený, ale musí být v jednom `??` bloku vedle
     * `vat_registration_limits` — ne roztroušený po komponentě.
     */
    public function testFrontendDoesNotHardcodeTaxLimits(): void
    {
        $webSrc = dirname(__DIR__, 3) . '/web/src';
        self::assertDirectoryExists($webSrc, 'web/src zmizel — guard by nekontroloval nic.');

        // Číselné literály, které patří výhradně do TaxConstants.
        $forbidden = ['2_000_000', '2_536_500', '2000000', '2536500'];

        // Soubory, kde totéž číslo znamená NĚCO JINÉHO. `AssetEditor` používá 2 000 000
        // jako limit odpisů vozidla M1 (§ 30e ZDP, konstanta `m1_depreciation_limit`),
        // ne jako registrační limit DPH — shoda hodnoty je náhoda. Je to ale taky
        // literál, který patří do konstant; vedeno jako samostatná díra v matici,
        // protože oprava potřebuje vlastní API plochu pro konstanty majetku.
        $differentMeaning = ['pages/accounting/AssetEditor.vue'];

        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($webSrc, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()
                || !in_array($file->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($webSrc) + 1));
            if (in_array($rel, $differentMeaning, true)) {
                continue;
            }
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                // Komentáře i fallback vedle `vat_registration_limits` jsou v pořádku.
                // Komentáře jsou v pořádku. Stejně tak řádek, který hodnotu ČTE
                // z konstant a literál nese jen jako fallback pro staré API —
                // poznáme ho podle názvu klíče na témž řádku.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')
                    || str_contains($line, 'vat_registration_limits')
                    || str_contains($line, 'vat_limit_low')
                    || str_contains($line, 'vat_limit_high')) {
                    continue;
                }
                foreach ($forbidden as $needle) {
                    if (str_contains($line, $needle) && !$this->isInFallbackBlock($lines, $i)) {
                        $offenders[] = sprintf('web/src/%s:%d — %s', $rel, $i + 1, trim($line));
                        break;
                    }
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Registrační limit DPH natvrdo na frontendu:\n  %s\n\n"
                . 'Ber ho ze `summary.vat_registration_limits` — limity jsou ročníkové '
                . 'a natvrdo zapsané lžou nad staršími daty.',
            implode("\n  ", $offenders),
        ));
    }

    /** Je řádek součástí fallback bloku bezprostředně pod `vat_registration_limits`? */
    private function isInFallbackBlock(array $lines, int $index): bool
    {
        for ($i = max(0, $index - 5); $i < $index; $i++) {
            if (str_contains((string) $lines[$i], 'vat_registration_limits')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guard musí mít co hlídat: kdyby se `forYear()` rozbilo a vracelo prázdno,
     * test výše by prošel a tvrdil, že je hlídáno něco, co hlídané není.
     */
    public function testConstantSetsAreNonTrivial(): void
    {
        foreach (self::YEARS as $year) {
            self::assertGreaterThan(
                30,
                count(TaxConstants::forYear($year)),
                "Sada konstant pro {$year} je podezřele malá — guard by nekontroloval nic.",
            );
        }
    }

    /**
     * Soubory, které klíč jen VYJMENOVÁVAJÍ, ale nečtou jeho hodnotu pro výpočet.
     * Do korpusu nepatří:
     *
     *   - `TaxConstants.php` sám sebe (definice — jinak se každý klíč najde sám v sobě),
     *   - `TaxConstantsAction.php` = admin editor. Ten je ZAPISOVATEL, ne konzument;
     *     kdyby se počítal, byl by mrtvý knoflík vždycky „používaný" právě tím
     *     knoflíkem, který nic nedělá. Přesně to by zamaskovalo `spouse_child_max_age`.
     *
     * @var list<string>
     */
    private const NOT_A_CONSUMER = [
        'Service/Tax/TaxConstants.php',
        'Action/Codebook/TaxConstantsAction.php',
    ];

    /**
     * Zdrojový korpus BEZ míst, která klíče jen vyjmenovávají (viz NOT_A_CONSUMER).
     */
    private function sourceCorpus(): string
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $skip = [];
        foreach (self::NOT_A_CONSUMER as $rel) {
            $skip[str_replace('\\', '/', $srcDir . '/' . $rel)] = true;
        }

        $buffer = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (isset($skip[str_replace('\\', '/', $file->getPathname())])) {
                continue;
            }
            $buffer .= file_get_contents($file->getPathname());
        }

        self::assertNotSame('', $buffer, 'Zdrojový korpus je prázdný — guard by nekontroloval nic.');

        return $buffer;
    }
}
