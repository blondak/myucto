<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollCsszDistrictCodebook;

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

    /** VS přidělený zahraničnímu zaměstnavateli, bez kódu okresu (katalog kontrol MH č. 143). */
    private const FOREIGN_PREFIX = '1868';

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
     * Logická kontrola VS zaměstnavatele (EDV 1.4.0.6, ID 10221 a 10222:
     * „C_COKR + Luhnův algoritmus pro VS10"; katalog kontrol MH č. 143):
     * 8 až 10 číslic; desetimístný VS musí projít Luhnovým součtem přes všech
     * deset číslic a, není-li zahraniční (začíná 1868), začínat kódem okresní
     * správy z číselníku C_COKR. Kratší, dříve přidělené symboly kontrolu
     * součtu ani okresu nemají.
     *
     * Vrací důvod, proč VS ČSSZ odmítne, nebo `null`, když je v pořádku.
     */
    public static function invalidReason(string $variableSymbol): ?string
    {
        if (preg_match('/^\d{8,10}$/D', $variableSymbol) !== 1) {
            return 'musí mít 8 až 10 číslic bez mezer a lomítek';
        }
        if (strlen($variableSymbol) !== 10) {
            return null;
        }
        if (!self::luhn($variableSymbol)) {
            return 'nesouhlasí kontrolní číslice (desetimístný symbol ČSSZ '
                . 'prochází Luhnovým součtem), nejspíš je v něm překlep';
        }
        if (!str_starts_with($variableSymbol, self::FOREIGN_PREFIX)
            && !PayrollCsszDistrictCodebook::contains(substr($variableSymbol, 0, 3))
        ) {
            return 'nezačíná kódem okresní správy sociálního zabezpečení '
                . '(první tři číslice „' . substr($variableSymbol, 0, 3)
                . '" nejsou v číselníku okresů ČSSZ)';
        }

        return null;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
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
