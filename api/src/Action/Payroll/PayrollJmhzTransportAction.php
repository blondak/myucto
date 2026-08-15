<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolError;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odeslání měsíčního hlášení na ČSSZ a dotažení výsledku.
 *
 * Tři kroky, schválně oddělené, protože se dějí v různém čase a mají různé
 * důsledky:
 *
 *  * `send` odešle zmrazené podání. Vrací potvrzení o PŘEVZETÍ, ne o přijetí —
 *    zpracování na straně ČSSZ teprve začíná a vydávat to za hotovo je přesně
 *    ta záměna, po které uživatel přestane sledovat výsledek.
 *  * `poll` se ptá na výsledek. Dokud VREP odpovídá potvrzením, běží zpracování
 *    a podání zůstává otevřené.
 *  * `close` uzavře transakci. Podací protokol to vyžaduje výslovně; aplikace,
 *    které transakce neuzavírají, porušují pravidla provozu. Uzavírá se až po
 *    dotažení protokolu — dřív by se výsledek ztratil.
 *
 * `Idempotency-Key` je u odeslání povinný. Bez něj by druhé kliknutí založilo
 * druhé podání za totéž období; ČSSZ takové podání odmítne jako duplicitu
 * a vzít zpět se nedá.
 */
final class PayrollJmhzTransportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzDispatchService $dispatch,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{submissionId:string} $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey === '') {
            return $this->invalid($response, 'Hlavička Idempotency-Key je povinná.');
        }
        $payload = $body['payload_xml'] ?? null;
        $variableSymbol = $body['variable_symbol'] ?? null;
        if (!is_string($payload) || trim($payload) === '') {
            return $this->invalid($response, 'Chybí datová věta zmrazeného podání.');
        }
        if (!is_string($variableSymbol) || preg_match('/^[0-9]{1,10}$/D', $variableSymbol) !== 1) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }

        return $this->run($request, $response, function (string $environment) use (
            $request,
            $args,
            $payload,
            $variableSymbol,
            $idempotencyKey,
        ): JmhzDispatchOutcome {
            return $this->dispatch->send(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'submissionId'),
                $payload,
                $variableSymbol,
                $idempotencyKey,
                $this->userId($request),
            );
        });
    }

    /** @param array{attemptId:string} $args */
    public function poll(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $variableSymbol = $this->queryVariableSymbol($request);
        if ($variableSymbol === null) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }

        return $this->run($request, $response, function (string $environment) use (
            $request,
            $args,
            $variableSymbol,
        ): JmhzDispatchOutcome {
            return $this->dispatch->poll(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
                $variableSymbol,
            );
        });
    }

    /** @param array{attemptId:string} $args */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response)) !== null) {
            return $denied;
        }
        $variableSymbol = $this->queryVariableSymbol($request);
        if ($variableSymbol === null) {
            return $this->invalid(
                $response,
                'Variabilní symbol zaměstnavatele musí mít nejvýše deset číslic.',
            );
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        try {
            $this->dispatch->close(
                $this->currentSupplierId($request),
                $environment,
                $this->id($args, 'attemptId'),
                $variableSymbol,
            );
        } catch (JmhzTransportException $exception) {
            return $this->transportError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return $this->noStore(Json::ok($response, ['closed' => true]));
    }

    /** @param callable(string):JmhzDispatchOutcome $operation */
    private function run(Request $request, Response $response, callable $operation): Response
    {
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        try {
            $outcome = $operation($environment);
        } catch (JmhzTransportException $exception) {
            return $this->transportError($response, $exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return Json::error($response, 'conflict', $exception->getMessage(), 409);
        }

        return $this->noStore(Json::ok($response, [
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
            'report' => $outcome->report === null ? null : [
                'status' => $outcome->report->status->name,
                'errors' => array_map(
                    // `control_id` se posílá jen tehdy, když ho kód chyby
                    // opravdu nese. Dopočítat ho u platformních kódů by
                    // ukázalo na kontrolu, o kterou vůbec nešlo.
                    static fn (JmhzProtocolError $error): array => [
                        'code' => $error->code,
                        'message' => $error->message,
                        'origin' => $error->origin->value,
                        'control_id' => $error->controlId?->value,
                    ],
                    $outcome->report->errors,
                ),
            ],
        ]));
    }

    private function transportError(
        Response $response,
        JmhzTransportException $exception,
    ): Response {
        // Chyba na straně ČSSZ není chyba klienta ani naše: 502 říká, že cesta
        // ven selhala, aniž by to vypadalo jako neplatný požadavek.
        $status = match (true) {
            $exception->errorCode === 'jmhz_dispatch_attempt_unknown' => 404,
            $exception->errorCode === 'jmhz_signing_profile_missing' => 422,
            str_starts_with($exception->errorCode, 'jmhz_signing_') => 422,
            str_starts_with($exception->errorCode, 'jmhz_vrep_') => 502,
            default => 422,
        };

        return $this->noStore(
            Json::error($response, $exception->errorCode, $exception->getMessage(), $status),
        );
    }

    private function queryVariableSymbol(Request $request): ?string
    {
        $value = $request->getQueryParams()['variable_symbol'] ?? null;
        if (!is_string($value) || preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function environment(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['environment'] ?? null) : null;
        if (!is_string($value)) {
            $value = $request->getQueryParams()['environment'] ?? 'test';
        }

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    /** @param array<string,string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level = AccessLevel::WRITE,
    ): ?Response {
        // Odeslání úředního podání jménem firmy se nikdy nespouští přes token:
        // token se dá odcizit a na rozdíl od relace u něj není druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
