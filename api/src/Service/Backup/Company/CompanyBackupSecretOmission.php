<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Manifestový počet hodnot vynechaných na jednom registry místě. */
final readonly class CompanyBackupSecretOmission
{
    private function __construct(
        public string $registryKey,
        public CompanyBackupSecretScope $scope,
        public string $name,
        public TenantSecretPolicy $policy,
        public CompanyBackupSecretOmissionReason $reason,
        public int $count,
    ) {}

    public static function fromArray(
        mixed $value,
        CompanyBackupSecretDeclaration $expected,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($expected->signature());
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'count',
            'name',
            'policy',
            'reason',
            'registry_key',
            'scope',
        ] || $value['registry_key'] !== $expected->registryKey
            || $value['scope'] !== $expected->scope->value
            || $value['name'] !== $expected->name
            || $value['policy'] !== $expected->policy->value
            || $value['reason'] !== $expected->reason->value
            || !is_int($value['count'])
            || $value['count'] < 0
        ) {
            throw self::invalid($expected->signature());
        }
        return new self(
            $expected->registryKey,
            $expected->scope,
            $expected->name,
            $expected->policy,
            $expected->reason,
            $value['count'],
        );
    }

    /** @return array{registry_key:string,scope:string,name:string,policy:string,reason:string,count:int} */
    public function toArray(): array
    {
        return [
            'registry_key' => $this->registryKey,
            'scope' => $this->scope->value,
            'name' => $this->name,
            'policy' => $this->policy->value,
            'reason' => $this->reason->value,
            'count' => $this->count,
        ];
    }

    public function withCount(int $count): self
    {
        if ($count < 0) {
            throw self::invalid(
                $this->registryKey . ':' . $this->scope->value . ':' . $this->name,
            );
        }
        return new self(
            $this->registryKey,
            $this->scope,
            $this->name,
            $this->policy,
            $this->reason,
            $count,
        );
    }

    private static function invalid(string $signature): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Vynechání secretu ' . $signature . ' není platné.',
        );
    }
}
