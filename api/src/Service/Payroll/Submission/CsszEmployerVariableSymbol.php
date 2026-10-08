<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Variabilní symbol zaměstnavatele, pod kterým podání ČSSZ odchází — podle
 * prostředí. Jediné pravidlo pro všechny agendy ČSSZ: JMHZ, registrace
 * zaměstnance (PREZEC, REGZEC), nemocenské (NEMPRI, HZUPN) i OZUSPOJ.
 *
 * Testovací prostředí ČSSZ má k účtárně vlastní přidělený VS (Nastavení mezd →
 * Zaměstnavatel a účtárny), jiný než ostrý. Do testu se posílá ten; bez
 * vyplněného testovacího VS zůstává VS účtárny. Ostré prostředí testovací VS
 * nikdy nedostane.
 *
 * Dřív tohle uměla jen JMHZ a registrace četly natvrdo ostrý VS, takže
 * zkušební přihláška do testu ČSSZ odcházela pod ostrým symbolem firmy.
 */
final class CsszEmployerVariableSymbol
{
    private const ENVIRONMENTS = ['test', 'production'];

    public static function forEnvironment(
        string $environment,
        ?string $productionSymbol,
        ?string $testSymbol,
    ): ?string {
        return self::usesTestSymbols($environment)
            ? self::preferTest($productionSymbol, $testSymbol)
            : $productionSymbol;
    }

    /** Smí se v tomhle prostředí vůbec sáhnout po testovacím VS? */
    public static function usesTestSymbols(string $environment): bool
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(
                'Prostředí podání ČSSZ musí být `test` nebo `production`.',
            );
        }

        return $environment === 'test';
    }

    /**
     * Výběr uvnitř testovacího prostředí: platný testovací VS má přednost,
     * jinak zůstává VS účtárny.
     */
    public static function preferTest(?string $productionSymbol, ?string $testSymbol): ?string
    {
        $test = $testSymbol === null ? '' : trim($testSymbol);

        return preg_match('/^[0-9]{10}$/D', $test) === 1 ? $test : $productionSymbol;
    }
}
