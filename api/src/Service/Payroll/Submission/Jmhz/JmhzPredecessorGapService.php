<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use PDO;

/**
 * Převzaté měsíce, za které NIKDO nepodal měsíční hlášení JMHZ.
 *
 * Převzatý měsíc (běh `takeover`) zpracoval předchozí program a MyÚčto ho samo
 * nepočítá, takže za něj ani nepřipraví hlášení. Když předchozí program hlášení
 * neodeslal (nebo ho odeslat nestihl, protože se mezitím posunul začátek vedení
 * mezd), povinnost dál trvá — a Měsíční přehled dřív tvrdil „žádná otevřená
 * položka". Jediné pravidlo pro Měsíční přehled, hlídač termínů i panel
 * Podání předchozím programem:
 *
 *   - měsíc má nezrušený převzatý běh,
 *   - pro jeho období platí lhůta JMHZ (starší měsíce hlášení nemají),
 *   - v historii předchozího programu za něj není ODESLANÉ řádné/opravné hlášení,
 *   - MyÚčto za něj nemá vlastní povinnost JMHZ v evidenci podání.
 *
 * Jen ostré prostředí: testovací podání předchozí program nevede.
 */
final readonly class JmhzPredecessorGapService
{
    public function __construct(
        private Connection $db,
        private JmhzDeadlinePolicy $deadlines,
        private PayrollDeadlineAssessmentService $assessments,
    ) {}

    /**
     * @param ?string $period `YYYY-MM`, jen tento měsíc; `null` = všechny
     * @return list<array{
     *   period:string,earliest_submission_on:string,due_on:string,phase:string,
     *   days_to_due:int,is_overdue:bool,prepared_not_sent:bool
     * }>
     */
    public function missing(int $supplierId, string $environment, ?string $period = null): array
    {
        if ($environment !== 'production'
            || !$this->db->hasTable('payroll_external_jmhz_submissions')
        ) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(run.period_start, '%Y-%m') AS period,
                    EXISTS (
                        SELECT 1 FROM payroll_external_jmhz_submissions ext
                         WHERE ext.supplier_id = run.supplier_id AND ext.environment = ?
                           AND ext.document_kind = 'monthly'
                           AND ext.period = DATE_FORMAT(run.period_start, '%Y-%m')
                           AND ext.status = 'not_sent'
                    ) AS prepared_not_sent
               FROM payroll_runs run
              WHERE run.supplier_id = ? AND run.run_kind = 'takeover' AND run.status <> 'cancelled'
                AND (? IS NULL OR run.period_start = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_external_jmhz_submissions ext
                     WHERE ext.supplier_id = run.supplier_id AND ext.environment = ?
                       AND ext.document_kind = 'monthly'
                       AND ext.period = DATE_FORMAT(run.period_start, '%Y-%m')
                       AND ext.status = 'sent'
                       AND (ext.submission_type IS NULL OR ext.submission_type <> 'S')
                )
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_obligations obligation
                     WHERE obligation.supplier_id = run.supplier_id AND obligation.environment = ?
                       AND obligation.agenda_code = ?
                       AND obligation.period_start = run.period_start
                       AND obligation.status <> 'cancelled'
                )
              GROUP BY run.period_start
              ORDER BY run.period_start"
        );
        $periodStart = $period === null ? null : $period . '-01';
        $stmt->execute([
            $environment,
            $supplierId,
            $periodStart,
            $periodStart,
            $environment,
            $environment,
            JmhzSubmissionBridgeService::AGENDA_CODE,
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month = (string) $row['period'];
            try {
                $window = $this->deadlines->forPeriod($month . '-01');
            } catch (\Throwable) {
                // Měsíc bez účinné lhůty JMHZ hlášení nemá.
                continue;
            }
            $assessment = $this->assessments->assess($window->earliestSubmissionOn, $window->dueOn, 'open', null);
            $out[] = [
                'period' => $month,
                'earliest_submission_on' => $window->earliestSubmissionOn,
                'due_on' => $window->dueOn,
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'prepared_not_sent' => (bool) $row['prepared_not_sent'],
            ];
        }

        return $out;
    }
}
