<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Default-deny porovnání deklarovaného profilu se skutečným inventářem objektů. */
final class TenantDataRegistryCoverageValidator
{
    /**
     * @param array<mixed> $inventoryObjects
     */
    public function evaluate(
        TenantDataRegistry $registry,
        string $profile,
        array $inventoryObjects,
    ): TenantDataCoverageReport {
        if (!array_is_list($inventoryObjects)) {
            throw new \InvalidArgumentException('Inventář tenantových objektů musí být seznam.');
        }
        $inventory = [];
        foreach ($inventoryObjects as $object) {
            if (!is_string($object) || !TenantDataDefinition::isValidKey($object)) {
                throw new \InvalidArgumentException('Inventář obsahuje neplatný klíč objektu.');
            }
            if (isset($inventory[$object])) {
                throw new \InvalidArgumentException('Inventář obsahuje duplicitní objekt.');
            }
            $inventory[$object] = true;
        }
        ksort($inventory, SORT_STRING);

        $issues = [];
        if (!$registry->isComplete($profile)) {
            $issues[] = new TenantDataCoverageIssue(
                'profile_incomplete',
                'profile:' . $profile,
                'Profil registru není označený jako úplný.',
            );
        }
        foreach (array_keys($inventory) as $object) {
            $definition = $registry->definition($object);
            if ($definition === null) {
                $issues[] = new TenantDataCoverageIssue(
                    'object_unclassified',
                    $object,
                    'Objekt z runtime inventáře nemá explicitní klasifikaci.',
                );
                continue;
            }
            if (!$definition->hasProfile($profile)) {
                $issues[] = new TenantDataCoverageIssue(
                    'object_outside_profile',
                    $object,
                    'Objekt je registrovaný, ale není klasifikovaný pro tento profil.',
                );
                continue;
            }
            if ($definition->policy === TenantDataPolicy::Unsupported) {
                $issues[] = new TenantDataCoverageIssue(
                    'object_unsupported',
                    $object,
                    'Objekt je známý, ale jeho bezpečný export nebo obnova nejsou podporované.',
                );
            }
        }
        foreach ($registry->definitionsFor($profile) as $definition) {
            if (!isset($inventory[$definition->key])) {
                $issues[] = new TenantDataCoverageIssue(
                    'declared_object_missing',
                    $definition->key,
                    'Objekt deklarovaný profilem chybí v runtime inventáři.',
                );
            }
        }
        usort($issues, static function (
            TenantDataCoverageIssue $left,
            TenantDataCoverageIssue $right,
        ): int {
            $object = strcmp($left->object, $right->object);
            return $object !== 0 ? $object : strcmp($left->code, $right->code);
        });
        return new TenantDataCoverageReport($issues);
    }

    /** @param array<mixed> $inventoryObjects */
    public function assertSafe(
        TenantDataRegistry $registry,
        string $profile,
        array $inventoryObjects,
    ): void {
        $report = $this->evaluate($registry, $profile, $inventoryObjects);
        if (!$report->isSafe()) {
            throw new IncompleteTenantDataRegistryCoverage($report);
        }
    }
}
