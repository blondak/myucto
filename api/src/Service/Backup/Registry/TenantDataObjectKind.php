<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

enum TenantDataObjectKind: string
{
    case Table = 'table';
    case FileArea = 'file_area';
    case LogicalObject = 'logical_object';

    public function keyPrefix(): string
    {
        return match ($this) {
            self::Table => 'table:',
            self::FileArea => 'file-area:',
            self::LogicalObject => 'logical:',
        };
    }
}
