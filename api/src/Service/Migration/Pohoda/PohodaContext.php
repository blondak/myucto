<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Stav jednoho běhu převodu, který si kroky předávají: cílová firma, export, protokol
 * a mapy vzniklé v předchozích krocích.
 *
 * Doklady se v mapách drží pod číslem dokladu z Pohody - v rámci agendy (roku) je
 * jednoznačné a právě pod ním je vede i deník (`act:number`).
 */
final class PohodaContext
{
    /** @var array<string,int> kód účtu v MyÚčtu (`221.001`) => chart_of_accounts.id */
    public array $accountIds = [];

    /** @var array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}|null */
    public ?array $period = null;

    /** @var array<string,int> id adresy v Pohodě => clients.id */
    public array $clientsByPohodaId = [];

    /** @var array<string,int> IČO => clients.id */
    public array $clientsByIco = [];

    /** @var array<string,int> číslo dokladu => invoices.id */
    public array $issuedInvoices = [];

    /** @var array<string,int> číslo ostatní pohledávky => invoices.id */
    public array $receivables = [];

    /** @var array<string,int> číslo dokladu => purchase_invoices.id */
    public array $purchaseInvoices = [];

    /** @var array<string,int> číslo ostatního závazku => purchase_invoices.id */
    public array $commitments = [];

    /** @var array<string,int> číslo interního dokladu (daňový doklad k přijaté platbě) => invoices.id */
    public array $internalSales = [];

    /** @var array<string,int> číslo interního dokladu (daňový doklad k uhrazené záloze) => purchase_invoices.id */
    public array $internalPurchases = [];

    /** @var array<string,int> číslo dokladu => cash_documents.id */
    public array $cashDocuments = [];

    /** @var array<string,list<array{id:int,date:string}>> číslo pohybu => bank_transactions */
    public array $bankTransactions = [];

    /**
     * Úhrady dokladů z `liquidations` - kterým bankovním nebo pokladním dokladem Pohody
     * byl doklad uhrazen. Páruje je {@see DocumentLinker::matchPayments()}.
     *
     * @var list<array{doc:string,id:int,number:string,agenda:string,source:string,date:?string,amount:float,liq:string}>
     */
    public array $liquidations = [];

    /** @var array<string,true> id převedených dokladů minulého období („typ|id“) - nemají zápis v deníku roku */
    public array $previousPeriod = [];

    /** @var array<int,true> pokladní doklady bez zaúčtování k začátku období - jsou v počátečních stavech */
    public array $openingCash = [];

    /** @var array<string,string> číslo pokladního dokladu => účet pokladny z deníku (`211.001`) */
    public array $cashAccountsByNumber = [];

    /** @var list<int> klienti převedených vydaných faktur (přepočet statistik) */
    public array $statsClients = [];

    public ?int $runId = null;

    /** @var (callable(string,int,int):void)|null */
    public $progress = null;

    public function __construct(
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly PohodaExport $export,
        public readonly PohodaVat $vat,
        public readonly bool $dryRun,
        public readonly ImportProtocol $protocol,
    ) {}

    public function year(): int
    {
        return $this->export->year;
    }

    public function report(string $step, int $done, int $total): void
    {
        if ($this->progress !== null) {
            ($this->progress)($step, $done, $total);
        }
    }

    public function userOrNull(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }
}
