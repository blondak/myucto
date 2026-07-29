<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Číselník sazeb DPH členských států pro OSS (§ 110 a násl. ZDPH).
 *
 * Systém žádný neměl: sazbu pro zemi spotřeby si uživatel zakládal ručně v obecné tabulce
 * `vat_rates` a jediná kontrola byla vnitřní konzistence `základ × sazba ≈ daň`. Použití
 * německých 19 % na rakouské plnění tedy prošlo bez varování — čísla si odpovídala, jen
 * mířila do nesprávného státu.
 *
 * ── Varuje, neblokuje ───────────────────────────────────────────────────────────────
 * Seed číselníku je platný ke dni migrace a nevyhnutelně zestárne. Tvrdé odmítnutí by po
 * první změně sazby v kterémkoli členském státě znemožnilo vystavit legitimní doklad —
 * to je horší než dnešní stav, protože rozbité je hůř obejitelné než chybějící. Kontrola
 * proto vrací VAROVÁNÍ a uživatel si sazbu může doplnit (`is_custom`).
 *
 * ── Platnost k datu ─────────────────────────────────────────────────────────────────
 * Sazby se mění a podání se opravuje zpětně, proto se hledá vždy sazba platná K DATU
 * PLNĚNÍ. Bez toho by oprava staršího období dostala dnešní sazbu a hlásila neexistující
 * chybu.
 */
final class OssRateCodebook
{
    /** Tolerance porovnání sazby v procentních bodech (DECIMAL(5,2) vs. float). */
    private const EPSILON = 0.005;

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('oss_member_state_rates');
    }

    /**
     * Sazby platné pro zemi k datu.
     *
     * @return list<array{rate_type:string, rate_percent:float}>
     */
    public function ratesFor(string $country, string $onDate): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate_type, rate_percent
               FROM oss_member_state_rates
              WHERE country = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
           ORDER BY rate_percent DESC'
        );
        $stmt->execute([strtoupper($country), $onDate, $onDate]);

        return array_map(static fn ($r) => [
            'rate_type'    => (string) $r['rate_type'],
            'rate_percent' => (float) $r['rate_percent'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Ověří sazbu použitou na OSS řádku proti číselníku. Vrací varování, nebo `null`,
     * je-li vše v pořádku (nebo nelze-li ověřit).
     *
     * @param ?string $rateType deklarovaný typ sazby (standard/reduced/…)
     */
    public function checkRate(string $country, float $rate, ?string $rateType, string $onDate): ?string
    {
        $country = strtoupper(trim($country));
        if ($country === '' || $country === '??') {
            return null; // Chybějící zemi hlásí jiné varování, nedubluj ho.
        }

        $known = $this->ratesFor($country, $onDate);
        if ($known === []) {
            // Stát v číselníku není (nový členský stát, neúplný seed). Mlčet by budilo
            // dojem, že sazba byla ověřena — a ona nebyla.
            return sprintf(
                'Sazbu %s %% pro %s nelze ověřit — stát není v číselníku sazeb členských států. '
                    . 'Doplňte jeho sazby, nebo ověřte plnění ručně.',
                self::fmt($rate),
                $country,
            );
        }

        $match = null;
        foreach ($known as $k) {
            if (abs($k['rate_percent'] - $rate) <= self::EPSILON) {
                $match = $k;
                break;
            }
        }

        if ($match === null) {
            return sprintf(
                'Sazba %s %% neodpovídá žádné sazbě platné v %s k %s (platné: %s). '
                    . 'Ověřte sazbu státu spotřeby — číselník nemusí být aktuální.',
                self::fmt($rate),
                $country,
                (new \DateTimeImmutable($onDate))->format('j. n. Y'),
                implode(', ', array_map(static fn ($k) => self::fmt($k['rate_percent']) . ' %', $known)),
            );
        }

        // Sazba sedí, ale je deklarovaná jako jiný typ — do podání jde typ, ne procento
        // ({@see OssXmlExporter::rateTypeCode()}), takže rozpor by skončil ve výkazu.
        if ($rateType !== null && $rateType !== '' && $rateType !== $match['rate_type']) {
            return sprintf(
                'Sazba %s %% je v %s vedena jako „%s", ale doklad ji deklaruje jako „%s" — '
                    . 'do podání se posílá TYP sazby, ne procento.',
                self::fmt($rate),
                $country,
                $match['rate_type'],
                $rateType,
            );
        }

        return null;
    }

    private static function fmt(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',');
    }
}
