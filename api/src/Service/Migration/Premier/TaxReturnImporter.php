<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;

/**
 * Ruční úpravy základu daně z přiznání k DPPO, které PREMIER v záloze drží (`D_PO1`,
 * `D_PO2`), do rozpracovaného přiznání MyÚčta.
 *
 * Výsledek hospodaření, odpisy a nedaňové účty spočte MyÚčto samo z převedeného deníku,
 * osnovy a majetku. Z účetnictví ale nevyplývají úpravy, které účetní zadala do přiznání
 * ručně (paušál na dopravu, příjmy osvobozené, ztráta minulých let…). Ty se převedou jako
 * ruční položky přiznání s textem, ze kterého řádku PREMIER pocházejí:
 *
 * - zvyšující: ř. 20, 30, 61, 62 a část ř. 40, kterou nedaňové účty osnovy nepokryjí;
 * - snižující: ř. 100-162 kromě ř. 150 (rozdíl odpisů spočte MyÚčto z karet majetku);
 *   ř. 112 PREMIER používá pro paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), takže se
 *   položka i s odpovídajícím vrácením PHM na ř. 40 označí jako paušál na dopravu;
 * - odečet ztráty (ř. 230) a zaplacené zálohy.
 *
 * Existující přiznání roku (i rozpracované) převod nemění.
 */
final class TaxReturnImporter
{
    public const STEP = 'tax_return';

    private const INCREASES = ['II_20_HODN' => '20', 'II_30_CAST' => '30', 'II_61_UPRA' => '61', 'II_62_OST2' => '62'];
    private const DECREASES = [
        'II_100_PRI' => '100', 'II_101_PRI' => '101', 'II_110_PRI' => '110', 'II_111_SNI' => '111', 'II_112_LZE' => '112',
        'II_120_PRI' => '120', 'II_130_PRI' => '130', 'II_140_PRI' => '140', 'II_160_UHR' => '160', 'II_161_UPR' => '161', 'II_162_OS2' => '162',
    ];
    private const FLAT_RATE_TRAVEL_LINE = '112';

    public function __construct(
        private readonly TaxReturnRepository $returns,
        private readonly DppoReturnDataProvider $data,
        private readonly PremierImportRepository $map,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $values = self::premierReturn($ctx->backup, $ctx->year);
        if ($values === null) {
            $p->finish(self::STEP);
            return;
        }
        $existing = $this->returns->find($ctx->supplierId, $ctx->year, 'po');
        if ($existing !== null) {
            $p->count(self::STEP, 'kept');
            $p->info(self::STEP, 'return_exists', "Přiznání k DPPO za rok {$ctx->year} už ve firmě je, úpravy z PREMIER se do něj nepřebírají.");
            $p->finish(self::STEP);
            return;
        }
        $travel = (float) ($values['II_112_LZE'] ?? 0) > 0.0;
        $increase = [];
        $decrease = [];
        foreach (self::INCREASES as $column => $line) {
            $amount = round((float) ($values[$column] ?? 0), 2);
            if ($amount > 0.0) {
                $increase[] = ['text' => "Úprava základu z přiznání v PREMIER (ř. {$line})", 'amount' => $amount];
            }
        }
        $data = $this->data->gather($ctx->supplierId, $ctx->year, []);
        $computed = round((float) ($data['non_deductible_costs'] ?? 0) + (float) ($data['disposal_nondeductible_residual'] ?? 0), 2);
        $line40 = round((float) ($values['II_40_VYDA'] ?? 0) - $computed, 2);
        if ($line40 > 0.0) {
            $item = ['text' => $travel ? 'Vrácení PHM do základu u paušálu na dopravu (ř. 40 z PREMIER)' : 'Nedaňové výdaje z přiznání v PREMIER (ř. 40)', 'amount' => $line40];
            if ($travel) {
                $item['kind'] = DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL;
            }
            $increase[] = $item;
        } elseif ($line40 < 0.0) {
            $p->warn(self::STEP, 'line40_higher', sprintf('Nedaňové náklady podle osnovy (%s Kč) jsou vyšší než ř. 40 přiznání v PREMIER (%s Kč). Zkontrolujte daňovou uznatelnost účtů.',
                number_format($computed, 2, ',', ' '), number_format((float) ($values['II_40_VYDA'] ?? 0), 2, ',', ' ')));
        }
        foreach (self::DECREASES as $column => $line) {
            $amount = round((float) ($values[$column] ?? 0), 2);
            if ($amount <= 0.0) {
                continue;
            }
            $item = ['text' => $line === self::FLAT_RATE_TRAVEL_LINE
                ? 'Paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), ř. 112 z PREMIER'
                : "Úprava základu z přiznání v PREMIER (ř. {$line})", 'amount' => $amount];
            if ($line === self::FLAT_RATE_TRAVEL_LINE) {
                $item['kind'] = DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL;
            }
            $decrease[] = $item;
        }
        $inputs = [];
        if ($increase !== []) {
            $inputs['manual_increase_items'] = $increase;
        }
        if ($decrease !== []) {
            $inputs['manual_decrease_items'] = $decrease;
        }
        $loss = round((float) ($values['II_230_ODE'] ?? 0), 2);
        if ($loss > 0.0) {
            $inputs['loss_carryforward'] = $loss;
        }
        $advances = round((float) ($values['V_1_NA_ZAL'] ?? 0), 2);
        if ($advances > 0.0) {
            $inputs['tax_paid_advances'] = $advances;
        }
        foreach (['II_240_ODE' => '240', 'II_242_ODE' => '242', 'II_300_SLE' => '300'] as $column => $line) {
            if ((float) ($values[$column] ?? 0) > 0.0) {
                $p->warn(self::STEP, 'manual_line', "Přiznání v PREMIER má vyplněný ř. {$line} (odpočty, slevy) - v MyÚčtu ho doplňte ručně v přiznání k DPPO.", ['line' => $line]);
            }
        }
        if ($inputs === []) {
            $p->finish(self::STEP);
            return;
        }
        $row = $this->returns->create($ctx->supplierId, $ctx->year, 'po', $inputs, $ctx->userOrNull());
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_TAX_RETURN, (string) $ctx->year, (int) $row['id'], $ctx->runId);
        $p->setCount(self::STEP, 'increase_items', count($increase));
        $p->setCount(self::STEP, 'decrease_items', count($decrease));
        $p->info(self::STEP, 'return_created', "Rozpracované přiznání k DPPO {$ctx->year} dostalo ruční úpravy základu z PREMIER: "
            . implode('; ', array_map(static fn (array $i): string => $i['text'] . ' ' . number_format($i['amount'], 0, ',', ' ') . ' Kč', array_merge($increase, $decrease))) . '.');
        $p->finish(self::STEP);
    }

    /**
     * Přiznání roku z PREMIER: hlavička `D_PO1` + hodnoty `D_PO2` (poslední podání roku).
     *
     * @return array<string,mixed>|null
     */
    public static function premierReturn(PremierBackup $backup, int $year): ?array
    {
        $head = null;
        foreach ($backup->rows('D_PO1') as $h) {
            if ((int) ($h['ROK'] ?? 0) === $year) {
                $head = $h;
            }
        }
        if ($head === null) {
            return null;
        }
        foreach ($backup->rows('D_PO2') as $r) {
            if (trim((string) ($r['ID_CISLO'] ?? '')) === trim((string) $head['ID_CISLO'])) {
                return $r + $head;
            }
        }
        return null;
    }
}
