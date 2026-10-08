<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

/**
 * Řazení a filtry seznamu firem (správa firem). Neznámé hodnoty se tiše vrací na
 * výchozí, aby rozbitý nebo starý sdílený odkaz pořád něco zobrazil.
 */
final class SupplierDirectoryQuery
{
    public const SORTS = ['urgency', 'name', 'last_invoice', 'last_activity', 'overdue'];

    private const DEFAULT_DIR = [
        'urgency'       => 'desc',
        'name'          => 'asc',
        'last_invoice'  => 'desc',
        'last_activity' => 'desc',
        'overdue'       => 'desc',
    ];

    public function __construct(
        public readonly string $sort = 'urgency',
        public readonly string $dir = 'desc',
        public readonly string $q = '',
        public readonly ?bool $vatPayer = null,
        public readonly ?string $accountingMode = null,
        public readonly ?bool $urgent = null,
    ) {}

    /** @param array<string,mixed> $params */
    public static function fromArray(array $params): self
    {
        $sort = is_string($params['sort'] ?? null) && in_array($params['sort'], self::SORTS, true)
            ? $params['sort']
            : 'urgency';
        $dir = ($params['dir'] ?? null) === 'asc' || ($params['dir'] ?? null) === 'desc'
            ? (string) $params['dir']
            : self::DEFAULT_DIR[$sort];
        $q = is_string($params['q'] ?? null) ? mb_substr(trim($params['q']), 0, 100) : '';
        $vat = match ($params['vat'] ?? null) {
            'payer'     => true,
            'non_payer' => false,
            default     => null,
        };
        $mode = in_array($params['mode'] ?? null, ['double_entry', 'tax_evidence'], true) ? (string) $params['mode'] : null;
        $urgent = match ($params['urgency'] ?? null) {
            'with'    => true,
            'without' => false,
            default   => null,
        };

        return new self($sort, $dir, $q, $vat, $mode, $urgent);
    }
}
