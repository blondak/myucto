<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Odkud se bere registrace odesílací brány.
 *
 * Existuje kvůli jedinému účelu: {@see GatewayIsdsTransport} se dá otestovat
 * bez databáze. {@see IsdsGatewayRegistrationService} je `final readonly`
 * a visí na `Connection`, takže by jinak každý test o překážkách adaptéru
 * potřeboval schéma — a testy podání nesmí být závislé na tom, jestli zrovna
 * někdo klonoval testovací databázi.
 *
 * Je to úmyslně JEN dvě metody: „smí se to vůbec zkoušet" a „dej registraci".
 * Zápis, mazání ani výpis registrací sem nepatří — ty dělá provozovatel přes
 * {@see \MyInvoice\Action\Submission\IsdsGatewayAction}, ne kanál podání.
 */
interface IsdsGatewayRegistrationSource
{
    /**
     * Je brána v tomhle prostředí nastavená (registrace, zapnutá, certifikát)?
     *
     * Nikdy nehází — nejistota je `false`. Rozhoduje o tvaru překážky, ne
     * o oprávnění odeslat; tím zůstává {@see load()}.
     */
    public function isDispatchReady(string $environment): bool;

    /**
     * Registrace k použití. **Fail-closed:** chybějící, vypnutá nebo
     * nerozšifrovatelná registrace je pojmenovaná výjimka, ne tichý průchod.
     *
     * @throws SubmissionChannelException
     */
    public function load(string $environment): IsdsGatewayRegistration;
}
