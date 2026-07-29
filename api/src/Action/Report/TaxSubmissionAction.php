<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Historie archivovaných EPO XML výkazů včetně asistovaného předání a důkazních souborů.
 *
 *   GET    /api/reports/submissions             → list
 *   GET    /api/reports/submissions/{id}        → detail (s XML obsahem)
 *   GET    /api/reports/submissions/{id}/xml    → XML download
 *   POST   /api/reports/submissions/{id}/submit → označit jako prokazatelně PODANÉ (§2.4)
 *   DELETE /api/reports/submissions/{id}        → smazat archiv (admin)
 */
final class TaxSubmissionAction
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly ActivityLogger $logger,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $rows = $this->epo->enrich($this->repo->list($supplierId), $supplierId);
        return Json::ok($response, $rows);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        $row['attempts'] = $this->epo->attempts((int) $row['id'], $supplierId);
        $row['artifacts'] = $this->epo->artifacts((int) $row['id'], $supplierId);
        return Json::ok($response, $row);
    }

    public function downloadXml(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);

        $filename = TaxSubmissionFilename::forSnapshot($row, 'archive.xml');

        $response->getBody()->write((string) $row['xml_content']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Označí archivovaný snapshot jako PROKAZATELNĚ PODANÝ (audit §2.4). Teprve tím se
     * stává základem opravného/následného tvrzení, posouvá daňový zámek a v UI je "podáno".
     * Vyžaduje čas podání; identifikátor/č.j. potvrzení podatelny je doporučený.
     *
     * Body: { submitted_at?: 'YYYY-MM-DD'|ISO, submission_ref?: string }
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění označit podání.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);

        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        }
        // Neplatné (XSD failed) přiznání nelze doložit jako podané — na EPO by neprošlo.
        if (($existing['validation_status'] ?? null) === 'failed') {
            return Json::error(
                $response,
                'validation_failed',
                'Snapshot neprošel XSD validací — nelze jej označit jako podaný.',
                422,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $submittedAt = trim((string) ($body['submitted_at'] ?? ''));
        if ($submittedAt === '') {
            $submittedAt = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($submittedAt);
            if ($ts === false) {
                return Json::error($response, 'validation_failed', 'Neplatné datum podání (submitted_at).', 422);
            }
            $submittedAt = date('Y-m-d H:i:s', $ts);
        }
        $submissionRef = trim((string) ($body['submission_ref'] ?? '')) ?: null;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->epo->lockSubmission($id, $supplierId) === null) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
            }
            $confirmableAttempt = $this->epo->latestConfirmableAttempt($id, $supplierId);
            if (($confirmableAttempt['epo_environment'] ?? null) === 'test') {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return Json::error(
                    $response,
                    'epo_test_attempt',
                    'Zkušební předání EPO nelze označit jako právně účinné podání.',
                    409,
                );
            }

            $row = $this->archiver->markSubmitted(
                $id,
                $supplierId,
                $submittedAt,
                $submissionRef,
                $userId,
            );
            if ($row === null) {
                throw new \RuntimeException('Tax submission disappeared.');
            }
            $attemptId = isset($confirmableAttempt['id']) ? (int) $confirmableAttempt['id'] : null;
            if ($attemptId !== null) {
                $this->epo->markAttemptConfirmed($attemptId, $submittedAt);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->log('report.submission_marked_submitted', $userId, null, null, [
            'submission_id' => $id,
            'attempt_id' => $attemptId,
            'form_code'     => $row['form_code'] ?? null,
            'period_year'   => $row['period_year'] ?? null,
            'period_month'  => $row['period_month'] ?? null,
            'period_quarter' => $row['period_quarter'] ?? null,
            'submitted_at'  => $submittedAt,
            'submission_ref' => $submissionRef,
        ]);

        return Json::ok($response, $row);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $result = $this->epo->deleteSubmissionIfNoEvidence($id, $supplierId);
        if ($result === 'has_evidence') {
            return Json::error(
                $response,
                'submission_has_evidence',
                'Podání s EPO pokusem, dokumentem nebo potvrzeným stavem nelze smazat.',
                409,
            );
        }
        if ($result === 'not_found') {
            return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        }
        return Json::ok($response, ['deleted' => true]);
    }
}
