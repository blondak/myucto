<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface MultiFileBankConnector extends StructuredBankConnector
{
    /** @return array{account_number:string,bank_code:string,currency:string} */
    public function verifyAccount(#[\SensitiveParameter] string $credentials): array;

    /**
     * `source` říká, jak import soubor eviduje: `gpc` je originál výpisu banky,
     * `bank_api` průběžná data (avízo, pohyby z API). Import podle něj páruje
     * tentýž pohyb napříč zdroji; bez `source` se rozbor eviduje jako `bank_api`.
     *
     * @return list<array{content:string,filename:string,parsed?:array,source?:'gpc'|'bank_api'}>
     */
    public function statementFiles(#[\SensitiveParameter] string $content): array;
}
