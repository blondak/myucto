<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Dávka už opustila aplikaci (stažený soubor nebo předání bance) a uživatel
 * nepotvrdil, že ji v bankovnictví zrušil.
 */
final class PayrollPaymentBatchHandoverException extends \DomainException
{
    /** @param 'downloaded'|'submitted' $handoverState */
    public function __construct(public readonly string $handoverState)
    {
        parent::__construct(
            $handoverState === 'submitted'
                ? 'Dávka už byla předána do banky. Pokud jste ji v bankovnictví neautorizovali,'
                    . ' zrušte ji tam ručně, jinak hrozí dvojí platba. Zahození potvrďte výslovně.'
                : 'Dávka už byla stažena. Pokud jste soubor do bankovnictví nahráli,'
                    . ' zrušte tam příkaz ručně, jinak hrozí dvojí platba. Zahození potvrďte výslovně.',
        );
    }
}
