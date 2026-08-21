<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Testovací třída nesmí překrýt finální metodu `PHPUnit\Framework\TestCase`.
 *
 * ⚠️ Vzniklo z reálného selhání, které se stalo **třikrát během jednoho dne**
 * a pokaždé od jiného autora: pomocná metoda pojmenovaná `status()` nebo
 * `run()` shodila načtení **celé** testové sady fatální chybou
 * „Cannot override final method PHPUnit\Framework\TestCase::status()".
 *
 * Zákeřné na tom je, že se to při běžné práci neprojeví: cílený běh přes
 * `--filter` ostatní soubory vůbec nenačítá, takže autor vidí zeleno a chyba
 * vyskočí až v plné sadě — typicky u někoho jiného a bez souvislosti s tím,
 * co zrovna dělal. Hláška navíc ukazuje na soubor, který s rozdělanou prací
 * nemá nic společného.
 *
 * ⚠️ Kontrola jde přes **parser, ne přes hledání v textu**. Textová varianta
 * hlásila plané poplachy hned dvakrát: `'public function run('` jako řetězec
 * uvnitř tvrzení a `name()` deklarovanou v anonymní třídě, která implementuje
 * cizí rozhraní. Ani jedno s `TestCase` nekoliduje.
 *
 * Reflexí to udělat nejde — vyžadovala by třídy načíst, a načtení je právě to,
 * co padá.
 */
final class TestCaseFinalMethodCollisionTest extends TestCase
{
    /**
     * Finální metody `TestCase`, které si člověk přirozeně pojmenuje sám.
     * Není to úplný seznam finálních metod PHPUnitu — je to seznam těch,
     * na které se dá narazit omylem.
     *
     * @var list<string>
     */
    private const RISKY_NAMES = [
        'run', 'status', 'result', 'output', 'size', 'groups', 'name', 'id',
        'valueObjectForEvents', 'requires', 'sortId', 'providedTests',
        'registerComparator', 'expectNotToPerformAssertions',
    ];

    public function testNoTestClassOverridesFinalTestCaseMethod(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new NodeFinder();
        $risky  = array_map('strtolower', self::RISKY_NAMES);

        $offenders = [];

        foreach ($this->testFiles() as $file) {
            $ast = $parser->parse((string) file_get_contents($file));
            if ($ast === null) {
                self::fail('nešlo rozparsovat ' . $this->relative($file));
            }

            /** @var list<Class_> $classes */
            $classes = $finder->find($ast, static function (Node $n): bool {
                // Pojmenovaná třída, která něco dědí. Anonymní třídy (`name`
                // je null) se přeskočí — jejich metody s `TestCase` kolidovat
                // nemohou, i když se jmenují stejně.
                return $n instanceof Class_ && $n->name !== null && $n->extends !== null;
            });

            foreach ($classes as $class) {
                if (!$this->extendsTestCase($class)) {
                    continue;
                }
                foreach ($class->stmts as $stmt) {
                    // Jen přímé metody třídy, ne cokoli zanořeného hlouběji.
                    if (!$stmt instanceof ClassMethod) {
                        continue;
                    }
                    if (in_array(strtolower($stmt->name->toString()), $risky, true)) {
                        $offenders[] = $this->relative($file) . ' → ' . $stmt->name->toString() . '()';
                    }
                }
            }
        }

        sort($offenders, SORT_STRING);

        self::assertSame(
            [],
            $offenders,
            "Testovací třída překrývá finální metodu PHPUnit\\Framework\\TestCase.\n"
            . "Shodí to načtení CELÉ testové sady, ne jen dotčeného souboru, a `--filter` to nechytí.\n"
            . "Přejmenuj pomocníka (například `status()` → `quotaStatus()`):\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Dědí třída (byť nepřímo přes vlastní základnu) z `TestCase`?
     *
     * Rozhoduje se podle jména předka: `TestCase` přímo, nebo cokoli, co končí
     * na `TestCase` (projektové základny typu `IntegrationTestCase`). Je to
     * záměrně velkorysé — planý poplach tu znamená přejmenovat pomocníka,
     * kdežto přehlédnutí znamená spadlou sadu.
     */
    private function extendsTestCase(Class_ $class): bool
    {
        $parent = $class->extends?->toString() ?? '';
        $short  = substr($parent, (int) strrpos($parent, '\\') + 1);

        return $short === 'TestCase' || str_ends_with($short, 'TestCase');
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        $root = dirname(__DIR__);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && str_ends_with($entry->getFilename(), 'Test.php')) {
                $out[] = $entry->getPathname();
            }
        }
        sort($out, SORT_STRING);

        // Pojistka proti tichému projití: kdyby se změnil layout testů a sem
        // nedorazil žádný soubor, test by byl zelený a nekontroloval by nic.
        self::assertNotEmpty($out, 'nenašly se žádné testovací soubory — kontrola by nic neověřovala');

        return $out;
    }

    private function relative(string $path): string
    {
        $root = dirname(__DIR__, 2);
        return str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    }
}
