<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Codebook;

use MyInvoice\Action\Codebook\VatClassificationsAction;
use PHPUnit\Framework\TestCase;

final class VatClassificationsActionValidationTest extends TestCase
{
    public function testKhSpecialAttributesAcceptOnlyDocumentedValues(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $base = ['code' => 'T90', 'label' => 'Test', 'direction' => 'sale'];

        self::assertNull($validate->invoke($action, $base + ['kh_regime_code' => '2', 'kh_bad_debt' => 'P'], false));
        self::assertStringContainsString('kh_regime_code', (string) $validate->invoke($action, $base + ['kh_regime_code' => '9'], false));
        self::assertStringContainsString('kh_bad_debt', (string) $validate->invoke($action, $base + ['kh_bad_debt' => 'X'], false));
    }

    /**
     * `dphdp3_line` byl volný text bez validace. Řádek, který generátor neumí, se do XML
     * nedostane — základ i daň tiše zmizí, přestože náhled v UI je zobrazí.
     *
     * Zvlášť hlídáme '34' a krácené 40k/41k/42k: ty v $lineMap JSOU, takže by prošly
     * naivní kontrolou „je v mapě", ale uživatel je nastavovat nesmí. U '34' by to bylo
     * horší než tiché zahození — jeho `base` slot nese daň, takže by se do opr_dluz
     * dostal ZÁKLAD a v podaném XML by byla tiše chybná hodnota.
     */
    public function testDphLineAcceptsOnlySupportedReturnLines(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $base = ['code' => 'T91', 'label' => 'Test', 'direction' => 'sale'];

        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => '1'], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => '51b'], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line_secondary' => '43'], false));
        // Prázdné / nevyplněné je v pořádku — klasifikace nemusí do přiznání mířit.
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => null], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => ''], false));

        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '99'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '34'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '40k'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '33'], false));
        self::assertStringContainsString(
            'dphdp3_line_secondary',
            (string) $validate->invoke($action, $base + ['dphdp3_line_secondary' => '34'], false),
        );
    }
}
