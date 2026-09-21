<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Query parametry filtru sestavy na dimenzi: `dimension_value_id` (hodnota) a volitelně
 * `dimension_descendants=0` (jen hodnota sama, bez podřízených).
 */
trait DimensionFilterParam
{
    /** @return DimensionFilter|null|false false = chyba v $err */
    protected function dimensionFilterParam(DimensionService $dimensions, Request $request, Response $response, int $supplierId, ?Response &$err): DimensionFilter|null|false
    {
        $err = null;
        $q = $request->getQueryParams();
        $valueId = (int) ($q['dimension_value_id'] ?? 0);
        if ($valueId <= 0) {
            return null;
        }
        try {
            return $dimensions->filter($supplierId, $valueId, (string) ($q['dimension_descendants'] ?? '1') !== '0');
        } catch (DimensionException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return false;
        }
    }
}
