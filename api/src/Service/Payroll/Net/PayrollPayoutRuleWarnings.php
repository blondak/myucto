<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Nefatální nálezy nad výplatními pravidly — věci, které zápis nezastaví, ale
 * které BUDOU vadit při přípravě plateb.
 *
 * PROČ VARONÍ A NE CHYBA: bankovní pravidlo na neověřený účet je legitimní
 * mezistav. Účetní zadá číslo účtu i pravidlo, ověření (`payroll.person.write`
 * → „Ověřit účet") proběhne o den dva později. Kdyby zápis pravidla ověření
 * vyžadoval, nešlo by kartu osoby dokončit na jeden zátah.
 *
 * PROČ TO PŘESTO MUSÍ BÝT VIDĚT: PayrollNetWageLiabilityMaterializer takový cíl
 * odmítne („Zmrazený účet nemá úplné ověření") — jenže až nad ZMRAZENOU revizí,
 * kde se vada opravuje jedině opravnou revizí. Varování posouvá tuhle informaci
 * o měsíc dopředu, do okamžiku, kdy s tím uživatel ještě může něco udělat.
 *
 * PROČ SE POČÍTÁ Z ULOŽENÉHO PRAVIDLA, NE ZE ZÁPISU: varování je čistá funkce
 * stavu, ne události. Díky tomu ho stejně dostane i GET nad pravidly, která
 * někdo založil minulý měsíc — kdyby vznikalo jen ve validátoru při zápisu,
 * viděl by ho jen ten, kdo pravidlo právě uložil.
 */
final class PayrollPayoutRuleWarnings
{
    /** Bankovní cíl pravidla míří na účet bez kompletního ověření. */
    public const UNVERIFIED_DESTINATION = 'unverified_destination';

    /**
     * @param list<array<string,mixed>> $rules pravidla z PayrollPayoutRuleRepository
     * @return list<array{code:string,rule_id:int,account_id:?int,message:string}>
     */
    public static function forRules(array $rules): array
    {
        $warnings = [];
        foreach ($rules as $rule) {
            // Neaktivní pravidlo do výplaty nevstupuje, takže ho neověřený účet
            // nepálí — hlásit ho by byl jen šum.
            if (($rule['is_active'] ?? false) !== true) {
                continue;
            }
            // null = ověření u tohohle cíle nedává smysl (hotovost, zápočet).
            if (($rule['destination_verified'] ?? null) !== false) {
                continue;
            }
            $warnings[] = [
                'code' => self::UNVERIFIED_DESTINATION,
                'rule_id' => (int) ($rule['id'] ?? 0),
                'account_id' => self::accountId($rule),
                'message' => 'Výplatní účet zatím není ověřený. Dokud ověření '
                    . 'neproběhne, nepůjde na něj mzdu připravit k výplatě.',
            ];
        }

        return $warnings;
    }

    /** @param array<string,mixed> $rule */
    private static function accountId(array $rule): ?int
    {
        $reference = $rule['destination_reference'] ?? null;
        if (!is_string($reference)
            || preg_match(
                PayrollPayoutRuleInput::BANK_REFERENCE_PATTERN,
                $reference,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return (int) $match[1];
    }
}
