<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

/**
 * Jediné místo, které říká, kdy u plátce náleží základní sleva na poplatníka
 * [§ 35ba odst. 1 písm. a) ZDP].
 *
 * Sleva na poplatníka nemá vlastní podmínku, kterou by šlo doložit zvlášť:
 * náleží každému poplatníkovi a měsíčně ji u plátce uplatňuje právě ten, kdo
 * u něj podepsal prohlášení k dani (§ 38k odst. 4 ZDP; bez prohlášení plátce
 * ke slevám nepřihlíží, § 38h odst. 5).
 * Podpis prohlášení je tedy zároveň uplatněním slevy a doklad k ní je samo
 * prohlášení. Proto se odvozuje z evidence prohlášení, ne z řádku nároku.
 *
 * Dřív byly prohlášení a sleva dvě tabulky a dvě obrazovky. Měsíční výpočet
 * slevu bez řádku nároku tiše vynechal (záloha o 2 570 Kč vyšší), roční
 * zúčtování na tentýž stav hlásilo překážku a editor evidence ho vydával za
 * „úplný". Tři větve, tři odpovědi na jednu otázku — teď jedna.
 *
 * Řádek nároku druhu `taxpayer` dál smí existovat (import JMHZ, starší
 * evidence), ale na výsledku nic nemění: bez podpisu slevu nezaloží
 * (§ 38k odst. 4 — to hlídá `tax-credit-requires-signed-declaration`),
 * s podpisem je nadbytečný.
 *
 * Kdo má prohlášení podepsané, dostane slevu v každém měsíci, na jehož
 * POČÁTKU prohlášení platilo — stejný test, jaký používá měsíční i roční
 * větev ({@see EvidenceInterval::includesMonthStart}).
 */
final class TaxpayerCreditEntitlement
{
    public static function fromDeclaration(?TaxDeclarationStatus $status): bool
    {
        return $status === TaxDeclarationStatus::Signed;
    }

    /** Hodnota `status` z evidence nebo ze snímku (`signed`, `not-signed`, `unverified`). */
    public static function fromDeclarationStatus(mixed $status): bool
    {
        return is_string($status)
            && self::fromDeclaration(TaxDeclarationStatus::tryFrom($status));
    }

    /**
     * Měsíce roku, na jejichž počátku platilo podepsané prohlášení.
     *
     * @param list<array<string,mixed>> $declarationRows řádky payroll_person_tax_declarations
     * @return list<int>
     */
    public static function signedMonths(array $declarationRows, int $taxYear): array
    {
        $months = [];
        foreach ($declarationRows as $row) {
            if (!self::fromDeclarationStatus($row['status'] ?? null)) {
                continue;
            }
            $from = substr((string) ($row['effective_from'] ?? ''), 0, 10);
            $to = $row['effective_to'] ?? null;
            $to = is_string($to) && $to !== '' ? substr($to, 0, 10) : null;
            if ($from === '') {
                continue;
            }
            for ($month = 1; $month <= 12; $month++) {
                try {
                    $covers = EvidenceInterval::includesMonthStart(
                        $from,
                        $to,
                        sprintf('%04d-%02d-01', $taxYear, $month),
                    );
                } catch (\InvalidArgumentException) {
                    // Rozbité datum nezakládá nárok; neznámý stav se nečte
                    // jako podpis.
                    $covers = false;
                }
                if ($covers) {
                    $months[$month] = $month;
                }
            }
        }
        ksort($months);

        return array_values($months);
    }
}
