<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use RuntimeException;

/**
 * Storno exportu uživatelem. Nese se výjimkou, aby se běh zastavil i uprostřed
 * dlouhé smyčky přes doklady — návratový kód by se musel testovat na každé úrovni
 * a jedno zapomenuté místo by znamenalo, že „zrušený" export dál žere výkon.
 */
final class InstanceExportCancelled extends RuntimeException
{
}
