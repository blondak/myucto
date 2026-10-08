<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Druhé oznámení NEMPRI s rozhodným obdobím ke dni převedení na jinou práci.
 *
 * ## Co norma chce
 *
 * § 19 odst. 6 zák. č. 187/2006 Sb.: u zaměstnankyně převedené na jinou práci
 * z důvodu těhotenství, mateřství nebo kojení se rozhodné období zjišťuje ke dni
 * převedení, je-li to pro ni výhodnější. Všeobecné zásady NEMPRI 2025 (položka
 * Převedení na jinou práci, u VPM, PPM, OSE a DLO odkazem „viz nemocenské")
 * k tomu ukládají „předložit současně také další Oznámení zaměstnavatele
 * o žádosti zaměstnance o dávku s RO vztahujícím se ke dni převedení".
 * Výhodnější z obou období určí územní správa, ne zaměstnavatel.
 *
 * ## Jak se podává
 *
 * DV NEMPRI25 pro druhé oznámení žádnou akci ani vazební prvek nemá (akce mají
 * jen OSE a DLO a znamenají vznik, trvání a ukončení péče; `opravnePodani`
 * znamená opravu). Druhé oznámení je proto samostatná datová věta téhož
 * formuláře se stejnou identifikací případu (druh dávky, pojištěnec,
 * zaměstnání, číslo rozhodnutí, potvrzení s `prevedenaNaJinouPraci = true`
 * a `datumNaJinouPraci`), která se liší jen rozhodným obdobím. Aplikace ho
 * podává jako vlastní podání případu ({@see SicknessDocumentKind::NempriTransfer})
 * se stejnou lhůtou jako první oznámení; vlastní stav a vlastní protokol
 * znamenají, že odmítnutí jednoho oznámení neschová přijetí druhého. Do
 * `dalsiSdeleni` se přidá věta {@see self::NOTE}, aby územní správa obě
 * oznámení spárovala i bez vazebního prvku.
 *
 * ## Kdy se nepodává
 *
 * Leží-li den převedení ve stejném kalendářním měsíci jako rozhodný den
 * (§ 18 odst. 3 počítá období po celých měsících), vyšlo by druhé oznámení
 * stejně jako první a ČSSZ by ho odmítla jako duplicitu (logická kontrola č. 1).
 * Totéž u vyrovnávacího příspěvku, u kterého je sociální událostí převedení
 * samo, a u ošetřovného bez akce vznik, které rozhodné období nenese.
 */
final class NempriTransferNotice
{
    /** Věta pro územní správu v `dalsiSdeleni` druhého oznámení. */
    public const NOTE = 'Další oznámení s rozhodným obdobím ke dni převedení na jinou práci (§ 19 odst. 6 zák. č. 187/2006 Sb.).';

    /** Délka `dalsiSdeleni` podle XSD NEMPRI25. */
    private const NOTE_MAX_LENGTH = 200;

    /**
     * Den převedení, ke kterému se druhé oznámení podává; `null`, když se
     * druhé oznámení nepodává.
     *
     * @param array<string,mixed> $row řádek případu (i rozpracovaný stav editoru)
     */
    public static function transferDate(SicknessBenefitKind $kind, array $row, ?string $employmentEnd): ?string
    {
        if ($kind === SicknessBenefitKind::Opp) {
            return null;
        }
        if ($kind->hasActions() && !(bool) ($row['action_start'] ?? true)) {
            return null;
        }
        if ((int) ($row['transferred_other_work'] ?? 0) !== 1
            || !in_array($row['transfer_reason'] ?? null, NempriDecisivePeriodResolver::TRANSFER_REASONS, true)
        ) {
            return null;
        }
        $transferredOn = self::date($row['transferred_on'] ?? null);
        $eventFrom = self::date($row['incapacity_from'] ?? null);
        if ($transferredOn === null || $eventFrom === null) {
            return null;
        }
        $decisiveDate = NempriDecisivePeriodResolver::decisiveDate($eventFrom, $employmentEnd);

        return substr($transferredOn, 0, 7) < substr($decisiveDate, 0, 7) ? $transferredOn : null;
    }

    /** @param array<string,mixed> $row */
    public static function required(SicknessBenefitKind $kind, array $row, ?string $employmentEnd): bool
    {
        return self::transferDate($kind, $row, $employmentEnd) !== null;
    }

    /** `dalsiSdeleni` druhého oznámení: věta pro územní správu a poznámka případu. */
    public static function note(?string $caseNote): string
    {
        $note = $caseNote === null || trim($caseNote) === ''
            ? self::NOTE
            : self::NOTE . ' ' . trim($caseNote);

        return mb_substr($note, 0, self::NOTE_MAX_LENGTH);
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? $value : null;
    }
}
