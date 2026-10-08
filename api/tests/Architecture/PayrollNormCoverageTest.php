<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Brana normativniho pokryti podani mezd (api/resources/payroll/norms).
 * Soubory generuje tools/norms/build-norm-coverage.php z pozadavku vytazenych z oficialnich
 * dokumentu. Zmena generatoru, importeru ci validatoru podani musi upravit status a tests
 * a nesmi snizit baseline.json (ratchet).
 */
final class PayrollNormCoverageTest extends TestCase
{
    private const STATUSES = [
        'implemented_tested', 'implemented_untested', 'missing', 'violated',
        'not_applicable', 'unclear', 'accepted_gap',
    ];
    private const GAP_STATUSES = ['missing', 'violated', 'unclear', 'accepted_gap'];
    private const KINDS = [
        'required', 'forbidden', 'conditional', 'format', 'codebook',
        'logical_check', 'deadline', 'semantics',
    ];

    /** @var array<string, array{meta: array<string, mixed>, test_refs: list<string>, requirements: list<array<string, mixed>>}>|null */
    private static ?array $forms = null;

    /** @return array<string, array{meta: array<string, mixed>, test_refs: list<string>, requirements: list<array<string, mixed>>}> */
    private static function forms(): array
    {
        if (self::$forms !== null) {
            return self::$forms;
        }
        $dir = self::normsDir();
        $files = glob($dir . '/*.json') ?: [];
        $forms = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            if ($name === 'baseline') {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($data, "Neplatny JSON: {$name}.json");
            $forms[$name] = $data;
        }
        ksort($forms);

        return self::$forms = $forms;
    }

    private static function normsDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/payroll/norms';
    }

    /** @return iterable<string, array{string}> */
    public static function formNames(): iterable
    {
        foreach (array_keys(self::forms()) as $name) {
            yield $name => [$name];
        }
    }

    public function testAllExpectedFormsArePresent(): void
    {
        $expected = ['eldp', 'hoz', 'hzupn20', 'jmhz', 'nempri25', 'ozuspoj', 'prehled-zp', 'prezec26', 'regzec25'];
        foreach ($expected as $form) {
            self::assertArrayHasKey($form, self::forms(), "Chybi norms/{$form}.json");
        }
    }

    #[DataProvider('formNames')]
    public function testEveryEntryHasAllowedStatusAndRequiredFields(string $form): void
    {
        $data = self::forms()[$form];
        self::assertArrayHasKey('requirements', $data);
        self::assertSame($form, $data['meta']['form'] ?? null, "{$form}: meta.form nesedi");
        self::assertSame(count($data['requirements']), $data['meta']['requirements'] ?? null, "{$form}: meta.requirements nesedi");
        self::assertNotSame([], $data['meta']['official_sources'] ?? [], "{$form}: meta.official_sources je prazdne");

        $errors = [];
        foreach ($data['requirements'] as $i => $r) {
            $id = is_string($r['id'] ?? null) && $r['id'] !== '' ? $r['id'] : "#{$i}";
            if ($id[0] === '#') {
                $errors[] = "{$form} {$id}: chybi id";
            }
            if (!in_array($r['status'] ?? null, self::STATUSES, true)) {
                $errors[] = "{$form} {$id}: nepovoleny status " . json_encode($r['status'] ?? null);
            }
            if (!in_array($r['kind'] ?? null, self::KINDS, true)) {
                $errors[] = "{$form} {$id}: nepovoleny kind " . json_encode($r['kind'] ?? null);
            }
            foreach (['rule', 'severity'] as $field) {
                if (!is_string($r[$field] ?? null) || trim($r[$field]) === '') {
                    $errors[] = "{$form} {$id}: chybi {$field}";
                }
            }
            if (($r['status'] ?? null) === 'implemented_tested' && ($r['tests'] ?? []) === []) {
                $errors[] = "{$form} {$id}: implemented_tested bez tests";
            }
        }
        self::assertSame([], array_slice($errors, 0, 20), implode("\n", array_slice($errors, 0, 20)));
    }

    #[DataProvider('formNames')]
    public function testEveryOpenEntryHasAGap(string $form): void
    {
        $errors = [];
        foreach (self::forms()[$form]['requirements'] as $r) {
            if (in_array($r['status'] ?? null, self::GAP_STATUSES, true)
                && (!is_string($r['gap'] ?? null) || trim($r['gap']) === '')) {
                $errors[] = "{$form} {$r['id']} ({$r['status']}): prazdny gap";
            }
        }
        self::assertSame([], array_slice($errors, 0, 20), implode("\n", array_slice($errors, 0, 20)));
    }

    #[DataProvider('formNames')]
    public function testEveryTestReferenceResolvesToAnExistingTest(string $form): void
    {
        $data = self::forms()[$form];
        $pool = $data['test_refs'] ?? [];
        $errors = [];
        foreach ($pool as $ref) {
            [$class, $method] = array_pad(explode('::', (string) $ref, 2), 2, null);
            if (!is_string($class) || !class_exists($class)) {
                $errors[] = "{$form}: neexistujici trida {$ref}";
                continue;
            }
            if ($method !== null && !(new ReflectionClass($class))->hasMethod($method)) {
                $errors[] = "{$form}: neexistujici metoda {$ref}";
            }
        }
        foreach ($data['requirements'] as $r) {
            foreach ($r['tests'] ?? [] as $idx) {
                if (!is_int($idx) || !isset($pool[$idx])) {
                    $errors[] = "{$form} {$r['id']}: neplatny index tests " . json_encode($idx);
                }
            }
        }
        self::assertSame([], array_slice($errors, 0, 20), implode("\n", array_slice($errors, 0, 20)));
    }

    #[DataProvider('formNames')]
    public function testImplementedTestedCountDoesNotDropBelowBaseline(string $form): void
    {
        $baseline = json_decode((string) file_get_contents(self::normsDir() . '/baseline.json'), true);
        self::assertIsArray($baseline);
        self::assertArrayHasKey($form, $baseline['implemented_tested'] ?? [], "baseline.json nema formular {$form}");

        $count = 0;
        foreach (self::forms()[$form]['requirements'] as $r) {
            if (($r['status'] ?? null) === 'implemented_tested') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(
            (int) $baseline['implemented_tested'][$form],
            $count,
            "{$form}: pocet implemented_tested klesl pod baseline (snizeni vyzaduje upravu baseline.json ve stejnem PR).",
        );
    }

    #[DataProvider('formNames')]
    public function testPinnedXsdFolderExists(string $form): void
    {
        $dir = (string) (self::forms()[$form]['meta']['xsd_dir'] ?? '');
        self::assertStringStartsWith('api/xsd/', $dir, "{$form}: meta.xsd_dir musi mirit pod api/xsd");
        $path = dirname(__DIR__, 3) . '/' . $dir;
        self::assertDirectoryExists($path, "{$form}: pripnuta slozka XSD {$dir} neexistuje");
        self::assertNotSame([], glob($path . '/*.xsd') ?: [], "{$form}: {$dir} neobsahuje zadne XSD");
    }

    public function testRequirementIdsAreUniquePerFormAndAcrossForms(): void
    {
        $seen = [];
        $dups = [];
        foreach (self::forms() as $form => $data) {
            foreach ($data['requirements'] as $r) {
                $id = (string) ($r['id'] ?? '');
                if (isset($seen[$id])) {
                    $dups[] = "{$id} ({$seen[$id]} a {$form})";
                }
                $seen[$id] = $form;
            }
        }
        self::assertSame([], array_slice($dups, 0, 20), 'Duplicitni id: ' . implode(', ', array_slice($dups, 0, 20)));
    }

    public function testRequirementsAreSortedByIdForDeterministicDiffs(): void
    {
        foreach (self::forms() as $form => $data) {
            $ids = array_map(static fn (array $r): string => (string) $r['id'], $data['requirements']);
            $sorted = $ids;
            usort($sorted, 'strcmp');
            self::assertSame($sorted, $ids, "{$form}: pozadavky nejsou razeny podle id");
        }
    }

    public function testNormFilesStayWithinSizeBudget(): void
    {
        $total = 0;
        foreach (glob(self::normsDir() . '/*.json') ?: [] as $file) {
            $total += (int) filesize($file);
        }
        self::assertLessThanOrEqual(5 * 1024 * 1024, $total, 'api/resources/payroll/norms presahlo 5 MB.');
    }
}
