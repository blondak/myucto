<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Zápočet čisté mzdy na účet společníka (331/366 MD / 365 D).
 *
 * Jednatel-společník si čistou odměnu nevyplácí — započítává ji proti svému účtu
 * ke společníkům. Starý engine „Mzdová rekapitulace" to řeší přes
 * payroll_employees.net_settlement_account_code (migrace 1178); tahle třída drží
 * stejnou sémantiku pro plný mzdový modul.
 *
 * ČÍM JE TENHLE ZPŮSOB VÝPLATY JINÝ: není to výplata. Nevzniká platba, platební
 * příkaz ani pokladní doklad — je to čistě účetní přeúčtování závazku. Proto se
 * započtená částka nesmí objevit jako závazek čisté mzdy ani jako řádek platební
 * dávky; kdyby se objevila, firma by vyplatila peníze, které už jsou vypořádané.
 * Praktický důsledek: běh, kde je celá čistá mzda vypořádaná zápočtem, projde
 * příkazy `prepare_payments` i `mark_paid` bez jediného platebního závazku —
 * „není co platit" je legitimní výsledek, ne chyba přípravy plateb.
 */
final class PayrollPartnerSettlement
{
    /** Hodnota payroll_payout_rules.destination_kind i profile.payout_method. */
    public const KIND = 'partner_settlement';

    /**
     * Jen vztahy, u kterých zápočet proti účtu společníka dává smysl.
     * Běžný zaměstnanec ani dohodář společníkem není a jeho mzdu proti 365
     * započítat nelze.
     *
     * @var list<string>
     */
    public const RELATION_TYPES = ['partner_dependent', 'statutory_body'];

    /**
     * Ověří, že osoba má aspoň jeden vztah, u kterého je zápočet přípustný.
     *
     * Volá se na KAŽDÉM místě, kde se ze zmrazeného výplatního pravidla stává
     * účetní zápis nebo platba — ne jen při zápisu osobní karty. Řádek v
     * payroll_payout_rules se totiž může objevit i mimo aplikaci (import, ruční
     * SQL, budoucí zapisovací API) a kontrola na kartě by ho nezachytila.
     *
     * @param list<string> $relationTypes
     */
    public static function assertEligible(array $relationTypes, int $employeeId): void
    {
        foreach ($relationTypes as $relationType) {
            if (in_array($relationType, self::RELATION_TYPES, true)) {
                return;
            }
        }

        throw new \DomainException(
            "Zápočtem na účet společníka lze vypořádat jen příjem společníka nebo "
            . "odměnu za výkon funkce. Osoba {$employeeId} takový pracovní vztah nemá.",
        );
    }

    /**
     * Kód účtu, proti kterému se zápočet provádí (např. 365.100).
     *
     * Držíme přímo kód z osnovy tenanta, ne přepínač „zápočet ano/ne" — analytika
     * 365.100 vs 365.200 vs 479 je volba účetní. Stejný důvod jako u migrace 1178.
     */
    public static function accountCode(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{3}[.A-Z0-9]{0,7}$/D', trim($value)) !== 1
        ) {
            throw new \InvalidArgumentException(
                "{$field} musí být kód účtu zápočtu, například 365.100.",
            );
        }

        return trim($value);
    }
}
