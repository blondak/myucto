<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Deletion;

/**
 * Důvod, proč mazání neprošlo — v řeči uživatele, ne v názvech tabulek.
 *
 * `counts` (kód blokátoru => počet vazeb) jde do odpovědi jako strojová část,
 * `message` je věta, kterou uvidí člověk. Obojí musí JMENOVAT, co brání, jinak
 * uživatel netuší, kde má vazbu zrušit.
 */
final class DeletionConflict
{
    /** @param array<string,int> $counts */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $counts,
    ) {}

    /** @return array<string,mixed> `extra` payload pro {@see \MyInvoice\Http\Json::error()} */
    public function toErrorExtra(): array
    {
        return ['blocked_by' => $this->counts];
    }
}
