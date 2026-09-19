<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyAccountCode;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingSource;

/**
 * Převzaté zaúčtování mezd z `91_mzdy.xml` (POHODA Mzdy / PAMICA).
 *
 * ⚠ **Nic to neúčtuje a neukládá.** Třída jen čte a překládá; co z toho plyne
 * pro nastavení mezd, rozhoduje obecná vrstva
 * ({@see \MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalBuilder}).
 * Převzaté účetní zápisy se do MyÚčta NEPŘENÁŠEJÍ - mzdy se zaúčtují až tady,
 * podle vlastního nastavení, a přenesené zápisy by proti převedeným dokladům
 * vyrobily duplicitu.
 *
 * ── Které tabulky a proč ────────────────────────────────────────────────────
 * - `MZzauct` (v reálné agendě přes 11 tisíc řádků) - vlastní zaúčtování mzdy:
 *   částka, předkontace, účet MD a D, středisko, složka.
 * - `MZzauctRoz` - rozúčtování řádku na střediska, činnosti a zakázky. Účty
 *   nenese, takže pro odvození předkontace přidává JEN střediska.
 * - `pPK` - číselník předkontací. Tohle je jediné místo, kde je u zápisu
 *   napsáno, CO to je („Zdravotní pojištění (zaměstnanec)"). Bez něj by z
 *   dvojice účtů nešlo poznat, jestli 336 je sociální, nebo zdravotní.
 * - `pOS` - číselník účtů; dává převzatému účtu jeho původní název.
 *
 * ── Jak se pozná význam ─────────────────────────────────────────────────────
 * `MZzauct.ResPk` nese IDS předkontace, ne její id. Text z `pPK.SText` se
 * normalizuje (bez diakritiky, malá písmena) a projde tabulkou vzorů. Číslo ani
 * pořadí položky číselníku se ZÁMĚRNĚ nepoužívá: uživatel si předkontace v
 * PAMICA přidává a přepisuje, takže id viděná na jedné instalaci nejsou
 * kontrakt - stejná úvaha jako u srážek v {@see PohodaPayrollDeductions}.
 *
 * Zaměstnanec vs. společník se bere z příznaku `Spolec` na řádku, ne z textu:
 * text ho zmiňuje jen u části položek, zatímco příznak je na každém řádku.
 *
 * ── Strany MD a D se berou z číselníku, ne z řádku ──────────────────────────
 * `MZzauct.UMD` a `UD` jsou u záporné částky PROHOZENÉ: sražené pojistné má
 * v reálném exportu `Kc` = −3 427 787, `UMD` = 336000 a `UD` = 331000, přestože
 * předkontace je 331000/336000. Kdyby se braly z řádku, skončilo by 336 jako
 * nákladová strana hrubé mzdy. Orientaci proto určuje `pPK` a částka se bere
 * v absolutní hodnotě - storna a opravy jsou váha řádku, ne jeho opak.
 *
 * `IDS` v `pPK` NENÍ unikátní - výchozí číselník má „331000/379000" dvakrát
 * („Zaúčtování zálohy zaměstnance" a „Srážky ze mzdy zaměstnance"). Když se
 * takové položky rozcházejí ve významu, bere se ten jediný, který nějaký význam
 * MÁ; kdyby si dva významy odporovaly, řádek zůstane nezařazený. Prázdný význam
 * totiž nic netvrdí - plnění, pro které MyÚčto předkontaci nemá (zálohy, úhrada
 * mzdy, zaokrouhlení, dávky nemocenské), se nepřekládá vůbec.
 */
final class PohodaPayrollPostingMap implements PayrollLegacyPostingSource
{
    public const SOURCE = 'pamica';

    /** Nejvíc středisek, která se u jedné předkontace vypisují jako doklad. */
    private const COST_CENTER_LIMIT = 20;

    /**
     * Vzory textu předkontace => základní význam.
     *
     * Pořadí ROZHODUJE, první shoda vyhrává. Specifické vzory proto stojí před
     * obecnými: „zvláštní sazba" před „daň z příjmů", pojistné firmy před
     * pojistným zaměstnance a všechno, co MyÚčto nemá, před tím, s čím by se to
     * dalo splést.
     *
     * Hodnota `null` znamená „rozpoznáno, ale MyÚčto pro to předkontaci nemá".
     * Není to totéž co chybějící vzor: takový řádek se nemá za co zařadit a
     * nesmí přebít význam, se kterým sdílí IDS.
     *
     * @var array<string,?string>
     */
    private const PATTERNS = [
        // Plnění bez protějšku v sadě předkontací MyÚčta.
        '/zauctovani zalohy|zaloha zamestnance|zaloha spolecnika/' => null,
        '/uhrada mzdy/' => null,
        '/zaokrouhlen/' => null,
        '/nahrada nakladu zamestnavatele/' => null,
        '/socialni zabezpeceni davky|davky zamestnanci/' => null,
        '/duchodove sporeni/' => null,
        '/urazove pojisteni/' => null,
        '/produkty na stari|penzij|zivotni pojist/' => null,
        '/karanten/' => null,
        '/dan v jinem state/' => null,
        // „Sociální a zdravotní pojištění (společník)" je jedna částka za obě
        // instituce. Rozdělit ji nejde a nabídnout týž účet oběma předkontacím
        // by zrušilo přesně to, kvůli čemu se 336 na analytiky dělí.
        '/socialni a zdravotni/' => null,

        // Plnění, která předkontaci v MyÚčtu mají.
        '/statutar|jednatel|organ spolecnosti/' => 'statutory_gross',
        '/pojisteni \(firma|pojisteni firma|pojistne zamestnavatele/' => 'employer_insurance',
        '/zdravotni pojisteni/' => 'health_insurance',
        '/socialni pojisteni/' => 'social_insurance',
        '/zvlastni sazba|srazkova dan/' => 'withholding_tax',
        '/dan z prijmu|zaloha na dan/' => 'advance_tax',
        '/exekuc|insolven/' => 'enforcement_deductions',
        '/srazky ze mzdy|srazka ze mzdy/' => 'other_deductions',
        '/cestovn/' => 'travel_expense',
        '/hruba mzda|mimomzdove|prijmy spolecniku/' => 'gross',
    ];

    /** @var list<PayrollLegacyPostingRow> */
    private array $rows;

    /** @param list<PayrollLegacyPostingRow> $rows */
    private function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function sourceKey(): string
    {
        return self::SOURCE;
    }

    /** @return list<PayrollLegacyPostingRow> */
    public function postingRows(): array
    {
        return $this->rows;
    }

    /**
     * Přečte převzaté zaúčtování; `$year` omezí na jeden rok exportu.
     */
    public static function read(string $file, ?int $year = null): self
    {
        $catalog = self::readPredkontace($file);
        $accountNames = self::readAccountNames($file);
        $splitCostCenters = self::readSplitCostCenters($file, $year);

        /** @var array<string,array<string,mixed>> $buckets */
        $buckets = [];
        foreach (PohodaXml::records($file, 'MZzauct') as $row) {
            if ($year !== null && (int) PohodaXml::text($row, 'Rok') !== $year) {
                continue;
            }
            $ids = PohodaXml::text($row, 'ResPk');
            $entries = $catalog[$ids] ?? [];
            $partner = PohodaXml::text($row, 'Spolec') === '1';

            $debit = PayrollLegacyAccountCode::normalize(
                $entries === [] ? PohodaXml::text($row, 'UMD') : $entries[0]['umd'],
            );
            $credit = PayrollLegacyAccountCode::normalize(
                $entries === [] ? PohodaXml::text($row, 'UD') : $entries[0]['ud'],
            );
            $debitSource = $entries === [] ? PohodaXml::text($row, 'UMD') : $entries[0]['umd'];
            $creditSource = $entries === [] ? PohodaXml::text($row, 'UD') : $entries[0]['ud'];

            [$concept, $label] = self::resolveMeaning($entries, $partner, $ids, $accountNames, $debitSource, $creditSource);
            $key = $ids . '|' . ($concept ?? '') . '|' . $debitSource . '|' . $creditSource;

            $bucket = $buckets[$key] ?? [
                'concept' => $concept,
                'label' => $label,
                'debit' => $debit,
                'credit' => $credit,
                'debit_source' => $debitSource === '' ? null : $debitSource,
                'credit_source' => $creditSource === '' ? null : $creditSource,
                'reference' => $ids === '' ? 'pamica:MZzauct:' . $debitSource . '/' . $creditSource : 'pamica:pPK:' . $ids,
                'lines' => 0,
                'amount' => 0,
                'cost_centers' => [],
            ];
            $bucket['lines']++;
            $bucket['amount'] += (int) abs(round(PohodaXml::num($row, 'Kc') * 100.0));
            foreach (['ResStr', 'ResStrPomer', 'ResStrSl'] as $field) {
                $centre = PohodaXml::text($row, $field);
                if ($centre !== '') {
                    $bucket['cost_centers'][$centre] = true;
                }
            }
            foreach ($splitCostCenters[PohodaXml::text($row, 'ID')] ?? [] as $centre => $_) {
                $bucket['cost_centers'][$centre] = true;
            }
            $buckets[$key] = $bucket;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $centres = array_keys($bucket['cost_centers']);
            sort($centres, SORT_STRING);
            $rows[] = new PayrollLegacyPostingRow(
                self::SOURCE,
                (string) $bucket['reference'],
                $bucket['concept'],
                (string) $bucket['label'],
                $bucket['debit'],
                $bucket['credit'],
                $bucket['debit_source'],
                $bucket['credit_source'],
                (int) $bucket['lines'],
                (int) $bucket['amount'],
                array_slice($centres, 0, self::COST_CENTER_LIMIT),
            );
        }

        return new self($rows);
    }

    /**
     * Význam a popisek řádku.
     *
     * @param list<array{text:string,umd:string,ud:string}> $entries
     * @param array<string,string> $accountNames
     * @return array{0:?string,1:string}
     */
    private static function resolveMeaning(
        array $entries,
        bool $partner,
        string $ids,
        array $accountNames,
        string $debitSource,
        string $creditSource,
    ): array {
        if ($entries === []) {
            return [null, self::fallbackLabel($ids, $accountNames, $debitSource, $creditSource)];
        }

        $concepts = [];
        $texts = [];
        foreach ($entries as $entry) {
            $texts[$entry['text']] = true;
            $concept = self::classify($entry['text'], $partner);
            if ($concept !== null) {
                $concepts[$concept] = true;
            }
        }
        $label = implode(' / ', array_keys($texts));

        return [count($concepts) === 1 ? (string) array_key_first($concepts) : null, $label];
    }

    /** Popisek pro řádek, ke kterému se předkontace nenašla. */
    private static function fallbackLabel(
        string $ids,
        array $accountNames,
        string $debitSource,
        string $creditSource,
    ): string {
        if ($debitSource === '' && $creditSource === '') {
            return $ids === '' ? 'Zaúčtování bez předkontace' : 'Předkontace ' . $ids . ' není v číselníku';
        }
        $name = $accountNames[$debitSource] ?? $accountNames[$creditSource] ?? null;
        $pair = trim($debitSource . '/' . $creditSource, '/');

        return $name === null ? 'Zaúčtování ' . $pair : 'Zaúčtování ' . $pair . ' - ' . $name;
    }

    /** Základní význam textu předkontace přeložený na variantu zaměstnanec/společník. */
    private static function classify(string $text, bool $partner): ?string
    {
        $normalized = AttendanceText::normalize($text);
        $base = null;
        foreach (self::PATTERNS as $pattern => $meaning) {
            if (preg_match($pattern, $normalized) === 1) {
                $base = $meaning;
                break;
            }
        }
        if ($base === null) {
            return null;
        }
        // Text zmiňuje společníka jen u části položek výchozího číselníku,
        // příznak `Spolec` je na každém řádku - obojí se proto sčítá.
        $partner = $partner || preg_match('/spolecnik|spolecniku|spol\./', $normalized) === 1;

        return match ($base) {
            'gross' => $partner ? 'partner_gross' : 'employment_gross',
            'statutory_gross' => 'statutory_gross',
            'social_insurance' => $partner ? 'partner_employee_social' : 'employee_social',
            'health_insurance' => $partner ? 'partner_employee_health' : 'employee_health',
            // Pojistné zaměstnavatele varianty nemá: 524 je táž nákladová
            // předkontace pro zaměstnance i společníka. Rozdělí se jen na
            // sociální a zdravotní, podle toho, co v textu zbylo.
            'employer_insurance' => str_contains($normalized, 'zdravotni')
                ? 'employer_health'
                : 'employer_social',
            'advance_tax' => $partner ? 'partner_advance_tax' : 'advance_tax',
            'withholding_tax' => $partner ? 'partner_withholding_tax' : 'withholding_tax',
            'other_deductions' => $partner ? 'partner_other_deductions' : 'other_deductions',
            'enforcement_deductions' => 'enforcement_deductions',
            'travel_expense' => 'travel_expense',
            default => null,
        };
    }

    /**
     * Číselník předkontací podle IDS; jedno IDS může mít víc položek.
     *
     * @return array<string,list<array{text:string,umd:string,ud:string}>>
     */
    private static function readPredkontace(string $file): array
    {
        $catalog = [];
        foreach (PohodaXml::records($file, 'pPK') as $row) {
            $ids = PohodaXml::text($row, 'IDS');
            if ($ids === '') {
                continue;
            }
            $catalog[$ids][] = [
                'text' => PohodaXml::text($row, 'SText'),
                'umd' => PohodaXml::text($row, 'UMD'),
                'ud' => PohodaXml::text($row, 'UD'),
            ];
        }

        return $catalog;
    }

    /** @return array<string,string> původní účet => jeho název */
    private static function readAccountNames(string $file): array
    {
        $names = [];
        foreach (PohodaXml::records($file, 'pOS') as $row) {
            $code = PohodaXml::text($row, 'Ucet');
            if ($code !== '') {
                $names[$code] = PohodaXml::text($row, 'Nazev');
            }
        }

        return $names;
    }

    /**
     * Střediska z rozúčtování, podle id řádku `MZzauct`.
     *
     * @return array<string,array<string,true>>
     */
    private static function readSplitCostCenters(string $file, ?int $year): array
    {
        $out = [];
        foreach (PohodaXml::records($file, 'MZzauctRoz') as $row) {
            if ($year !== null && (int) PohodaXml::text($row, 'Rok') !== $year) {
                continue;
            }
            $parent = PohodaXml::text($row, 'RefMZzauct');
            if ($parent === '') {
                continue;
            }
            foreach (['ResStrPomer', 'ResStrSl', 'ResStrClen'] as $field) {
                $centre = PohodaXml::text($row, $field);
                if ($centre !== '') {
                    $out[$parent][$centre] = true;
                }
            }
        }

        return $out;
    }
}
