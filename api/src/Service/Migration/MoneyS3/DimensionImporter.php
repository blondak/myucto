<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;

/**
 * Středisko a zakázka z deníku Money do dimenzí MyÚčta (Firma → Dimenze).
 *
 * - `Stred` → hodnota typu Středisko (firemní) navázaná na číselník `cost_centers`;
 *   textový kód střediska na řádku deníku zůstává jako dosud.
 * - `Zakazka` ve tvaru registrační značky → hodnota typu Vozidlo (firemní) navázaná
 *   na vůz z knihy jízd, je-li tam; různé zápisy téže značky jsou jedna hodnota
 *   ({@see Ms3Journal::jobCode()}).
 * - Ostatní `Zakazka` → hodnota typu Projekt. Patří-li firma do skupiny firem, je
 *   Projekt globální: stejný kód zakázky v mateřské firmě i v SPV je jeden projekt.
 * - `Cinnost` Money skupina nepoužívá, nepřevádí se.
 *
 * Převod dimenze u firmy zapne. Hodnota, kterou poslední převáděný rok nepoužil,
 * vznikne uzavřená; hodnotu, kterou poslední rok použil, převod znovu otevře.
 * Existující hodnotu převod nikdy nezavírá.
 *
 * Dimenze se razítkují do všech zápisů převzatých z Money, i do let uzavřených
 * dřívějším převodem: jde o analytické členění, obraty ani výkazy se nemění. Pro
 * řádky z Money je rozhodující Money — opakovaný převod přepíše ruční změnu typů,
 * které převod spravuje.
 *
 * Doklad (přijatá a vydaná faktura, pokladní doklad, bankovní pohyb) dostane hodnotu
 * do hlavičky, když všechny řádky jeho zápisů s tímto typem nesou tutéž. Hlavičku,
 * kterou už někdo vyplnil, převod nemění.
 */
final class DimensionImporter
{
    public const STEP = 'dimensions';

    private const BATCH = 1000;

    /** Zdroj zápisu v deníku => typ dokladu v document_dimensions. */
    private const DOCUMENT_SOURCES = [
        'purchase_invoice' => 'purchase_invoice',
        'invoice' => 'invoice',
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly JournalImporter $journal,
        private readonly CostCenterRepository $costCenters,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $lines = $this->journal->lineDimensions($ctx);

        $centers = [];
        $jobs = [];
        foreach ($lines as $key => $byLine) {
            $year = (int) explode('|', $key, 2)[0];
            foreach ($byLine as $no => $d) {
                // Řádek Money = dva řádky deníku (MD a D); počítá se jednou.
                if ($no % 2 === 0) {
                    continue;
                }
                if ($d['stred'] !== null) {
                    $centers[$d['stred']] = self::used($centers[$d['stred']] ?? null, $year);
                }
                if ($d['zakazka'] !== null) {
                    $jobs[$d['zakazka']] = self::used($jobs[$d['zakazka']] ?? null, $year);
                }
            }
        }
        if ($centers === [] && $jobs === []) {
            $p->finish(self::STEP);
            return;
        }
        $lastYear = $ctx->dirYears === [] ? 0 : max($ctx->dirYears);

        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $types = [];
        if ($centers !== []) {
            $types['center'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, ['stredisko'])['cost_center'];
        }
        $vehicles = array_filter(array_keys($jobs), static fn ($c): bool => Ms3Journal::vehiclePlate((string) $c) !== null);
        if ($vehicles !== []) {
            $types['vehicle'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, ['vozidlo'])['vehicle'];
        }
        if (count($vehicles) < count($jobs)) {
            $types['project'] = $this->dimensionService->ensureDefaultTypes($ctx->supplierId, ['projekt'])['project'];
        }

        // Číselný kód (středisko `633585`) z klíče pole vyjde jako int.
        $centerValues = [];
        foreach ($centers as $code => $use) {
            $code = (string) $code;
            $centerValues[$code] = $this->value($ctx, $types['center'], 'cost_center', $code, $use['last'] >= $lastYear, [
                'cost_center_id' => $this->costCenter($ctx, $code),
            ]);
        }
        $jobValues = [];
        foreach ($jobs as $code => $use) {
            $code = (string) $code;
            $vehicle = Ms3Journal::vehiclePlate($code) !== null;
            $typeId = $vehicle ? $types['vehicle'] : $types['project'];
            $extra = [];
            if ($vehicle) {
                $car = $this->car($ctx, $code);
                $extra = ['car_id' => $car['id'] ?? null, 'name' => $car !== null && $car['name'] !== '' ? $code . ' - ' . $car['name'] : $code];
            }
            $jobValues[$code] = [$typeId, $this->value($ctx, $typeId, $vehicle ? 'vehicle' : 'project', $code, $use['last'] >= $lastYear, $extra)];
        }

        $managed = array_values($types);
        $stamped = $this->stampLines($ctx, $lines, $types['center'] ?? null, $centerValues, $jobValues, $managed);
        $p->count(self::STEP, 'lines', $stamped);
        $documents = $this->stampDocuments($ctx, $managed);
        $p->count(self::STEP, 'documents', $documents);

        $p->info(self::STEP, 'summary', sprintf(
            'Střediska: %d (%d řádků Money), zakázky: %d (%d řádků Money), z toho vozidla: %d. Dimenze jsou zapnuté v Firma → Dimenze.',
            count($centers),
            array_sum(array_column($centers, 'rows')),
            count($jobs),
            array_sum(array_column($jobs, 'rows')),
            count($vehicles),
        ));
        $p->finish(self::STEP);
    }

    /**
     * @param array{rows:int,last:int}|null $use
     * @return array{rows:int,last:int}
     */
    private static function used(?array $use, int $year): array
    {
        return ['rows' => ($use['rows'] ?? 0) + 1, 'last' => max($use['last'] ?? 0, $year)];
    }

    private function costCenter(ImportContext $ctx, string $code): int
    {
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_COST_CENTER, $code);
        if ($mapped !== null) {
            return $mapped;
        }
        $existing = $this->costCenters->findByCode($ctx->supplierId, $code);
        if ($existing !== null) {
            $id = (int) $existing['id'];
        } else {
            $id = $this->costCenters->create($ctx->supplierId, $code, $code);
            $ctx->protocol->count(self::STEP, 'cost_centers_created');
        }
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_COST_CENTER, $code, $id, $ctx->runId);
        return $id;
    }

    /**
     * Hodnota dimenze pro kód z Money: z mapy převodu, podle kódu v typu (globální
     * projekt mohla založit jiná firma skupiny), nebo nová.
     *
     * @param array<string,mixed> $extra
     */
    private function value(ImportContext $ctx, int $typeId, string $kind, string $code, bool $active, array $extra): int
    {
        $key = $kind . ':' . $code;
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key);
        $existing = $mapped !== null
            ? $this->dimensions->findValue($ctx->supplierId, $mapped)
            : $this->dimensions->findValueByCode($typeId, mb_substr($code, 0, 50));
        if ($existing !== null && (int) $existing['type_id'] === $typeId) {
            $id = (int) $existing['id'];
            if ($active && !$existing['is_active']) {
                $this->dimensions->updateValue($ctx->supplierId, $id, ['is_active' => true]);
            }
            $ctx->protocol->count(self::STEP, 'values_existing');
        } else {
            $type = $this->dimensions->findType($ctx->supplierId, $typeId);
            $id = $this->dimensions->createValue((array) $type, [
                'code' => mb_substr($code, 0, 50),
                'name' => mb_substr((string) ($extra['name'] ?? $code), 0, 190),
                'is_active' => $active,
                'car_id' => $extra['car_id'] ?? null,
                'cost_center_id' => $type !== null && $type['level'] === 'company' ? ($extra['cost_center_id'] ?? null) : null,
            ]);
            $ctx->protocol->count(self::STEP, 'values_created');
        }
        if ($mapped === null) {
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key, $id, $ctx->runId);
        } elseif ($mapped !== $id) {
            $this->map->repoint($ctx->supplierId, MoneyS3ImportRepository::KIND_DIMENSION_VALUE, $key, $id, $ctx->runId);
        }
        return $id;
    }

    /** @return array{id:int,name:string}|null vůz z knihy jízd podle registrační značky */
    private function car(ImportContext $ctx, string $plate): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, TRIM(CONCAT_WS(' ', NULLIF(name, ''), NULLIF(brand, ''), NULLIF(model, ''))) AS name
               FROM cars WHERE supplier_id = ? AND REPLACE(UPPER(registration), ' ', '') = ?
              ORDER BY is_archived, id LIMIT 1"
        );
        $stmt->execute([$ctx->supplierId, str_replace(' ', '', $plate)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'name' => trim((string) $row['name'])];
    }

    /**
     * @param array<string,array<int,array{stred:?string,zakazka:?string}>> $lines
     * @param array<string,int> $centerValues
     * @param array<string,array{0:int,1:int}> $jobValues kód => [typ, hodnota]
     * @param list<int> $managedTypes typy, které převod na řádcích z Money spravuje
     * @return int počet změněných vazeb řádek–dimenze
     */
    private function stampLines(ImportContext $ctx, array $lines, ?int $centerType, array $centerValues, array $jobValues, array $managedTypes): int
    {
        $pdo = $this->db->pdo();
        $entries = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY);
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_money_s3_dimensions');
        $pdo->exec(
            'CREATE TEMPORARY TABLE tmp_money_s3_dimensions (
                entry_id BIGINT UNSIGNED NOT NULL,
                line_no INT UNSIGNED NOT NULL,
                cost_center VARCHAR(50) NULL,
                center_type BIGINT UNSIGNED NULL,
                center_value BIGINT UNSIGNED NULL,
                job_type BIGINT UNSIGNED NULL,
                job_value BIGINT UNSIGNED NULL,
                PRIMARY KEY (entry_id, line_no)
            ) ENGINE=InnoDB'
        );
        $batch = [];
        $flush = static function () use (&$batch, $pdo): void {
            if ($batch === []) {
                return;
            }
            $pdo->prepare(
                'INSERT INTO tmp_money_s3_dimensions (entry_id, line_no, cost_center, center_type, center_value, job_type, job_value) VALUES '
                . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?)'))
            )->execute(array_merge(...$batch));
            $batch = [];
        };
        foreach ($lines as $key => $byLine) {
            $entryId = $entries[$key] ?? null;
            if ($entryId === null) {
                continue;
            }
            foreach ($byLine as $no => $d) {
                $center = $d['stred'] !== null ? ($centerValues[$d['stred']] ?? null) : null;
                $job = $d['zakazka'] !== null ? ($jobValues[$d['zakazka']] ?? null) : null;
                $batch[] = [
                    $entryId, $no, $d['stred'],
                    $center !== null ? $centerType : null, $center,
                    $job[0] ?? null, $job[1] ?? null,
                ];
                if (count($batch) >= self::BATCH) {
                    $flush();
                }
            }
        }
        $flush();

        $count = 0;
        // Textový kód střediska zůstává na řádku jako dosud (mzdy a starší sestavy).
        $stmt = $pdo->prepare(
            'UPDATE journal_entry_lines jel
               JOIN tmp_money_s3_dimensions t ON t.entry_id = jel.entry_id AND t.line_no = jel.line_no
                SET jel.cost_center = t.cost_center
              WHERE jel.supplier_id = ? AND NOT (jel.cost_center <=> t.cost_center)'
        );
        $stmt->execute([$ctx->supplierId]);

        if ($managedTypes !== []) {
            $marks = implode(',', array_fill(0, count($managedTypes), '?'));
            $delete = $pdo->prepare(
                "DELETE jd FROM journal_entry_line_dimensions jd
                   JOIN journal_entry_lines jel ON jel.id = jd.line_id AND jel.supplier_id = jd.supplier_id
                   JOIN tmp_money_s3_dimensions t ON t.entry_id = jel.entry_id AND t.line_no = jel.line_no
                  WHERE jd.supplier_id = ? AND jd.dimension_type_id IN ({$marks})
                    AND NOT (jd.dimension_type_id <=> t.center_type AND jd.dimension_value_id <=> t.center_value)
                    AND NOT (jd.dimension_type_id <=> t.job_type AND jd.dimension_value_id <=> t.job_value)"
            );
            $delete->execute([$ctx->supplierId, ...$managedTypes]);
            $count += $delete->rowCount();
        }
        foreach (['center', 'job'] as $col) {
            $insert = $pdo->prepare(
                "INSERT IGNORE INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id)
                 SELECT jel.id, t.{$col}_type, jel.supplier_id, t.{$col}_value
                   FROM tmp_money_s3_dimensions t
                   JOIN journal_entry_lines jel ON jel.entry_id = t.entry_id AND jel.line_no = t.line_no
                  WHERE jel.supplier_id = ? AND t.{$col}_value IS NOT NULL"
            );
            $insert->execute([$ctx->supplierId]);
            $count += $insert->rowCount();
        }
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_money_s3_dimensions');
        return $count;
    }

    /**
     * @param list<int> $managedTypes
     * @return int počet doplněných hlaviček (doklad × typ)
     */
    private function stampDocuments(ImportContext $ctx, array $managedTypes): int
    {
        // STRAIGHT_JOIN: čerstvě naplněná vazební tabulka nemá statistiky a optimalizátor
        // jinak volí průchod dimenzemi celé firmy pro každý zápis (desítky minut).
        if ($managedTypes === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($managedTypes), '?'));
        $count = 0;
        foreach (self::DOCUMENT_SOURCES as $sourceType => $docType) {
            $stmt = $this->db->pdo()->prepare(
                "INSERT IGNORE INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
                 SELECT ?, ?, x.source_id, 0, x.type_id, x.value_id
                   FROM (SELECT je.source_id, jd.dimension_type_id AS type_id, MIN(jd.dimension_value_id) AS value_id
                           FROM journal_entries je
                           STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                           STRAIGHT_JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
                          WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IS NOT NULL
                            AND jd.dimension_type_id IN ({$marks})
                          GROUP BY je.source_id, jd.dimension_type_id
                         HAVING COUNT(DISTINCT jd.dimension_value_id) = 1) x"
            );
            $stmt->execute([$ctx->supplierId, $docType, $ctx->supplierId, $sourceType, ...$managedTypes]);
            $count += $stmt->rowCount();
        }
        return $count;
    }
}
