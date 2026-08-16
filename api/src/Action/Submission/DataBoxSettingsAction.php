<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Systém → Datová schránka: systémový certifikát a souhlas s vybíráním schránky.
 *
 * Bezpečnostní režim se přebírá od trezoru certifikátů beze změny:
 * jen z přihlášené webové relace, nikdy přes bearer token. Certifikát k datové
 * schránce otevírá odesílání podání jménem firmy — je to stejná třída tajemství
 * jako soukromý klíč, a tak se s ním zachází.
 *
 * Přihlašovací formulář (jméno a heslo) tu ZÁMĚRNĚ není: přístupové údaje ke
 * schránce nesmí opustit zařízení uživatele (§ 9 odst. 2 zák. 300/2008 Sb.).
 *
 * Odpověď NIKDY neobsahuje uložený certifikát ani jeho heslo: čte se z projekce,
 * která ciphertext sloupce vůbec nevybírá.
 */
final class DataBoxSettingsAction
{
    public function __construct(
        private readonly SubmissionCredentialService $credentials,
        private readonly SubmissionInboxService $inbox,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->credentials->listPublic(SupplierGuard::currentId($request)),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $certificate = '';
            $file = $request->getUploadedFiles()['certificate'] ?? null;
            if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
                $certificate = (string) $file->getStream()->getContents();
            }

            $saved = $this->credentials->save(
                $supplierId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['label'] ?? ''),
                (string) ($body['box_id'] ?? ''),
                $certificate,
                isset($body['certificate_password']) ? (string) $body['certificate_password'] : null,
                $userId,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->logger->log('databox_credentials_save', $userId, 'databox', $saved['id'], null, null, null, $supplierId);

        return Json::ok($response, $saved);
    }

    /**
     * Zapnutí/vypnutí vybírání schránky.
     *
     * Samostatný endpoint schválně: není to nastavení jako každé jiné.
     * Vyzvednutím seznamu se zprávy DORUČÍ (§ 17 odst. 3 zák. 300/2008 Sb.)
     * a rozeběhnou se lhůty, takže to musí být vlastní vědomé rozhodnutí,
     * ne vedlejší efekt uložení certifikátu.
     */
    public function setPolling(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = (bool) ($body['enabled'] ?? false);

        // Zapnutí vyžaduje výslovné potvrzení, že uživatel ví, co dělá.
        // Bez něj by stačilo omylem přepnout přepínač a začaly by běžet lhůty.
        if ($enabled && ($body['acknowledged'] ?? false) !== true) {
            return Json::error(
                $response,
                'acknowledgement_required',
                'Vyzvednutí zprávy z datové schránky se počítá jako doručení a rozjíždí zákonné lhůty. '
                . 'Zapnutí proto musíte výslovně potvrdit.',
                400,
            );
        }

        try {
            $this->inbox->setPollingEnabled(
                SupplierGuard::currentId($request),
                (string) ($body['environment'] ?? 'production'),
                $enabled,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['inbox_polling_enabled' => $enabled]);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $environment = (string) ($args['environment'] ?? 'production');

        try {
            $deleted = $this->credentials->delete($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Takové přihlášení uložené není.', 404);
        }

        $this->logger->log('databox_credentials_delete', $this->userId($request), 'databox', 0, null, null, null, $supplierId);

        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Přihlášení k datové schránce lze spravovat jen z webového rozhraní.',
                403,
            );
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
