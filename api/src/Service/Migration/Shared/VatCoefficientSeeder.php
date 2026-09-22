<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Report\DphPriznaniBuilder;

/**
 * Koeficient krácení odpočtu (§ 76) převedených let - pro kterýkoli převod.
 *
 * Přiznání s kráceným odpočtem (ř. 52) se bez zálohového koeficientu sestavit nedá a cizí
 * programy ho v záloze nedrží tak, aby šel spolehlivě přečíst. Převod proto každý
 * uzavíraný rok vypořádá koeficientem spočteným z převedených dokladů - stejným výpočtem
 * jako ruční vypořádání v MyÚčtu ({@see DphPriznaniBuilder::computeAnnualCoefficient()}) -
 * a zálohový koeficient dalšího roku se z něj převezme sám (§ 76 odst. 6). Prvnímu
 * převedenému roku chybí předchozí rok: dostane jako zálohový svůj vlastní vypořádací
 * koeficient a protokol na to upozorní.
 *
 * Jen u firmy s kráceným odpočtem; rok, který už koeficient má, se nepřepisuje. Poslední
 * (otevřený) rok se nevypořádává - to je úkon účetní na konci roku.
 */
final class VatCoefficientSeeder
{
    public const STEP = 'vat_coefficients';

    public function __construct(
        private readonly Connection $db,
        private readonly VatCoefficientRepository $coefficients,
        private readonly DphPriznaniBuilder $dph,
    ) {}

    /** @param list<int> $years převedené roky (v libovolném pořadí) */
    public function seed(int $supplierId, array $years, int $userId, ImportProtocol $p): void
    {
        sort($years);
        if ($years === [] || !$this->hasReducedDeduction($supplierId)) {
            return;
        }
        $last = max($years);
        foreach ($years as $i => $year) {
            if ($i === 0 && $this->coefficients->resolveProvisionalPercent($supplierId, $year) === null) {
                $own = $this->dph->computeAnnualCoefficient($supplierId, $year);
                $this->coefficients->setProvisionalPercent($supplierId, $year, $own['final_percent']);
                $p->warn(self::STEP, 'provisional_from_own_year', sprintf(
                    'Zálohový koeficient § 76 roku %d nejde odvodit z předchozího roku (v převodu není); nastaven vypořádací koeficient roku %d, %d %%. Ověřte ho podle podaného přiznání.',
                    $year, $year, $own['final_percent']
                ), ['year' => $year, 'percent' => $own['final_percent']]);
            }
            if ($year === $last || ($this->coefficients->get($supplierId, $year)['settled_at'] ?? null) !== null) {
                continue;
            }
            $c = $this->dph->computeAnnualCoefficient($supplierId, $year);
            $this->coefficients->settleYear($supplierId, $year, $c['final_percent'], $c['numerator'], $c['denominator'], $userId > 0 ? $userId : null);
            $p->count(self::STEP, 'settled');
            $p->info(self::STEP, 'settled', sprintf('Koeficient § 76 za rok %d: %d %%.', $year, $c['final_percent']), ['year' => $year, 'percent' => $c['final_percent']]);
        }
    }

    private function hasReducedDeduction(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND vat_deduction = 'reduced')
                 OR EXISTS (SELECT 1 FROM cash_document_vat_lines l
                              JOIN cash_documents d ON d.id = l.cash_document_id
                             WHERE d.supplier_id = ? AND l.vat_deduction = 'reduced')"
        );
        $stmt->execute([$supplierId, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }
}
