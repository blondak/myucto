<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollDeletionConflictException;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollDeletionNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Jednotný překlad výsledku mzdového mazání do HTTP.
 *
 * Blokace není chyba uživatele — nese kód a VĚTU, PODLE KTERÉ SE DÁ JEDNAT,
 * takže ji frontend ukazuje místo zašedlého tlačítka bez vysvětlení.
 */
trait PayrollDeletionResponse
{
    private function deletionError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof PayrollDeletionNotFoundException => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            $e instanceof PayrollDeletionConflictException => Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            ),
            $e instanceof PayrollDeletionException => Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
            ),
            default => throw $e,
        };
    }

    /** Volitelné `row_version` z těla požadavku; `null` znamená „neověřuj". */
    private function optionalRowVersion(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $version = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $version === false ? null : (int) $version;
    }

    /** @return array<string,mixed> */
    private function deletionBody(\Psr\Http\Message\ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return [];
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function deletionServerParams(
        \Psr\Http\Message\ServerRequestInterface $request,
    ): array {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
