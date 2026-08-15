<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use PHPUnit\Framework\TestCase;

/**
 * Materializace závazků odvozuje z kódu pojišťovny referenci i platební příkaz,
 * takže neexistující kód musí spadnout dřív, než se cokoliv založí.
 *
 * Kontrolovaná větev leží v privátním `currentTargets()` a k číselníkové bráně
 * se dostane bez jediné závislosti materializeru (repozitáře se používají až za
 * ní), proto se instance vyrábí bez konstruktoru — jinak by šlo o integrační
 * test s databází.
 */
final class PayrollHealthInsuranceLiabilityInsurerCodebookTest extends TestCase
{
    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Kód zdravotní pojišťovny 999 neexistuje.');
        $this->currentTargets('999');
    }

    public function testMessageListsTheAvailableInsurers(): void
    {
        try {
            $this->currentTargets('205X');
            self::fail('Neexistující pojišťovna musí být odmítnuta.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('111 VZP', $exception->getMessage());
            self::assertStringContainsString('213 RBP', $exception->getMessage());
        }
    }

    private function currentTargets(string $insurerCode): void
    {
        $materializer = (new \ReflectionClass(PayrollHealthInsuranceLiabilityMaterializer::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($materializer, 'currentTargets');
        $method->invoke($materializer, 41, '2026-07-20', [
            'total_contribution_minor_units' => 229_500,
            'insurer_liabilities' => [[
                'insurer_code' => $insurerCode,
                'total_contribution_minor_units' => 229_500,
            ]],
        ]);
    }
}
