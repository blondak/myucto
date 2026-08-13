<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * MZ-02-W08 — legislativní hodnota smí žít jen v registry rulesetů.
 *
 * `EmploymentIncomeTaxPolicy2026` byla druhá nezávislá kopie všech parametrů
 * daně ze závislé činnosti a její `assertCompatibleRuleset()` navíc vyhodila
 * výjimku, kdykoli se ruleset od kódu lišil — administrátorská změna sazby nebo
 * slevy tedy shodila výpočet daně. Kopie vznikla nenápadně: jako tabulka
 * „klíč => hodnota" vedle registry.
 *
 * Guard proto hledá přesně ten tvar: **kanonický klíč parametru rulesetu
 * spárovaný s číselným literálem** kdekoli v `Service/Payroll` mimo
 * `Service/Payroll/Ruleset` (a mimo testy, které smějí hodnoty tvrdit).
 * Klíče se berou z živé sady {@see CzechPayrollRulesets2026}, takže guard roste
 * s doménou a nezastará.
 *
 * Výjimky se udělují SYMBOLU (metodě, konstantě), nikdy souboru — allowlist na
 * úrovni souboru by vypnul kontrolu i pro kód, který s výjimkou nesouvisí.
 */
final class PayrollRulesetSingleSourceGuardTest extends TestCase
{
    /**
     * Zatím prázdný: mimo registry nemá druhá kopie legislativní hodnoty
     * legitimní důvod. Nový záznam patří obhájit komentářem u kódu.
     *
     * @var array<string, list<string>> relativní cesta => jména symbolů
     */
    private const ALLOWED_SYMBOLS = [];

    public function testRulesetProviderIsARequiredDependency(): void
    {
        $files = self::payrollSourceFiles();
        foreach ($files as $file) {
            require_once $file;
        }
        $paths = [];
        foreach ($files as $relative => $file) {
            $real = realpath($file);
            if (is_string($real)) {
                $paths[strtolower($real)] = $relative;
            }
        }
        $optional = [];
        foreach (get_declared_classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            $relative = is_string($file) ? ($paths[strtolower($file)] ?? null) : null;
            if ($relative === null) {
                continue;
            }
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if (self::isNullableRulesetProvider($parameter)) {
                    $optional[] = "{$relative}::\${$parameter->getName()}";
                }
            }
        }

        self::assertSame(
            [],
            $optional,
            "PayrollRulesetProvider nesmí být volitelná závislost. PHP-DI by použilo null "
            . "a výpočet by tiše přešel na vestavěný ruleset místo administrátorského nastavení.\n"
            . implode("\n", $optional),
        );
    }

    public function testRulesetProviderGuardRecognizesNullableSyntaxVariants(): void
    {
        $variants = [
            static fn (?PayrollRulesetProvider $provider = null): mixed => $provider,
            static fn (PayrollRulesetProvider|null $provider = null): mixed => $provider,
            static fn (\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider|null $provider = null): mixed =>
                $provider,
        ];

        foreach ($variants as $variant) {
            $parameter = (new \ReflectionFunction($variant))->getParameters()[0];
            self::assertTrue(self::isNullableRulesetProvider($parameter));
        }
    }

    private static function isNullableRulesetProvider(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionType || !$type->allowsNull()) {
            return false;
        }
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $candidate) {
            if ($candidate instanceof \ReflectionNamedType
                && ltrim($candidate->getName(), '\\') === PayrollRulesetProvider::class
            ) {
                return true;
            }
        }

        return false;
    }

    public function testNoSecondCopyOfLegislativeValuesOutsideTheRulesetRegistry(): void
    {
        $keys = self::rulesetParameterKeys();
        self::assertNotEmpty($keys);

        $findings = [];
        foreach (self::payrollSourceFiles() as $relative => $file) {
            $code = (string) file_get_contents($file);
            $allowed = self::ALLOWED_SYMBOLS[$relative] ?? [];
            self::assertSame(
                [],
                PhpSourceRegions::missingSymbols($code, $allowed),
                "Allowlist guardu se rozešel s kódem v {$relative}.",
            );
            $scanned = PhpSourceRegions::withoutSymbols(self::withoutComments($code), $allowed);
            foreach (self::hardcodedParameters($scanned, $keys) as $line => $snippet) {
                $findings[] = "{$relative}:{$line}  {$snippet}";
            }
        }

        self::assertSame(
            [],
            $findings,
            "Legislativní hodnota smí mít jediný zdroj — registry rulesetů\n"
            . "(CzechPayrollRulesets2026 + DB override). Druhá kopie v kódu\n"
            . "znamená, že se administrátorská změna buď neprojeví, nebo shodí\n"
            . "výpočet na kontrole shody. Nálezy:\n"
            . implode("\n", $findings),
        );
    }

    public function testGuardDetectsATableOfParametersCopiedIntoCode(): void
    {
        $sample = <<<'PHP'
            <?php
            final class Sample
            {
                public static function create(): array
                {
                    // 'withholding.rate' => '0.15' v komentáři guard nezajímá
                    return [
                        'advance.high_rate' => '0.23',
                        'advance.high_threshold.monthly' => 14_690_100,
                        'credit.taxpayer.monthly' => 257_000,
                    ];
                }
            }
            PHP;

        $findings = self::hardcodedParameters(
            self::withoutComments($sample),
            self::rulesetParameterKeys(),
        );

        self::assertCount(3, $findings, 'Guard musí najít každý zkopírovaný parametr.');
    }

    public function testGuardIgnoresKeysUsedAsLookupsIntoTheRuleset(): void
    {
        $sample = <<<'PHP'
            <?php
            final class Sample
            {
                public function read(object $ruleset): void
                {
                    $rate = $ruleset->parameter('withholding.rate');
                    $limit = $this->money($ruleset, 'dpp.withholding.maximum');
                    $keys = ['credit.taxpayer.monthly', 'credit.ztp_p.monthly'];
                }
            }
            PHP;

        self::assertSame(
            [],
            self::hardcodedParameters(self::withoutComments($sample), self::rulesetParameterKeys()),
        );
    }

    /**
     * Kanonický klíč spárovaný s číselným literálem = hodnota bydlící v kódu.
     * Klíč použitý jako lookup (`parameter('withholding.rate')`) nebo jako
     * položka seznamu klíčů žádnou hodnotu nenese, a guard ho tedy nehlásí.
     *
     * @param list<string> $keys
     * @return array<int,string> číslo řádku => úryvek
     */
    private static function hardcodedParameters(string $code, array $keys): array
    {
        $alternatives = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            $keys,
        ));
        $pattern = "/'(?:{$alternatives})'\\s*=>\\s*'?-?[0-9]/";

        $findings = [];
        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $findings[$index + 1] = trim($line);
            }
        }

        return $findings;
    }

    /**
     * Kanonické klíče všech domén vestavěné sady. Guard tak hlídá i domény,
     * které teprve přibudou.
     *
     * @return list<string>
     */
    private static function rulesetParameterKeys(): array
    {
        $keys = [];
        foreach (CzechPayrollRulesets2026::provider()->versions() as $version) {
            foreach (array_keys($version->parameters) as $key) {
                $keys[$key] = true;
            }
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Komentáře se vyprazdňují, ne mažou — čísla řádků musí zůstat platná
     * a guard nesmí hlásit nález, který žije jen v komentáři.
     */
    private static function withoutComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Registry samotné je jediné legitimní bydliště hodnot, takže se ze skenu
     * vyjímá — všechno ostatní ve `Service/Payroll` se kontroluje.
     *
     * @return array<string,string> relativní cesta => absolutní cesta
     */
    private static function payrollSourceFiles(): array
    {
        $src = str_replace('\\', '/', dirname(__DIR__, 2) . '/src');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $src . '/Service/Payroll',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($src) + 1);
            if (str_starts_with($relative, 'Service/Payroll/Ruleset/')) {
                continue;
            }
            $files[$relative] = $path;
        }
        self::assertNotEmpty($files);
        ksort($files);

        return $files;
    }
}
