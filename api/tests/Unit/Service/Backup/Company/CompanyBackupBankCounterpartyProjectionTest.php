<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankCounterpartyProjectionTest extends TestCase
{
    /** @return array<string,array{string,list<string>,list<string>}> */
    public static function tables(): array
    {
        return [
            'map' => ['bank_counterparty_map', [
                'id', 'supplier_id', 'client_bank_account_id', 'counterparty_account',
                'counterparty_bank', 'side', 'client_id', 'match_count', 'manual_count',
                'contradiction_count', 'promoted_at', 'demoted_at', 'fee_pct_last',
                'fee_pct_samples', 'last_match_at', 'created_at', 'updated_at',
            ], ['client_bank_account_id', 'client_id', 'supplier_id']],
            'observations' => ['bank_counterparty_observations', [
                'id', 'map_id', 'bank_transaction_id', 'manual', 'fee_pct', 'observed_at',
            ], ['bank_transaction_id', 'map_id']],
        ];
    }

    /** @param list<string> $columns @param list<string> $references */
    #[DataProvider('tables')]
    public function testPreservesLearnedEvidenceAndRemapsRelations(
        string $table, array $columns, array $references,
    ): void {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:' . $table);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertSame($columns, $projection->dataColumns);
        $projection->assertRegistryTargets($registry);
        $row = array_fill_keys($columns, 'historical-value');
        $row['id'] = 1;
        foreach ($references as $index => $column) {
            $row[$column] = $index + 10;
        }
        $row['side'] = 'incoming';
        if ($table === 'bank_counterparty_observations') {
            unset($row['side']);
        }
        $projection->assertCompleteSourceRow($row);
        CompanyBackupSourceIdentityProjection::fromDefinition($definition)->identityForRow($row);
        $visited = [];
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): array {
                $visited[] = $reference->columns[0];
                return [$key[0] + 100];
            },
        );
        self::assertSame($references, $visited);
        foreach ($row as $column => $value) {
            self::assertSame(in_array($column, $references, true) ? $value + 100 : $value,
                $mapped[$column], $table . '.' . $column);
        }
    }

    public function testObservationOwnershipFollowsItsMapNotAnUnrelatedTransaction(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_counterparty_observations');
        self::assertNotNull($definition);
        $selection = (new CompanyBackupTenantSqlSelector())->select(
            CompanyBackupTableProjection::fromDefinition($definition), 11,
        );
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE bank_counterparty_map (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_counterparty_observations (id INTEGER PRIMARY KEY, map_id INTEGER)');
        $pdo->exec('INSERT INTO supplier VALUES (11), (12)');
        $pdo->exec('INSERT INTO bank_counterparty_map VALUES (21, 11), (22, 12)');
        $pdo->exec('INSERT INTO bank_counterparty_observations VALUES (31, 21), (32, 22)');
        $query = $pdo->prepare('SELECT id FROM bank_counterparty_observations AS `_company_source` WHERE '
            . $selection->where);
        $query->execute($selection->params);
        self::assertSame([31], $query->fetchAll(PDO::FETCH_COLUMN));
    }
}
