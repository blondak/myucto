<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollYearCloseRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverLayerCheck;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use PDO;

final class PayrollYearCloseService
{
    public const BLOCKER_CODES = [
        'schema_unavailable',
        'missing_months',
        'takeover_months_missing',
        'open_corrections',
        'open_submissions',
        'open_leave',
        'open_enforcement',
        'reconciliation_differences',
    ];

    /**
     * Nálezy, které se účetní UKÁŽOU, ale rok nedrží.
     *
     * `open_liabilities` = mzdový závazek bez doloženého bankovního pohybu
     * nebo pokladního dokladu. Není to chyba účetnictví: platební příkaz
     * odchází v den výplaty, ABO výpis dorazí o týdny později a spáruje se
     * až při jeho importu. Jako blokátor to znamenalo, že se rok nedal
     * zavřít kvůli papíru, který ještě nedošel, a jediná cesta ven vedla
     * přes ruční párování. Rozhodnutí zavřít rok patří účetní — tohle je
     * podklad k němu, ne závora.
     */
    public const WARNING_CODES = [
        'open_liabilities',
        'takeover_layers_mismatch',
        'takeover_start_estimated',
    ];

    /** Kolik nedoložených závazků se vypíše jmenovitě. */
    private const WARNING_SAMPLE_LIMIT = 20;

    private const REQUIRED_TABLES = [
        'payroll_year_closures', 'payroll_module_state', 'payroll_runs', 'payroll_run_revisions',
        'payroll_submissions', 'payroll_obligations', 'payroll_payment_liabilities',
        'payroll_payment_allocations', 'payroll_payment_matches', 'payroll_absences',
        'payroll_enforcement_cases', 'payroll_enforcement_claims',
        'payroll_enforcement_ledger', 'payroll_enforcement_month_results',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollYearCloseRepository $repository,
        private readonly ActivityLogger $activity,
        private readonly PayrollPostingReconciliationService $postingReconciliation,
        private readonly PayrollTakeoverCoverage $takeover,
        private readonly PayrollTakeoverLayerCheck $takeoverLayers,
    ) {}

    /** @return array<string,mixed> */
    public function status(int $supplierId, int $year): array
    {
        self::assertYear($year);
        if (!$this->db->hasTable('payroll_year_closures')) {
            return [
                'closure' => $this->openState($supplierId, $year),
                'blockers' => [[
                    'code' => 'schema_unavailable',
                    'tables' => ['payroll_year_closures'],
                ]],
                'warnings' => [],
            ];
        }
        $closure = $this->repository->find($supplierId, $year);
        if ($closure !== null) {
            $open = $closure['status'] === 'open';

            return [
                'closure' => $closure,
                'blockers' => $open ? $this->blockers($supplierId, $year) : [],
                'warnings' => $open ? $this->warnings($supplierId, $year) : [],
            ];
        }

        return [
            'closure' => $this->openState($supplierId, $year),
            'blockers' => $this->blockers($supplierId, $year),
            'warnings' => $this->warnings($supplierId, $year),
        ];
    }

    /** @return array<string,mixed> */
    private function openState(int $supplierId, int $year): array
    {
        return [
            'id' => null,
            'supplier_id' => $supplierId,
            'calendar_year' => $year,
            'status' => 'open',
            'row_version' => 0,
            'closed_at' => null,
            'closed_by' => null,
            'reopened_at' => null,
            'reopened_by' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function close(
        int $supplierId,
        int $year,
        int $expectedRowVersion,
        ?int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        self::assertYear($year);
        self::assertExpectedVersion($expectedRowVersion);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'payroll_year_close';
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $existing = $this->repository->findForUpdate($supplierId, $year);
            if ($existing !== null && (int) $existing['row_version'] !== $expectedRowVersion) {
                throw new PayrollYearCloseConflictException('Roční uzávěrku mezitím změnil jiný uživatel.');
            }
            if ($existing !== null && $existing['status'] === 'closed') {
                $this->finishTransaction($pdo, $ownsTransaction, $savepoint);
                return $existing;
            }

            $blockers = $this->blockers($supplierId, $year);
            if ($blockers !== []) {
                throw new PayrollYearCloseBlockedException($blockers);
            }

            if ($existing === null) {
                $id = $this->repository->insertClosed($supplierId, $year, $userId);
            } elseif (!$this->repository->setStatusCas($supplierId, $year, 'closed', $expectedRowVersion, $userId)) {
                throw new PayrollYearCloseConflictException('Roční uzávěrku mezitím změnil jiný uživatel.');
            } else {
                $id = (int) $existing['id'];
            }
            $closed = $this->repository->findForUpdate($supplierId, $year);
            if ($closed === null) {
                throw new \LogicException('Roční uzávěrku se nepodařilo načíst po zápisu.');
            }
            $this->activity->log(
                'payroll.year_closed', $userId, 'payroll_year_closure', $id,
                ['calendar_year' => $year, 'row_version' => $closed['row_version']],
                $ip, $userAgent, $supplierId,
            );
            $this->finishTransaction($pdo, $ownsTransaction, $savepoint);
            return $closed;
        } catch (\PDOException $exception) {
            $this->rollbackTransaction($pdo, $ownsTransaction, $savepoint);
            if (($exception->errorInfo[0] ?? null) === '23000') {
                throw new PayrollYearCloseConflictException('Roční uzávěrku mezitím změnil jiný uživatel.', 0, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->rollbackTransaction($pdo, $ownsTransaction, $savepoint);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function reopen(
        int $supplierId,
        int $year,
        int $expectedRowVersion,
        ?int $userId,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        self::assertYear($year);
        self::assertExpectedVersion($expectedRowVersion);
        if (mb_strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Důvod znovuotevření musí mít alespoň 10 znaků.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'payroll_year_reopen';
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $existing = $this->repository->findForUpdate($supplierId, $year);
            if ($existing === null || $existing['status'] !== 'closed') {
                throw new \DomainException('Uzavřený mzdový rok nebyl nalezen.');
            }
            if ((int) $existing['row_version'] !== $expectedRowVersion
                || !$this->repository->setStatusCas($supplierId, $year, 'open', $expectedRowVersion, $userId)) {
                throw new PayrollYearCloseConflictException('Roční uzávěrku mezitím změnil jiný uživatel.');
            }
            $reopened = $this->repository->findForUpdate($supplierId, $year);
            if ($reopened === null) {
                throw new \LogicException('Roční uzávěrku se nepodařilo načíst po znovuotevření.');
            }
            $this->activity->log(
                'payroll.year_reopened', $userId, 'payroll_year_closure', (int) $reopened['id'],
                ['calendar_year' => $year, 'row_version' => $reopened['row_version'], 'reason' => trim($reason)],
                $ip, $userAgent, $supplierId,
            );
            $this->finishTransaction($pdo, $ownsTransaction, $savepoint);
            return $reopened;
        } catch (\Throwable $exception) {
            $this->rollbackTransaction($pdo, $ownsTransaction, $savepoint);
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function blockers(int $supplierId, int $year): array
    {
        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => !$this->db->hasTable($table),
        ));
        if ($missingTables !== []) {
            return [['code' => 'schema_unavailable', 'tables' => $missingTables]];
        }
        $blockers = [];
        $missingMonths = $this->repository->missingMonths($supplierId, $year);
        if ($missingMonths !== []) {
            $blockers[] = ['code' => 'missing_months', 'months' => $missingMonths];
        }
        // `missing_months` hlídá jen měsíce od začátku vedení mezd. Měsíce před
        // ním se nepočítají, ale rok je musí mít doložené převzatými úhrny —
        // jinak roční zúčtování, vyúčtování daně i roční doklady stojí na
        // neúplném roce a uzavření by ho zamklo.
        $takeoverGaps = $this->takeover->gaps($supplierId, $year);
        if ($takeoverGaps !== []) {
            $blockers[] = [
                'code' => 'takeover_months_missing',
                'count' => count($takeoverGaps),
                'people' => array_slice($takeoverGaps, 0, self::WARNING_SAMPLE_LIMIT),
            ];
        }
        foreach ([
            'open_corrections' => $this->repository->openCorrectionCount($supplierId, $year),
            'open_submissions' => $this->repository->openSubmissionCount($supplierId, $year),
            'open_leave' => $this->repository->openLeaveCount($supplierId, $year),
            'open_enforcement' => $this->repository->unresolvedEnforcementCount($supplierId, $year),
            'reconciliation_differences' => $this->reconciliationDifferenceCount($supplierId, $year),
        ] as $code => $count) {
            if ($count > 0) {
                $blockers[] = ['code' => $code, 'count' => $count];
            }
        }
        return $blockers;
    }

    /**
     * Nálezy k prohlédnutí, ne k blokování. Vrací i JMENNÝ seznam — samotné
     * číslo („5 nedoložených závazků") účetní nikam neposune, potřebuje
     * vidět, o která období a které odvody jde.
     *
     * @return list<array<string,mixed>>
     */
    private function warnings(int $supplierId, int $year): array
    {
        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => !$this->db->hasTable($table),
        ));
        if ($missingTables !== []) {
            return [];
        }
        $warnings = [];
        $count = $this->repository->openLiabilityCount($supplierId, $year);
        if ($count > 0) {
            $items = $this->repository->openLiabilities(
                $supplierId,
                $year,
                self::WARNING_SAMPLE_LIMIT,
            );
            $warnings[] = [
                'code' => 'open_liabilities',
                'count' => $count,
                'items' => $items,
                'truncated' => $count > count($items),
            ];
        }
        // Rozchod počátečních stavů a převzatých mezd rok nedrží — která strana
        // je správně, rozhodne účetní. Uzavřít ale nesmí naslepo. Patří sem i
        // měsíc jen v jedné vrstvě: bez převzaté mzdy nevznikne evidenční list
        // za rok přechodu, bez počátečního stavu s měsícem nepočítá daň.
        $layers = $this->takeoverLayers->check($supplierId, $year);
        $findings = count($layers['differences'])
            + count($layers['opening_only'])
            + count($layers['takeover_only']);
        if ($findings > 0) {
            $warnings[] = [
                'code' => 'takeover_layers_mismatch',
                'count' => $findings,
                'items' => [],
                'differences' => array_slice($layers['differences'], 0, self::WARNING_SAMPLE_LIMIT),
                'opening_only' => array_slice($layers['opening_only'], 0, self::WARNING_SAMPLE_LIMIT),
                'takeover_only' => array_slice($layers['takeover_only'], 0, self::WARNING_SAMPLE_LIMIT),
                'truncated' => count($layers['differences']) > self::WARNING_SAMPLE_LIMIT,
            ];
        }
        // Odhadnutý nástup z hlášení schová mezeru roku přechodu i před
        // blokátorem `takeover_months_missing`; tady se aspoň ukáže.
        $estimated = $this->takeover->estimatedStartGaps($supplierId, $year);
        if ($estimated !== []) {
            $warnings[] = [
                'code' => 'takeover_start_estimated',
                'count' => count($estimated),
                'items' => [],
                'estimated_starts' => array_slice($estimated, 0, self::WARNING_SAMPLE_LIMIT),
                'truncated' => count($estimated) > self::WARNING_SAMPLE_LIMIT,
            ];
        }

        return $warnings;
    }

    private function reconciliationDifferenceCount(int $supplierId, int $year): int
    {
        $count = 0;
        for ($month = 1; $month <= 12; ++$month) {
            try {
                $result = $this->postingReconciliation->forPeriod(
                    $supplierId,
                    sprintf('%04d-%02d', $year, $month),
                );
            } catch (\DomainException|\UnexpectedValueException|\JsonException) {
                ++$count;
                continue;
            }
            if (($result['overall_status'] ?? null) === 'diff') {
                ++$count;
            }
        }
        return $count;
    }

    private static function assertYear(int $year): void
    {
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }
    }

    private static function assertExpectedVersion(int $version): void
    {
        if ($version < 0) {
            throw new \InvalidArgumentException('row_version musí být celé číslo alespoň 0.');
        }
    }

    private function finishTransaction(PDO $pdo, bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackTransaction(PDO $pdo, bool $ownsTransaction, string $savepoint): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }
}
