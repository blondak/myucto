<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pojistka proti mrtvému filtru na stav pracovního vztahu.
 *
 * ## Co se stalo
 *
 * Migrace 1195 přejmenovala hodnotu `cancelled` na `no_show` a z enumu
 * `payroll_employments.status` ji odstranila. Dotaz v docházce
 * (`PayrollTimeRepository::employments()`) zůstal psaný proti staršímu enumu:
 * `AND employment.status <> 'cancelled'`. Podmínka se tím stala mrtvou —
 * nevyloučila nic a do docházky propouštěla i `no_show` a `archived` vztahy.
 * Majitel to viděl jako „proč mám Radka Hulána 2×".
 *
 * ## Proč statický test a ne jen scénář
 *
 * Integrační test na docházku (`PayrollTimeApiTest`) chybu chytí, ale jen pro
 * jednu metodu. Tenhle test hlídá celou vrstvu: jakmile kdokoliv porovná stav
 * pracovního vztahu s hodnotou, která v enumu neexistuje, spadne to hned —
 * nezávisle na tom, jestli k té metodě někdo napsal scénář.
 *
 * Enum se čte z migrace, ne z konstanty v testu: kdyby ho někdo v budoucnu zase
 * změnil, test se posune s ním a chytne kód, který se za změnou neposunul.
 */
#[Group('architecture')]
final class PayrollEmploymentStatusLiteralTest extends TestCase
{
    /**
     * Migrace, která drží finální podobu enumu.
     *
     * Ověřeno grepem: žádná pozdější migrace `payroll_employments.status`
     * nemodifikuje. Kdyby přibyla, `enumFromMigration()` níž ji musí vzít v potaz
     * a tenhle test to připomene tím, že přestane sedět s realitou.
     */
    private const LIFECYCLE_MIGRATION = '1195_payroll_employment_lifecycle.sql';

    /**
     * Aliasy sloupce se stavem vztahu, jak se v dotazech objevují.
     *
     * `effective_status` je odvozený stav k období
     * ({@see \MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql}) — nabývá
     * týchž hodnot, takže platí totéž.
     */
    private const STATUS_EXPRESSIONS = [
        'employment.status',
        'employment.effective_status',
        'effective_status',
    ];

    public function testEmploymentStatusFiltersUseOnlyExistingEnumValues(): void
    {
        $allowed = $this->enumFromMigration();
        self::assertContains('no_show', $allowed);
        self::assertNotContains(
            'cancelled',
            $allowed,
            'Migrace 1195 hodnotu cancelled z enumu odstranila.',
        );

        $offences = [];
        foreach ($this->phpSources() as $path) {
            $code = (string) file_get_contents($path);
            foreach (self::STATUS_EXPRESSIONS as $expression) {
                $pattern = '/' . preg_quote($expression, '/')
                    . '\s*(?:<>|!=|=|NOT\s+IN|IN)\s*\(?([^;)]{0,300})/i';
                if (preg_match_all($pattern, $code, $matches) === 0) {
                    continue;
                }
                foreach ($matches[1] as $condition) {
                    preg_match_all('/["\']([a-z_]+)["\']/', $condition, $literals);
                    foreach ($literals[1] as $literal) {
                        if (!in_array($literal, $allowed, true)) {
                            $offences[] = sprintf(
                                '%s: %s porovnáno s neexistující hodnotou "%s"',
                                basename($path),
                                $expression,
                                $literal,
                            );
                        }
                    }
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offences)),
            'Filtr nad payroll_employments.status používá hodnotu, kterou enum nezná — '
            . 'podmínka je mrtvá a nevyloučí nic.',
        );
    }

    /** @return list<string> */
    private function enumFromMigration(): array
    {
        $path = dirname(__DIR__, 3) . '/db/migrations/' . self::LIFECYCLE_MIGRATION;
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        preg_match_all(
            '/MODIFY COLUMN status\s*ENUM\s*\(([^)]+)\)/i',
            $sql,
            $matches,
        );
        self::assertNotSame([], $matches[1], 'Enum stavu vztahu se v migraci nenašel.');
        // Migrace enum nejdřív rozšíří o legacy hodnoty a teprve pak zúží na finální
        // podobu — platí ta poslední.
        preg_match_all('/\'([a-z_]+)\'/', (string) end($matches[1]), $values);

        return $values[1];
    }

    /** @return list<string> */
    private function phpSources(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
