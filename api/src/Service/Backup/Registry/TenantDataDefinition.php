<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

use MyInvoice\Service\Backup\CanonicalJson;

/** Jedna verzovaná klasifikace tabulky, souborové oblasti nebo logického objektu. */
final readonly class TenantDataDefinition
{
    /** @var list<string> */
    public array $profiles;

    /** @var array<string,mixed> */
    public array $details;

    /**
     * @param array<mixed> $profiles
     * @param array<mixed> $details
     */
    public function __construct(
        public string $key,
        public TenantDataObjectKind $kind,
        public TenantDataPolicy $policy,
        array $profiles,
        array $details,
    ) {
        if (!self::isValidKey($key) || !str_starts_with($key, $kind->keyPrefix())) {
            throw new \InvalidArgumentException(
                'Klíč objektu tenantového registru neodpovídá druhu objektu.',
            );
        }
        if (!array_is_list($profiles) || $profiles === []) {
            throw new \InvalidArgumentException(
                'Objekt tenantového registru musí patřit alespoň do jednoho profilu.',
            );
        }
        $validatedProfiles = [];
        $seenProfiles = [];
        foreach ($profiles as $profile) {
            if (!is_string($profile) || !self::isValidProfile($profile)) {
                throw new \InvalidArgumentException(
                    'Profil tenantového registru nemá bezpečný identifikátor.',
                );
            }
            if (isset($seenProfiles[$profile])) {
                throw new \InvalidArgumentException(
                    'Objekt tenantového registru obsahuje duplicitní profil.',
                );
            }
            $seenProfiles[$profile] = true;
            $validatedProfiles[] = $profile;
        }
        sort($validatedProfiles, SORT_STRING);

        if (array_is_list($details) && $details !== []) {
            throw new \InvalidArgumentException(
                'Detaily objektu tenantového registru musí být JSON objekt.',
            );
        }
        $validatedDetails = [];
        foreach ($details as $field => $value) {
            if (!is_string($field)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Klíč detailu tenantového registru nemá bezpečný identifikátor.',
                );
            }
            $validatedDetails[$field] = $value;
        }
        CanonicalJson::encode($validatedDetails);

        if ($policy === TenantDataPolicy::Unsupported) {
            $reason = $validatedDetails['reason'] ?? null;
            if (!is_string($reason)
                || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Unsupported objekt tenantového registru musí mít stabilní důvod.',
                );
            }
        }

        $this->profiles = $validatedProfiles;
        $this->details = $validatedDetails;
    }

    public function hasProfile(string $profile): bool
    {
        return in_array($profile, $this->profiles, true);
    }

    public function name(): string
    {
        return substr($this->key, strlen($this->kind->keyPrefix()));
    }

    /** @return array{key:string,kind:string,policy:string,profiles:list<string>,details:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'policy' => $this->policy->value,
            'profiles' => $this->profiles,
            'details' => $this->details,
        ];
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(
            '/^(?:table|file-area|logical):[a-z][a-z0-9_.-]{0,127}$/D',
            $key,
        ) === 1;
    }

    public static function isValidProfile(string $profile): bool
    {
        return preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $profile) === 1;
    }
}
