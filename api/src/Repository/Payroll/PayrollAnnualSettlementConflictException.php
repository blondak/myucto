<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Žádost nebo výsledek ročního zúčtování mezitím změnil někdo jiný.
 *
 * Vlastní typ proto, aby akce uměla vrátit 409 s vysvětlením místo obecné
 * chyby — u evidence, na které závisí výplata přeplatku, je rozdíl mezi
 * „nepovedlo se" a „mezitím se to změnilo" podstatný.
 */
final class PayrollAnnualSettlementConflictException extends \RuntimeException
{
}
