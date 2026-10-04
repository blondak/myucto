<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * „Uložit nastavení do všech firem" v AI extrakci: rozkopíruje klíč, model a volbu
 * brány aktuální firmy do dalších firem uživatele.
 *
 * Cílové firmy počítá výhradně server. Kandidáti jsou firmy z přepínače (superadmin
 * všechny, ostatní jen přiřazené) a každá zvlášť projde stejnou resolucí jako běžný
 * požadavek do té firmy: membership, per-firemní role, vázaný token i zamčená doména.
 * Zapisuje se jen tam, kde výsledná role smí `settings.ai_provider` zapisovat.
 *
 * Klíč se nekopíruje jako šifrovaný blob: zdroj se dešifruje a každá firma dostane
 * vlastní šifrovanou kopii přes setCredentials klienta, stejně jako při ručním uložení.
 * Poznámky k extrakci jsou per firma a nekopírují se.
 */
final class AiCredentialsBulkApplier
{
    public const PERMISSION = 'settings.ai_provider';

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly AzureOpenAiClient $azure,
        private readonly OpenAiClient $openai,
        private readonly GeminiClient $gemini,
        private readonly OllamaClient $ollama,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $permissions,
        private readonly PermissionChecker $checker,
    ) {}

    /**
     * @return array{
     *     updated: list<array{id:int, name:string}>,
     *     skipped_configured: list<array{id:int, name:string}>,
     *     skipped_forbidden: list<array{id:int, name:string}>,
     *     skipped_constraint: list<array{id:int, name:string, reason:string}>
     * }
     */
    public function apply(
        Request $request,
        int $sourceSupplierId,
        string $provider,
        bool $onlyUnconfigured,
        ?string $ip = null,
        string $userAgent = '',
    ): array {
        $out = ['updated' => [], 'skipped_configured' => [], 'skipped_forbidden' => [], 'skipped_constraint' => []];

        $client = $this->clientFor($provider);
        $creds  = $client->getCredentials($sourceSupplierId);
        if ($creds === null) {
            throw new \DomainException('Aktuální firma nemá pro poskytovatele uložený klíč.');
        }
        $source       = $this->gatewayRow($sourceSupplierId);
        $sourceRegion = $client->capabilities($sourceSupplierId)->dataRegion;
        $hasEffort    = $this->db->hasColumn('supplier', 'ai_effort');

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $residency = new ResidencyPolicy();

        foreach ($this->candidateIds($request, $userId) as $targetId) {
            if ($targetId === $sourceSupplierId) continue;
            // Firma, do které se uživatel v tomhle kontextu nepřepne (zamčená doména,
            // vázaný token), se nevypisuje ani jménem — v přepínači ji taky nevidí.
            $role = $this->scopedRole($request, $targetId);
            if ($role === null) continue;
            $target = $this->gatewayRow($targetId);
            if ($target === null) continue;
            $entry = ['id' => $targetId, 'name' => $target['name']];

            if (!$role->isActive || $role->isClientType()
                || !$this->checker->allows($role, self::PERMISSION, AccessLevel::WRITE)) {
                $out['skipped_forbidden'][] = $entry;
                continue;
            }

            // „Nastavená AI" = aktivní poskytovatel firmy má použitelný klíč — stejná
            // definice jako odznak „Aktivní" v UI.
            if ($onlyUnconfigured
                && $this->clientFor($target['ai_provider'])->getCredentials($targetId) !== null) {
                $out['skipped_configured'][] = $entry;
                continue;
            }

            // EU rezidence se nikdy nesnižuje: firma, která ji vyžaduje, ji vyžaduje dál.
            $euRequired = $source['ai_eu_residency_required'] || $target['ai_eu_residency_required'];
            if ($euRequired && strtolower($sourceRegion) !== 'eu') {
                $out['skipped_constraint'][] = $entry + ['reason' => 'eu_residency'];
                continue;
            }

            // Každá firma atomicky; uvnitř cizí transakce přes savepoint, aby šlo
            // vrátit jen tuhle firmu.
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            $ownsTransaction ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ai_bulk_target');
            $rollback = static function () use ($pdo, $ownsTransaction): void {
                if ($ownsTransaction) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                } elseif ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ai_bulk_target');
                }
            };
            try {
                $this->persist($provider, $targetId, $creds);
                $sets   = ['ai_provider = ?', 'ai_data_region = ?', 'ai_eu_residency_required = ?'];
                $params = [$provider, $source['ai_data_region'], (int) $euRequired];
                if ($hasEffort) {
                    $sets[]   = 'ai_effort = ?';
                    $params[] = $source['ai_effort'];
                }
                $params[] = $targetId;
                $pdo->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

                // Pojistka po zápisu: region se počítá z uložené konfigurace cílové firmy.
                $residency->assertAllowed($provider, $client->capabilities($targetId)->dataRegion, $euRequired);

                $this->logger->log(
                    'import.ai_credentials_set',
                    $userId,
                    'supplier',
                    $targetId,
                    ['provider' => $provider, 'bulk_source_supplier_id' => $sourceSupplierId],
                    $ip,
                    $userAgent,
                    $targetId,
                );
                $ownsTransaction ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ai_bulk_target');
            } catch (ResidencyViolationException) {
                $rollback();
                $out['skipped_constraint'][] = $entry + ['reason' => 'eu_residency'];
                continue;
            } catch (\Throwable $e) {
                $rollback();
                throw $e;
            }
            $out['updated'][] = $entry;
        }

        return $out;
    }

    /** @return list<int> */
    private function candidateIds(Request $request, int $userId): array
    {
        if ($userId <= 0) return [];
        if (RequestAuthorization::isSuperadmin($request)) {
            return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $this->memberships->allowedSupplierIds($userId);
    }

    /**
     * Stejná resoluce jako požadavek přepnutý do cílové firmy (vzor GroupDashboardAccess):
     * zamčená doména nebo vázaný token přesměrování odmítnou, per-firemní role se
     * dopočítá z user_suppliers.
     */
    private function scopedRole(Request $request, int $targetId): ?EffectiveRole
    {
        $scoped = $request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $targetId)->withQueryParams([]);
        $access = $this->supplierAccess->resolve($scoped);
        if ($access->denied || $access->supplierId !== $targetId) return null;
        return $this->permissions->resolve($scoped);
    }

    /** @param array<string,string> $creds */
    private function persist(string $provider, int $supplierId, array $creds): void
    {
        match ($provider) {
            'anthropic'    => $this->anthropic->setCredentials($supplierId, $creds['api_key'], $creds['default_model'] ?? null),
            'azure_openai' => $this->azure->setCredentials(
                $supplierId, $creds['api_key'], $creds['endpoint'] ?? null, $creds['deployment'] ?? null, $creds['api_version'] ?? null,
            ),
            'openai'       => $this->openai->setCredentials($supplierId, $creds['api_key'], $creds['default_model'] ?? null, $creds['base_url'] ?? null),
            'gemini'       => $this->gemini->setCredentials($supplierId, $creds['api_key'], $creds['default_model'] ?? null),
            'ollama'       => $this->ollama->setCredentials($supplierId, $creds['base_url'], $creds['default_model'], $creds['api_key']),
        };
    }

    private function clientFor(string $provider): LlmGatewayInterface
    {
        return match ($provider) {
            'azure_openai' => $this->azure,
            'openai'       => $this->openai,
            'gemini'       => $this->gemini,
            'ollama'       => $this->ollama,
            default        => $this->anthropic,
        };
    }

    /** @return array{name:string, ai_provider:string, ai_data_region:string, ai_eu_residency_required:bool, ai_effort:string}|null */
    private function gatewayRow(int $supplierId): ?array
    {
        $effort = $this->db->hasColumn('supplier', 'ai_effort') ? 'ai_effort' : 'NULL AS ai_effort';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(NULLIF(display_name, ''), company_name) AS name,
                    ai_provider, ai_data_region, ai_eu_residency_required, {$effort}
               FROM supplier WHERE id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $effortValue = (string) ($row['ai_effort'] ?? '');
        return [
            'name'                     => (string) $row['name'],
            'ai_provider'              => (string) ($row['ai_provider'] ?: 'anthropic'),
            'ai_data_region'           => (string) ($row['ai_data_region'] ?: 'us'),
            'ai_eu_residency_required' => (bool) $row['ai_eu_residency_required'],
            'ai_effort'                => in_array($effortValue, LlmProviderCapabilities::EFFORTS, true)
                ? $effortValue
                : LlmProviderCapabilities::EFFORT_DEFAULT,
        ];
    }
}
