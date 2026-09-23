<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollTakeover;
use MyInvoice\Service\Migration\Premier\PremierPayrollTakeover;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Položka Zákonných termínů, kterou převod nemůže odškrtnout: očekávané odmítnutí se
 * přeskočí, chyba databáze projde výš u každého zdroje. Převod z PREMIER dřív spolykal
 * jakoukoli RuntimeException včetně PDOException a položka tiše zůstala neodškrtnutá.
 */
final class PayrollTakeoverChecklistFailureTest extends TestCase
{
    /** @return iterable<string,array{0:PayrollTakeoverPolicy}> */
    public static function policies(): iterable
    {
        yield 'PREMIER' => [PremierPayrollTakeover::policy()];
        yield 'PAMICA' => [PohodaPayrollTakeover::policy()];
    }

    #[DataProvider('policies')]
    public function testDatabaseErrorIsNotTolerated(PayrollTakeoverPolicy $policy): void
    {
        self::assertFalse(self::tolerated(new \PDOException('SQLSTATE[HY000]: General error'), $policy));
        self::assertFalse(self::tolerated(new \RuntimeException('neočekávaná chyba'), $policy));
    }

    #[DataProvider('policies')]
    public function testExpectedRejectionsAreTolerated(PayrollTakeoverPolicy $policy): void
    {
        foreach ([
            new PayrollEmploymentConflictException(2),
            new PayrollEmploymentNotFoundException('Položka checklistu nebyla nalezena.'),
            new \DomainException('Nejdřív doplňte datum nástupu.'),
            new \InvalidArgumentException('neplatný stav'),
        ] as $e) {
            self::assertTrue(self::tolerated($e, $policy), $e::class);
        }
    }

    private static function tolerated(\Exception $e, PayrollTakeoverPolicy $policy): bool
    {
        return (bool) (new \ReflectionMethod(PayrollTakeoverEmploymentWriter::class, 'checklistFailure'))->invoke(null, $e, $policy);
    }
}
