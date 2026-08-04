<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionRepository $repository,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollDeadlineAssessmentService $deadlines,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::READ,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
            }
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
            }
            return $error;
        }

        try {
            $query = $request->getQueryParams();
            $environment = $this->environment($query['environment'] ?? null);
            [$periodStart, $periodEnd] = $this->period(
                $query['period'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $items = $this->repository->listOverview(
            $this->currentSupplierId($request),
            $environment,
            $periodStart,
            $periodEnd,
        );
        $summary = [
            'total' => count($items),
            'open' => 0,
            'prepared' => 0,
            'submitted' => 0,
            'fulfilled' => 0,
            'overdue' => 0,
            'manual_review' => 0,
            'other' => 0,
        ];
        $deadlineSummary = [
            'not_open' => 0,
            'open' => 0,
            'due_soon' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'awaiting_result' => 0,
            'fulfilled' => 0,
            'action_required' => 0,
            'cancelled' => 0,
        ];
        foreach ($items as &$item) {
            $status = $item['status'];
            if (array_key_exists($status, $summary) && $status !== 'total') {
                ++$summary[$status];
            } else {
                ++$summary['other'];
            }
            $assessment = $this->deadlines->assess(
                $item['earliest_submission_on'],
                $item['due_on'],
                $status,
                $item['latest_submission']['status'] ?? null,
            );
            $item['deadline'] = $assessment->toArray();
            ++$deadlineSummary[$assessment->phase];
        }
        unset($item);

        return Json::ok($response, [
            'environment' => $environment,
            'period' => substr($periodStart, 0, 7),
            'summary' => $summary,
            'deadline_summary' => $deadlineSummary,
            'items' => $items,
        ]);
    }

    private function environment(mixed $value): string
    {
        if (!is_string($value)
            || !in_array($value, ['production', 'test'], true)
        ) {
            throw new \InvalidArgumentException(
                'Prostředí podání musí být production nebo test.',
            );
        }

        return $value;
    }

    /** @return array{string,string} */
    private function period(mixed $value): array
    {
        if (!is_string($value)
            || preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Období podání musí mít formát RRRR-MM.',
            );
        }
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value . '-01',
        );
        if (!$start instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(
                'Období podání není platné.',
            );
        }

        return [
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
        ];
    }
}
