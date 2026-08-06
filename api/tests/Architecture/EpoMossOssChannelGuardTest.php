<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\Epo\EpoSubmissionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Žádný kanál EPO nesmí nabízet písemnosti patřící do aplikace MOSS/OSS.
 *
 * Portál na POST XML do `/dpr/epo_podani` odpověděl: „Pro práci s písemností
 * 'DAP OSS - režim EU - Přiznání k DPH platné od 1.7.2021' musíte být přihlášeni
 * v aplikaci MOSS/OSS!" Odmítnutá je tedy sama písemnost, ne obsah ani formát —
 * a protože přímý kanál míří na týž endpoint a liší se jen podepsaným tělem,
 * padá na stejnou podmínku. Oba kanály proto musí říkat totéž.
 *
 * Seznamy jsou schválně dva (každá služba si drží svůj, stejně jako `SUPPORTED_FORMS`),
 * takže hrozí, že se rozejdou: dopsat nový formulář do jednoho a zapomenout na druhý
 * by znamenalo nabízet tlačítko, které skončí chybou portálu. Guard drží shodu.
 */
final class EpoMossOssChannelGuardTest extends TestCase
{
    public function testNeitherChannelListsMossOssFormAsSubmittable(): void
    {
        foreach ([EpoSubmissionService::class, EpoDirectSubmissionService::class] as $class) {
            self::assertNotContains(
                'ossei1',
                $this->constant($class, 'SUPPORTED_FORMS'),
                $class . ' nabízí OSS přiznání, které portál mimo aplikaci MOSS/OSS odmítne.',
            );
        }
    }

    public function testBothChannelsAgreeOnTheMossOssFormList(): void
    {
        $assisted = $this->constant(EpoSubmissionService::class, 'MOSS_OSS_FORMS');
        $direct = $this->constant(EpoDirectSubmissionService::class, 'MOSS_OSS_FORMS');

        self::assertSame(
            ['ossei1'],
            $assisted,
            'Asistované předání ztratilo OSS přiznání ze seznamu MOSS/OSS písemností.',
        );
        self::assertSame(
            $assisted,
            $direct,
            'Seznamy MOSS/OSS písemností se rozešly — jeden kanál by formulář nabízel, druhý ne.',
        );
    }

    /** @return list<string> */
    private function constant(string $class, string $name): array
    {
        $constants = (new ReflectionClass($class))->getConstants();
        self::assertArrayHasKey($name, $constants, $class . ' nemá konstantu ' . $name . '.');

        /** @var list<string> $value */
        $value = $constants[$name];
        return $value;
    }
}
