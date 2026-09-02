<?php

declare(strict_types=1);

namespace MyInvoice\Action\Backup;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadProvider;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadRangeException;
use MyInvoice\Service\Backup\Company\CompanyBackupManifestHeader;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Session-only transport hotového archivu zálohy aktuální firmy. */
final readonly class CompanyBackupDownloadAction
{
    private const PERMISSION = 'utilities.company_backup';

    public function __construct(
        private CompanyBackupDownloadProvider $downloads,
        private ActivityLogger $activity,
        private IpMatcher $ipMatcher,
    ) {}

    /**
     * GET /api/admin/company-backups/{backupId}/download
     *
     * @param array<string,string> $args
     */
    public function download(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return Json::error(
                $response,
                'unauthenticated',
                'Nepřihlášený uživatel.',
                401,
            );
        }
        if (!RequestAuthorization::allows(
            $request,
            self::PERMISSION,
            AccessLevel::READ,
        )) {
            return Json::error(
                $response,
                'forbidden',
                'Ke stažení zálohy firmy nemáš oprávnění.',
                403,
            );
        }

        $backupId = (string) ($args['backupId'] ?? '');
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            return Json::error(
                $response,
                'not_found',
                'Záloha nebyla nalezena.',
                404,
            );
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId < 1) {
            return Json::error(
                $response,
                'no_supplier',
                'Chybí kontext firmy.',
                400,
            );
        }

        try {
            $prepared = $this->downloads->prepare(
                $backupId,
                $supplierId,
                self::optionalHeader($request, 'Range'),
                self::optionalHeader($request, 'If-Range'),
            );
        } catch (CompanyBackupDownloadRangeException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                'Požadovaný rozsah archivu nelze poskytnout.',
                416,
                ['size_bytes' => $e->totalBytes],
            )
                ->withHeader('Content-Range', $e->contentRange())
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (CompanyBackupDownloadException $e) {
            [$status, $message] = match ($e->errorCode) {
                'not_found' => [404, 'Záloha nebyla nalezena.'],
                'not_ready' => [409, 'Záloha ještě není připravena ke stažení.'],
                'artifact_expired' => [410, 'Serverová kopie zálohy už expirovala.'],
                'artifact_unavailable' => [410, 'Archiv zálohy už není k dispozici.'],
                default => [500, 'Stažení zálohy se nezdařilo.'],
            };
            return Json::error($response, $e->errorCode, $message, $status);
        }

        $plan = $prepared->plan;
        $artifact = $prepared->artifact;
        $this->activity->log(
            'company_backup.downloaded',
            $userId,
            'supplier',
            $supplierId,
            [
                'backup_id' => $backupId,
                'sha256' => $artifact->sha256,
                'status_code' => $plan->statusCode,
                'range_start' => $plan->offset,
                'range_length' => $plan->length,
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        $result = $response
            ->withStatus($plan->statusCode)
            ->withBody($prepared->stream)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $artifact->downloadName . '"',
            )
            ->withHeader('Content-Length', (string) $plan->length)
            ->withHeader('ETag', $plan->etag)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Checksum-SHA256', $artifact->sha256)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $contentRange = $plan->contentRange();
        return $contentRange === null
            ? $result
            : $result->withHeader('Content-Range', $contentRange);
    }

    private static function optionalHeader(Request $request, string $name): ?string
    {
        return $request->hasHeader($name)
            ? $request->getHeaderLine($name)
            : null;
    }
}
