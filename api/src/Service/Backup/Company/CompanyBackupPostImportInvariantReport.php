<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Neměnný, kontextově svázaný soupis provedených doménových kontrol. */
final readonly class CompanyBackupPostImportInvariantReport
{
    private const MAX_INVARIANTS = 256;

    /** @var list<array{id:string,check_count:int}> */
    public array $invariants;

    public int $invariantCount;

    public int $checkCount;

    public string $bindingSha256;

    /** @param array<mixed> $invariants */
    public function __construct(
        public int $supplierId,
        public string $targetRegistryFingerprint,
        array $invariants,
    ) {
        if ($supplierId < 1
            || preg_match(
                '/^sha256:[0-9a-f]{64}$/D',
                $targetRegistryFingerprint,
            ) !== 1
            || !array_is_list($invariants)
            || count($invariants) > self::MAX_INVARIANTS
        ) {
            throw new \InvalidArgumentException(
                'Report post-import invariantů nemá platný kontext.',
            );
        }

        $validated = [];
        $previousId = null;
        $checkCount = 0;
        foreach ($invariants as $invariant) {
            if (!is_array($invariant) || array_is_list($invariant)) {
                throw new \InvalidArgumentException(
                    'Položka reportu post-import invariantů není objekt.',
                );
            }
            $keys = array_keys($invariant);
            sort($keys, SORT_STRING);
            $id = $invariant['id'] ?? null;
            $count = $invariant['check_count'] ?? null;
            if ($keys !== ['check_count', 'id']
                || !is_string($id)
                || preg_match('/^[a-z][a-z0-9._-]{0,95}$/D', $id) !== 1
                || !is_int($count)
                || $count < 0
                || ($previousId !== null
                    && strcmp($previousId, $id) >= 0)
                || $checkCount > PHP_INT_MAX - $count
            ) {
                throw new \InvalidArgumentException(
                    'Položka reportu post-import invariantů není platná.',
                );
            }
            $validated[] = ['id' => $id, 'check_count' => $count];
            $previousId = $id;
            $checkCount += $count;
        }

        $this->invariants = $validated;
        $this->invariantCount = count($validated);
        $this->checkCount = $checkCount;
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => 'myucto-company-post-import-invariant-report',
            'version' => 1,
            'supplier_id' => $supplierId,
            'target_registry_fingerprint' => $targetRegistryFingerprint,
            'invariants' => $validated,
        ]);
    }
}
