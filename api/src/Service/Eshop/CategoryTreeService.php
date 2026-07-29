<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockCategoryRepository;

/**
 * Strom kategorií zboží s materialized path (Epic ESHOP).
 *
 * Path formát: '/{id}/' pro kořen, '/{parentPath...}{id}/' pro potomka; depth =
 * počet '/' − 2. Materialized path drží breadcrumbs a subtree bez rekurze
 * (path LIKE '/12/%'). Insert je dvoufázový (potřebujeme id pro path). Move
 * přepíše prefix path celého podstromu jedním dotazem a přepočte depth; cyklus
 * (přesun pod vlastního potomka) je odmítnut. Vše v transakci — tenant-scoped.
 */
final class CategoryTreeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockCategoryRepository $categories,
    ) {}

    /**
     * @param array{parent_id?:?int, code:string, name:string, display_order?:int,
     *              export_eshop?:bool, archived?:bool} $data
     * @return array<string,mixed> vytvořená kategorie
     */
    public function create(int $supplierId, array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50) {
            throw new EshopException('validation_failed', 'Kód kategorie je povinný (max 50 znaků).', 400);
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new EshopException('validation_failed', 'Název kategorie je povinný (max 150 znaků).', 400);
        }
        if ($this->categories->findByCode($supplierId, $code) !== null) {
            throw new EshopException('category_code_taken', 'Kategorie s tímto kódem už existuje.', 409);
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        [$parentPath, $depth] = $this->parentContext($supplierId, $parentId);

        return $this->tx(function () use ($supplierId, $data, $parentId, $parentPath, $depth, $code, $name): array {
            $id = $this->categories->insert($supplierId, [
                'parent_id'     => $parentId,
                'code'          => $code,
                'name'          => $name,
                'path'          => $parentPath, // dočasná; finalizujeme po zjištění id
                'depth'         => $depth,
                'display_order' => (int) ($data['display_order'] ?? 0),
                'export_eshop'  => (bool) ($data['export_eshop'] ?? true),
                'archived'      => (bool) ($data['archived'] ?? false),
            ]);
            $this->categories->setPathDepth($supplierId, $id, $parentPath . $id . '/', $depth);
            return $this->categories->find($supplierId, $id) ?? [];
        });
    }

    /**
     * @param array{code:string, name:string, display_order?:int, export_eshop?:bool, archived?:bool} $data
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $data): array
    {
        $existing = $this->categories->find($supplierId, $id);
        if ($existing === null) {
            throw new EshopException('not_found', 'Kategorie nenalezena.', 404);
        }
        $code = trim((string) ($data['code'] ?? $existing['code']));
        $name = trim((string) ($data['name'] ?? $existing['name']));
        if ($code === '' || mb_strlen($code) > 50) {
            throw new EshopException('validation_failed', 'Kód kategorie je povinný (max 50 znaků).', 400);
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new EshopException('validation_failed', 'Název kategorie je povinný (max 150 znaků).', 400);
        }
        $byCode = $this->categories->findByCode($supplierId, $code);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            throw new EshopException('category_code_taken', 'Kategorie s tímto kódem už existuje.', 409);
        }
        $this->categories->updateFields($supplierId, $id, [
            'code'          => $code,
            'name'          => $name,
            'display_order' => (int) ($data['display_order'] ?? $existing['display_order']),
            'export_eshop'  => array_key_exists('export_eshop', $data) ? (bool) $data['export_eshop'] : (bool) $existing['export_eshop'],
            'archived'      => array_key_exists('archived', $data) ? (bool) $data['archived'] : (bool) $existing['archived'],
        ]);
        return $this->categories->find($supplierId, $id) ?? [];
    }

    /**
     * Přesun uzlu pod nového rodiče (nebo na kořen při $newParentId=null).
     * Odmítne cyklus (nový rodič = uzel nebo jeho potomek). Přepíše path+depth
     * celého podstromu jedním dotazem.
     * @return array<string,mixed>
     */
    public function move(int $supplierId, int $id, ?int $newParentId): array
    {
        $node = $this->categories->find($supplierId, $id);
        if ($node === null) {
            throw new EshopException('not_found', 'Kategorie nenalezena.', 404);
        }
        if ($newParentId !== null && $newParentId === $id) {
            throw new EshopException('category_cycle', 'Kategorii nelze vnořit do sebe sama.', 422);
        }

        $oldPrefix = (string) $node['path'];           // '/12/45/'
        [$parentPath, $childDepth] = $this->parentContext($supplierId, $newParentId); // childDepth = nová depth uzlu

        // Cyklus: nový rodič nesmí ležet v podstromu uzlu (jeho path začíná oldPrefix).
        if ($newParentId !== null) {
            $parent = $this->categories->find($supplierId, $newParentId);
            if ($parent !== null && str_starts_with((string) $parent['path'], $oldPrefix)) {
                throw new EshopException('category_cycle', 'Kategorii nelze přesunout pod vlastní podkategorii.', 422);
            }
        }

        $newPrefix = $parentPath . $id . '/';          // '/99/45/'
        if ($newPrefix === $oldPrefix) {
            return $node; // beze změny
        }
        // childDepth už JE cílová depth uzlu (parentContext vrací parent.depth+1, root→0).
        $depthDelta = $childDepth - (int) $node['depth'];

        return $this->tx(function () use ($supplierId, $id, $newParentId, $oldPrefix, $newPrefix, $depthDelta): array {
            $this->categories->repathSubtree($supplierId, $oldPrefix, $newPrefix, $depthDelta);
            $this->categories->updateParent($supplierId, $id, $newParentId);
            return $this->categories->find($supplierId, $id) ?? [];
        });
    }

    public function delete(int $supplierId, int $id): void
    {
        $node = $this->categories->find($supplierId, $id);
        if ($node === null) {
            throw new EshopException('not_found', 'Kategorie nenalezena.', 404);
        }
        if ($this->categories->hasChildren($supplierId, $id)) {
            throw new EshopException('category_has_children', 'Kategorii nelze smazat — má podkategorie. Nejdřív je přesuňte nebo smažte.', 409);
        }
        if ($this->categories->isReferenced($supplierId, $id)) {
            throw new EshopException('category_in_use', 'Kategorii nelze smazat — je přiřazena ke zboží.', 409);
        }
        $this->categories->delete($supplierId, $id);
    }

    /**
     * Kontext rodiče: [parentPath, childDepth]. Root → ['/', 0].
     * @return array{0:string, 1:int}
     */
    private function parentContext(int $supplierId, ?int $parentId): array
    {
        if ($parentId === null) {
            return ['/', 0];
        }
        $parent = $this->categories->find($supplierId, $parentId);
        if ($parent === null) {
            throw new EshopException('parent_not_found', 'Nadřazená kategorie nenalezena.', 422);
        }
        return [(string) $parent['path'], (int) $parent['depth'] + 1];
    }

    /**
     * Transakční obal (reentrant — pod běžící tx neotvírá vlastní).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
