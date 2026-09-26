<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

/**
 * Za které měsíce lze uplatnit daňové zvýhodnění na dítě, vzhledem k období,
 * po které je dítě vedené jako vyživované.
 *
 * § 35c odst. 10 zákona č. 586/1992 Sb.:
 *
 *   „Poplatníkovi, který vyživuje dítě jen jeden kalendářní měsíc nebo několik
 *   kalendářních měsíců ve zdaňovacím období, lze poskytnout daňové zvýhodnění
 *   ve výši 1/12 za každý kalendářní měsíc, na jehož počátku byly splněny
 *   podmínky pro jeho uplatnění. Daňové zvýhodnění lze uplatnit již
 *   v kalendářním měsíci, ve kterém se dítě narodilo, nebo ve kterém začíná
 *   soustavná příprava dítěte na budoucí povolání, anebo ve kterém bylo dítě
 *   osvojeno nebo převzato do péče nahrazující péči rodičů na základě
 *   rozhodnutí příslušného orgánu."
 *
 * Nárok (`payroll_person_tax_child_claims`) se eviduje po celých měsících
 * (od prvního dne, do posledního) a výpočet — měsíční záloha, bonus, roční
 * zúčtování, potvrzení § 38j i měsíční hlášení — se ptá jen, jestli interval
 * nároku obsahuje první den měsíce. Pravidlo „na jehož počátku" i jeho
 * výjimky se proto uplatní TADY, při zápisu intervalu proti období
 * vyživování (`payroll_dependants.existence_from/to`):
 *
 *  - Začátek: vyživování od prvního dne měsíce kryje celý měsíc. Začne-li
 *    v průběhu měsíce, patří měsíc do nároku jen při výjimce z druhé věty —
 *    narození (vyživování začíná v měsíci narození), osvojení, převzetí do
 *    péče nahrazující péči rodičů a zahájení soustavné přípravy na povolání
 *    (důvod nároku). Jinak nárok začíná až následujícím měsícem.
 *  - Konec: měsíc, ve kterém vyživování skončí (úmrtí, konec studia, 26 let),
 *    na svém počátku podmínky splňoval, takže do nároku patří celý.
 *
 * Průkaz ZTP/P výjimku nemá: dvojnásobek (§ 35c odst. 7) náleží až za měsíc,
 * na jehož počátku byl nárok na průkaz přiznán.
 */
final class ChildCreditClaimWindow
{
    /** Důvody nároku, které otevírají už měsíc, ve kterém vyživování začalo. */
    public const START_EVENT_REASONS = ['adoption', 'foster_care', 'study_start'];

    public static function earliestFrom(
        string $birthDate,
        string $existenceFrom,
        ?string $claimReason,
    ): string {
        $monthStart = substr($existenceFrom, 0, 7) . '-01';
        if ($existenceFrom === $monthStart
            || substr($existenceFrom, 0, 7) === substr($birthDate, 0, 7)
            || in_array($claimReason, self::START_EVENT_REASONS, true)
        ) {
            return $monthStart;
        }

        return (new \DateTimeImmutable($monthStart))
            ->modify('+1 month')
            ->format('Y-m-d');
    }

    public static function latestTo(?string $existenceTo): ?string
    {
        if ($existenceTo === null) {
            return null;
        }

        return (new \DateTimeImmutable(substr($existenceTo, 0, 7) . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    public static function contains(
        string $from,
        ?string $to,
        string $birthDate,
        string $existenceFrom,
        ?string $existenceTo,
        ?string $claimReason,
    ): bool {
        if ($from < self::earliestFrom($birthDate, $existenceFrom, $claimReason)) {
            return false;
        }
        $latest = self::latestTo($existenceTo);

        return $latest === null || ($to !== null && $to <= $latest);
    }
}
