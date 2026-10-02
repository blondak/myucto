<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Osoba nemá v roce žádný příjem toho druhu, o jehož potvrzení jde.
 *
 * Typický případ: potvrzení o srážkové dani u zaměstnance, kterému se strhávaly
 * jen zálohy. Není to chyba podkladů ani výpočtu, takové potvrzení se prostě
 * nevystavuje. Proto vlastní typ: hromadná dávka podle něj osobu PŘESKOČÍ,
 * místo aby ji třikrát zkoušela znovu a vykázala jako selhání.
 */
final class AnnualTaxCertificateNoIncomeException extends \DomainException
{
}
