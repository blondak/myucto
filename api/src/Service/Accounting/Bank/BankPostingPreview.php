<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingService;

/**
 * Kontace návrhu tak, jak ji uvidí deník.
 *
 * Návrh v `bank_posting_suggestions` nese kódy, jak je vydalo pravidlo, detektor nebo AI —
 * tedy typicky holé syntetiky (`261/221`). Skutečný zápis ale projde ještě dvěma přepisy:
 *
 *  1. {@see BankAnalyticResolver::apply()} svede bankovní nohu `221` na analytiku vlastního
 *     účtu výpisu (`221.400` u účtu CREDITAS),
 *  2. {@see PostingService::redirectedAccountCode()} přesměruje syntetiku s jedinou
 *     analytikou (migrace 1326), takže `261` skončí na `261.100`.
 *
 * Fronta návrhů oba kroky nedělala a zobrazovala syrové kódy, takže náhled tvrdil `261/221`,
 * zatímco zaúčtování zapsalo `261.100/221.400`. U účetnictví je to nepřípustné: uživatel
 * schvaluje něco jiného, než co vidí. Tahle třída je jediné místo, které náhled skládá, a
 * dělá to TOUŽ cestou jako zaúčtování — ne paralelním formátováním.
 *
 * Když analytiku určit nelze (cizí/neznámý účet výpisu, účet, který ještě žádnou nedostal),
 * zůstane syntetika a příznak `resolved` je `false` — volající to má dát najevo, ať to
 * nevypadá jako konečná kontace.
 */
final class BankPostingPreview
{
    public function __construct(
        private readonly BankAnalyticResolver $bankAnalytics,
        private readonly PostingService $posting,
    ) {}

    /**
     * @param array<string,mixed> $tx řádek transakce včetně `recipient_account`/`recipient_bank`
     *                                (číslo účtu výpisu), viz {@see BankAnalyticResolver}
     * @return array{debit:string, credit:string, resolved:bool}
     */
    public function codes(int $supplierId, array $tx, string $debit, string $credit): array
    {
        $md = $this->code($supplierId, $tx, $debit);
        $d = $this->code($supplierId, $tx, $credit);

        return [
            'debit' => $md['code'],
            'credit' => $d['code'],
            'resolved' => $md['resolved'] && $d['resolved'],
        ];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{code:string, resolved:bool}
     */
    public function code(int $supplierId, array $tx, string $code): array
    {
        if ($code !== BankAnalyticAssigner::BANK_SYNTHETIC) {
            return ['code' => $this->posting->redirectedAccountCode($supplierId, $code), 'resolved' => true];
        }
        $analytic = $this->bankAnalytics->existingAnalyticCodeFor($supplierId, $tx);

        return $analytic === null
            ? ['code' => $code, 'resolved' => false]
            : ['code' => $analytic, 'resolved' => true];
    }
}
