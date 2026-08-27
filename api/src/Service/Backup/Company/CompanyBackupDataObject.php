<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Jedna registrovaná JSONL položka strojového snapshotu. */
final readonly class CompanyBackupDataObject
{
    private function __construct(
        public string $registryKey,
        public string $path,
        public int $order,
        public int $rows,
        public int $bytes,
        public string $sha256,
    ) {}

    public static function fromArray(
        mixed $value,
        TenantDataDefinition $definition,
        int $expectedOrder,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Datový objekt manifestu musí být JSON objekt.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['bytes', 'order', 'path', 'registry_key', 'rows', 'sha256']) {
            throw new \InvalidArgumentException(
                'Datový objekt manifestu má neznámá nebo chybějící pole.',
            );
        }

        $registryKey = $value['registry_key'];
        $path = $value['path'];
        $order = $value['order'];
        $rows = $value['rows'];
        $bytes = $value['bytes'];
        $sha256 = $value['sha256'];
        $expectedPath = self::pathForRegistryKey($definition->key);
        if ($registryKey !== $definition->key
            || !is_string($path)
            || $path !== $expectedPath
            || !is_int($order)
            || $order !== $expectedOrder
            || !is_int($rows)
            || $rows < 0
            || !is_int($bytes)
            || $bytes < 0
            || !is_string($sha256)
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Datový objekt ' . $definition->key . ' nemá kanonická metadata.',
            );
        }

        return new self($registryKey, $path, $order, $rows, $bytes, $sha256);
    }

    public static function pathForRegistryKey(string $registryKey): string
    {
        return 'data/' . str_replace(':', '-', $registryKey) . '.jsonl';
    }

    public static function fromWrittenPayload(
        TenantDataDefinition $definition,
        int $order,
        int $rows,
        int $bytes,
        string $sha256,
    ): self {
        if (!in_array(
            $definition->kind,
            [TenantDataObjectKind::Table, TenantDataObjectKind::LogicalObject],
            true,
        )
            || !$definition->policy->hasMachineDataPayload()
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $order < 1
            || $rows < 0
            || $bytes < 0
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Metadata zapsaného objektu strojových dat nejsou platná.',
            );
        }
        return new self(
            $definition->key,
            self::pathForRegistryKey($definition->key),
            $order,
            $rows,
            $bytes,
            $sha256,
        );
    }

    /** @return array{registry_key:string,path:string,order:int,rows:int,bytes:int,sha256:string} */
    public function toArray(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'path' => $this->path,
            'order' => $this->order,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
        ];
    }
}
