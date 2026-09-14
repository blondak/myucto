<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Konektor, jehož banka omezuje, jak často lze stahovat pohyby.
 *
 * Automatická synchronizace (cron) spojení přeskočí, dokud od posledního
 * pokusu neuplyne vrácená doba. Ruční stažení z obrazovky se tím neomezuje.
 * Odstup se odvozuje z uložených přístupových údajů, protože limit může
 * záviset na variantě služby sjednané pro konkrétní účet.
 */
interface BankConnectorSyncPacing
{
    public function minimumAutomaticSyncIntervalSeconds(#[\SensitiveParameter] string $credential): int;
}
