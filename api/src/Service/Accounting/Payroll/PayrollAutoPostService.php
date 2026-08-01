<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use PDO;

/**
 * Automatické měsíční zaúčtování mezd (migrace 1175) — logika `cron-payroll-post`.
 *
 * Účtování samotné dělá {@see PayrollPostingService::post()}; tahle třída jen vybírá
 * KOHO a ZA JAKÝ MĚSÍC a rozhoduje, co se přeskočí. Je oddělená od cron skriptu proto,
 * aby šla otestovat bez spouštění procesu — cron entrypoint je tenký wrapper kolem ní.
 *
 * ── Který měsíc se účtuje ───────────────────────────────────────────────────────────
 * Běh 1. dne v měsíci účtuje měsíc PŘEDCHOZÍ: k tomu dni jsou už všechna jeho data
 * známá. Datum účetního případu zůstává {@see PayrollPostingService::entryDate()},
 * tj. poslední den účtovaného měsíce, takže zápis padne do správného období bez ohledu
 * na to, kdy cron doopravdy proběhl (dohnaný výpadek účtuje pořád do téhož měsíce).
 *
 * ── Co se PŘESKOČÍ a proč ───────────────────────────────────────────────────────────
 * Automat nesmí přepsat nic, co nezaložil sám:
 *
 *   STATUS_ALREADY   — za zaměstnance a měsíc už mzdový záznam existuje. To je běžný
 *                      stav druhého běhu (idempotence) i případ, kdy měsíc zaúčtovala
 *                      ručně účetní s jinou částkou (nemoc, odměna). Přepočítat to
 *                      zpátky na deklarovanou mzdu by ruční opravu tiše zahodilo.
 *   STATUS_CONFLICT  — zápis mzdové rekapitulace za ten měsíc už v deníku je, ale
 *                      k tomuhle zaměstnanci nepatří. Viz níž, proč se to nedá slučovat.
 *   STATUS_ERROR     — uzavřené období, chybějící období, zamčené datum… Chyba jednoho
 *                      zaměstnance NESMÍ shodit celý běh, proto se chytá per kus.
 *
 * ── Proč víc zaměstnanců naráz automaticky NEJDE ────────────────────────────────────
 * Rekapitulace se účtuje jako `source_type='manual'`, `source_id` = RRRRMM a unikát
 * `uq_je_supplier_source` drží NEJVÝŠ JEDEN zápis na dodavatele a měsíc. Druhé volání
 * `post()` proto existující zápis nepřičte, ale PŘEPÍŠE — u dvou zaměstnanců by v deníku
 * zůstala jen mzda toho druhého a náklad firmy by byl podhodnocený o celou první mzdu.
 * Mlčky vyrobit špatný zápis je horší než nezaúčtovat: druhý a další zaměstnanec proto
 * skončí jako STATUS_CONFLICT a je vidět v reportu i v Systém → Plánované úlohy.
 * (Skutečné řešení je zápis per zaměstnanec, tedy jiný tvar `source_id` — to je ale
 * změna idempotence celé rekapitulace včetně už zaúčtované historie, ne úprava cronu.)
 */
final class PayrollAutoPostService
{
    public const STATUS_POSTED = 'posted';
    public const STATUS_ALREADY = 'already';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_DRY_RUN = 'dry_run';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployeeRepository $employees,
        private readonly PayrollMonthlyRecordRepository $records,
        private readonly JournalEntryRepository $journal,
        private readonly PayrollPostingService $posting,
    ) {}

    /**
     * Rok a měsíc, který se k danému dni účtuje = měsíc předcházející.
     *
     * @return array{0:int, 1:int}
     */
    public static function periodFor(\DateTimeImmutable $today): array
    {
        // `first day of this month` PŘED odečtením měsíce: bez toho by 31. 3. minus měsíc
        // přeteklo na 3. 3. (PHP nemá kratší únor kam zaokrouhlit) a účtoval by se březen.
        $target = $today->modify('first day of this month')->modify('-1 month');

        return [(int) $target->format('Y'), (int) $target->format('n')];
    }

    /**
     * Dodavatelé v podvojném účetnictví — jen ti mzdovou rekapitulaci mají
     * (shodná brána jako `PayrollAction::requireDoubleEntry`).
     *
     * @return list<int>
     */
    public function doubleEntrySupplierIds(): array
    {
        $rows = $this->db->pdo()
            ->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows ?: []);
    }

    /**
     * Zaúčtuje mzdy jednoho dodavatele za daný měsíc.
     *
     * @param array<string,mixed> $meta auditní meta pro PostingService
     * @return array{
     *   supplier_id:int, year:int, month:int, candidates:int,
     *   posted:int, already:int, conflicts:int, errors:int,
     *   items:list<array<string,mixed>>
     * }
     */
    public function runForSupplier(
        int $supplierId,
        int $year,
        int $month,
        bool $dryRun = false,
        array $meta = [],
    ): array {
        $candidates = $this->employees->autoPostCandidates($supplierId);
        $sourceId = PayrollPostingService::sourceId($year, $month);

        $report = [
            'supplier_id' => $supplierId,
            'year'        => $year,
            'month'       => $month,
            'candidates'  => count($candidates),
            'posted'      => 0,
            'already'     => 0,
            'conflicts'   => 0,
            'errors'      => 0,
            'items'       => [],
        ];

        // Zápis za měsíc si v tomhle běhu už někdo nárokoval. Drží se zvlášť od dotazu
        // do deníku kvůli `--dry-run`: tam se nic nezapíše, takže by druhý zaměstnanec
        // vypadal jako bezproblémový, ačkoli ostrý běh by na něm skončil konfliktem.
        $claimed = false;

        foreach ($candidates as $employee) {
            $employeeId = (int) $employee['id'];
            $gross = (int) $employee['monthly_gross'];
            $item = [
                'employee_id' => $employeeId,
                'name'        => (string) $employee['full_name'],
                'gross'       => $gross,
                'status'      => self::STATUS_POSTED,
            ];

            $recorded = $this->records->grossForMonth($supplierId, $employeeId, $year, $month);
            if ($recorded !== null) {
                $item['status'] = self::STATUS_ALREADY;
                $item['message'] = sprintf('Mzda za %02d/%04d už je zaevidovaná (%d Kč).', $month, $year, (int) $recorded);
                $report['already']++;
                $report['items'][] = $item;
                continue;
            }

            // Zápis rekapitulace za měsíc už existuje, ale tomuhle zaměstnanci nepatří
            // (ad-hoc zaúčtování bez vazby na kartu, nebo druhý zaměstnanec v témže běhu).
            // `post()` by ho přepsal — viz docblock třídy.
            $existing = $this->journal->findBySource($supplierId, 'manual', $sourceId);
            if ($claimed || $existing !== null) {
                $item['status'] = self::STATUS_CONFLICT;
                if ($existing !== null) {
                    $item['journal_entry_id'] = (int) $existing['id'];
                }
                $item['message'] = sprintf(
                    'Za %02d/%04d už je mzdová rekapitulace zaúčtovaná pro jiného zaměstnance%s — systém '
                        . 'drží jeden zápis na měsíc, takže automat by ji přepsal. Zaúčtuj tuhle mzdu ručně.',
                    $month,
                    $year,
                    $existing !== null ? sprintf(' (zápis #%d)', (int) $existing['id']) : '',
                );
                $report['conflicts']++;
                $report['items'][] = $item;
                continue;
            }

            if ($dryRun) {
                $item['status'] = self::STATUS_DRY_RUN;
                $claimed = true;
                $report['items'][] = $item;
                continue;
            }

            try {
                $res = $this->posting->post(
                    $supplierId,
                    $year,
                    $month,
                    (float) $gross,
                    (string) $employee['taxpayer_type'],
                    $meta,
                    $employeeId,
                );
                $item['journal_entry_id'] = (int) $res['journal_entry_id'];
                $claimed = true;
                $report['posted']++;
            } catch (\Throwable $e) {
                $item['status'] = self::STATUS_ERROR;
                $item['message'] = $e->getMessage();
                $report['errors']++;
            }

            $report['items'][] = $item;
        }

        return $report;
    }
}
