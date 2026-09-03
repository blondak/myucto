<?php

declare(strict_types=1);

namespace MyInvoice\Action\Backup;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Backup\Company\CompanyBackupJobManager;
use MyInvoice\Service\Backup\Company\CompanyBackupManagementException;
use MyInvoice\Service\Backup\Company\CompanyBackupManifestHeader;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Session-only transport pro historii a řízení zálohových jobů aktuální firmy. */
final readonly class CompanyBackupJobAction
{
    private const PERMISSION = 'utilities.company_backup';
    private const LIST_LIMIT = 20;

    public function __construct(
        private CompanyBackupJobManager $jobs,
        private ActivityLogger $activity,
        private IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = SupplierGuard::currentId($request);

        return Json::ok($response, [
            'items' => $this->jobs->list($supplierId, self::LIST_LIMIT),
            'limit' => self::LIST_LIMIT,
        ]);
    }

    /** @param array<string,string> $args */
    public function status(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $backupId = self::backupId($args);
        if ($backupId === null) {
            return self::notFound($response);
        }

        try {
            $job = $this->jobs->detail(
                $backupId,
                SupplierGuard::currentId($request),
            );
        } catch (CompanyBackupManagementException $e) {
            return self::managementError($response, $e);
        }

        return Json::ok($response, ['job' => $job]);
    }

    /** @param array<string,string> $args */
    public function cancel(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $backupId = self::backupId($args);
        if ($backupId === null) {
            return self::notFound($response);
        }
        $supplierId = SupplierGuard::currentId($request);

        try {
            $result = $this->jobs->cancel($backupId, $supplierId);
        } catch (CompanyBackupManagementException $e) {
            return self::managementError($response, $e);
        }
        if ($result['changed']) {
            $this->log(
                $request,
                'company_backup.cancel_requested',
                $supplierId,
                [
                    'backup_id' => $backupId,
                    'status' => (string) ($result['job']['status'] ?? ''),
                ],
            );
        }

        return Json::ok($response, [
            'cancel_requested' => true,
            'changed' => $result['changed'],
            'job' => $result['job'],
        ]);
    }

    /** @param array<string,string> $args */
    public function delete(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $backupId = self::backupId($args);
        if ($backupId === null) {
            return self::notFound($response);
        }
        $supplierId = SupplierGuard::currentId($request);

        try {
            $result = $this->jobs->deleteArtifact($backupId, $supplierId);
        } catch (CompanyBackupManagementException $e) {
            return self::managementError($response, $e);
        }
        if ($result['changed']) {
            $this->log(
                $request,
                'company_backup.deleted',
                $supplierId,
                [
                    'backup_id' => $backupId,
                    'sha256' => $result['sha256'],
                ],
            );
        }

        return Json::ok($response, [
            'artifact_removed' => $result['changed'],
            'job' => $result['job'],
        ]);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) < 1) {
            return Json::error(
                $response,
                'unauthenticated',
                'Nepřihlášený uživatel.',
                401,
            );
        }
        if (!RequestAuthorization::allows($request, self::PERMISSION, $level)) {
            return Json::error(
                $response,
                'forbidden',
                'Ke správě záloh firmy nemáš oprávnění.',
                403,
            );
        }
        if (SupplierGuard::currentId($request) < 1) {
            return Json::error(
                $response,
                'no_supplier',
                'Chybí kontext firmy.',
                400,
            );
        }
        return null;
    }

    /** @param array<string,string> $args */
    private static function backupId(array $args): ?string
    {
        $backupId = (string) ($args['backupId'] ?? '');
        return CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
            ? $backupId
            : null;
    }

    private static function notFound(Response $response): Response
    {
        return Json::error(
            $response,
            'not_found',
            'Záloha nebyla nalezena.',
            404,
        );
    }

    private static function managementError(
        Response $response,
        CompanyBackupManagementException $error,
    ): Response {
        [$status, $message] = match ($error->errorCode) {
            'not_found' => [404, 'Záloha nebyla nalezena.'],
            'not_cancellable' => [409, 'Tento zálohový job už nelze zrušit.'],
            'not_deletable' => [409, 'Serverovou kopii tohoto jobu nelze smazat.'],
            'artifact_delete_deferred' => [
                409,
                'Archiv se nyní nepodařilo odstranit. Zkus to znovu později.',
            ],
            'state_conflict' => [
                409,
                'Stav zálohy se souběžně změnil. Obnov stránku a zkus to znovu.',
            ],
            default => [500, 'Správa zálohy se nezdařila.'],
        };
        return Json::error($response, $error->errorCode, $message, $status);
    }

    /** @param array<string,mixed> $payload */
    private function log(
        Request $request,
        string $action,
        int $supplierId,
        array $payload,
    ): void {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log(
            $action,
            (int) ($user['id'] ?? 0),
            'supplier',
            $supplierId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
