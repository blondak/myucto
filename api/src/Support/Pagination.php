<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Sdílený helper pro serverové stránkování list endpointů.
 *
 * Jednotný kontrakt (vzor: faktury, účetní deník):
 *   - query `page` (1..), `per_page` (clamp 5..200, default z configu / 50)
 *   - odpověď `{ data: [...], meta: { total, page, per_page, pages } }`
 */
final class Pagination
{
    public const MIN_PER_PAGE = 5;
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Z query parametrů odvodí page/perPage/offset.
     *
     * @param array<string,mixed> $query
     * @return array{page:int,per_page:int,offset:int}
     */
    public static function fromQuery(array $query, int $default = self::DEFAULT_PER_PAGE): array
    {
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(self::MIN_PER_PAGE, (int) ($query['per_page'] ?? $default)));
        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * Sestaví meta blok pro odpověď.
     *
     * @return array{total:int,page:int,per_page:int,pages:int}
     */
    public static function meta(int $total, int $page, int $perPage): array
    {
        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Zabalí data + meta do jednotné odpovědi.
     *
     * @param array<int,mixed> $data
     * @return array{data:array<int,mixed>,meta:array{total:int,page:int,per_page:int,pages:int}}
     */
    public static function envelope(array $data, int $total, int $page, int $perPage): array
    {
        return ['data' => $data, 'meta' => self::meta($total, $page, $perPage)];
    }
}
