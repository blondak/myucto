<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `HealthAction::$probe` je záměrně VOLITELNÝ parametr — health musí odpovědět
 * kompletním tvarem i bez databáze, takže testy si akci staví bez něj.
 *
 * PHP-DI ale volitelné parametry autowiringem PŘESKAKUJE
 * ({@see \DI\Definition\Source\ReflectionBasedAutowiring::getParametersDefinition()},
 * „Skip optional parameters"). Bez explicitního bindu v kontejneru by tedy probe
 * v provozu zůstal `null` a `/api/health` by vracel samé `null` u údržby,
 * běžících úloh, cronu, zálohy i migrací — tiché selhání přesně toho, kvůli
 * čemu ten endpoint vznikl. A žádný unit test by to nechytil, protože ty si
 * probe předávají ručně.
 *
 * Tenhle guard drží, že bind existuje. Kdyby ho někdo odstranil jako
 * „zbytečný", health se navenek nerozbije, jen přestane cokoli hlásit.
 */
final class HealthProbeWiringTest extends TestCase
{
    public function testContainerExplicitlyInjectsTheHealthProbe(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Bootstrap.php');

        self::assertMatchesRegularExpression(
            '~HealthAction::class\s*=>\s*\\\\DI\\\\autowire\(\)\s*->constructorParameter\(\s*\'probe\'~',
            $src,
            'HealthAction musí mít v kontejneru explicitní bind parametru `probe` — '
            . 'PHP-DI volitelné parametry autowiringem přeskakuje a health by tiše '
            . 'přestal hlásit údržbu, běžící úlohy, cron, zálohu i migrace.',
        );
    }
}
