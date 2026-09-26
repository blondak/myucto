<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Document\PayrollArchiveReencryptionService;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Systém → Diagnostika: přešifrování mzdového archivu a přebalení mzdových
 * hodnot na aktuální klíč přímo z aplikace.
 *
 *   POST /api/admin/diagnostics/payroll-archive/reencrypt
 *   POST /api/admin/diagnostics/payroll-archive/rewrap
 *
 * Jeden požadavek zpracuje jen dávku (webový požadavek nesmí běžet
 * neomezeně dlouho); odpověď říká, kolik zbývá, a UI volá dál, dokud není
 * hotovo. Totéž bez limitu dělá `api/bin/payroll-archive-reencrypt.php`.
 *
 * Zápis vyžaduje výslovné `confirm: true`, nevratné smazání plaintextu osob
 * po výmazu navíc `confirm_purge: true`. Každý běh jde do auditní stopy,
 * jen počty; obsah dokumentů ani klíče se nikam nevypisují.
 */
final class PayrollArchiveEncryptionAction
{
    public const REENCRYPT_BATCH = 200;
    public const REWRAP_BATCH = 500;
    private const PROBLEM_LIMIT = 20;

    public function __construct(
        private readonly PayrollArchiveReencryptionService $archive,
        private readonly PayrollKeyRotationService $rotation,
        private readonly ActivityLogger $activity,
    ) {}

    /** POST /api/admin/diagnostics/payroll-archive/reencrypt */
    public function reencrypt(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? false) === true;
        $includeOrphans = ($body['include_orphans'] ?? false) === true;
        $purgeErased = ($body['purge_erased'] ?? false) === true;
        if (!$dryRun && ($body['confirm'] ?? false) !== true) {
            return Json::error($response, 'confirmation_required', 'Přešifrování je potřeba potvrdit.', 422);
        }
        if ($purgeErased && !$dryRun && ($body['confirm_purge'] ?? false) !== true) {
            return Json::error(
                $response,
                'purge_confirmation_required',
                'Smazání nešifrovaných dokumentů osob po výmazu je nevratné a je potřeba ho potvrdit zvlášť.',
                422,
            );
        }
        $userId = $this->userId($request);
        @set_time_limit(300);

        $report = $this->archive->run(
            null,
            $dryRun,
            $includeOrphans,
            $purgeErased,
            self::REENCRYPT_BATCH,
            $userId > 0 ? $userId : null,
        );
        $problems = [];
        foreach ($report['items'] as $item) {
            if (in_array($item['status'], [
                PayrollArchiveReencryptionService::STATUS_FAILED,
                PayrollArchiveReencryptionService::STATUS_INTEGRITY_MISMATCH,
            ], true) && count($problems) < self::PROBLEM_LIMIT) {
                $problems[] = [
                    'supplier_id' => $item['supplier_id'],
                    'storage_key' => $item['storage_key'],
                    'status' => $item['status'],
                ];
            }
        }
        $result = [
            'dry_run' => $report['dry_run'],
            'processed' => $report['processed'],
            'remaining' => $report['remaining'],
            'counts' => $report['counts'],
            'problems' => $problems,
        ];
        if (!$dryRun) {
            $this->activity->log('payroll.archive.reencrypt', $userId, null, null, [
                'processed' => $report['processed'],
                'remaining' => $report['remaining'],
                'counts' => array_filter($report['counts']),
                'include_orphans' => $includeOrphans,
                'purge_erased' => $purgeErased,
            ]);
        }

        return Json::ok($response, $result);
    }

    /** POST /api/admin/diagnostics/payroll-archive/rewrap */
    public function rewrap(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? false) === true;
        if (!$dryRun && ($body['confirm'] ?? false) !== true) {
            return Json::error($response, 'confirmation_required', 'Přebalení je potřeba potvrdit.', 422);
        }
        $userId = $this->userId($request);
        @set_time_limit(300);

        $result = $this->rotation->rewrapAll(null, $dryRun, self::REWRAP_BATCH);
        $status = $this->rotation->status();
        $payload = [
            'dry_run' => $result['dry_run'],
            'rewrapped' => $result['rewrapped'],
            'would_rewrap' => $result['would_rewrap'],
            'failed' => $result['failed'],
            'remaining' => $status['stale_total'],
            'unknown' => $status['unknown_total'],
        ];
        if (!$dryRun) {
            $this->activity->log('payroll.keys.rewrap', $userId, null, null, $payload);
        }

        return Json::ok($response, $payload);
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
