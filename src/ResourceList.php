<?php

declare(strict_types=1);

namespace Silo;

use PDO;

class ResourceList
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    /** @return int[] */
    public function get(int $parent, string $attribute): array
    {
        $table = $this->prefix . '_link';
        $stmt = $this->pdo->prepare("SELECT id_child FROM {$table} WHERE id_parent=? AND attribute=? AND position >= 0 ORDER BY position");
        $stmt->execute([$parent, $attribute]);
        $ids = [];
        while ($id = $stmt->fetch(PDO::FETCH_COLUMN)) {
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /** @param int[] $children */
    public function set(int $parent, string $attribute, array $children): void
    {
        $table = $this->prefix . '_link';
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id_parent=? AND attribute=? AND position >= 0");
        $stmt->execute([$parent, $attribute]);

        if ($children === []) {
            return;
        }

        $stmt = $this->pdo->prepare("INSERT INTO {$table} (id_parent, id_child, attribute, position) VALUES (?, ?, ?, ?)");
        foreach ($children as $position => $childId) {
            $stmt->execute([$parent, $childId, $attribute, $position]);
        }
    }

    /**
     * Get all list children of $parent, grouped by attribute, ordered by position.
     *
     * @param ?callable $resolve Callable(int $id): mixed to resolve child ids.
     * @return array<string, int[]|mixed[]>
     */
    public function from(int $parent, ?callable $resolve = null): array
    {
        $table = $this->prefix . '_link';
        $stmt = $this->pdo->prepare("SELECT attribute, id_child FROM {$table} WHERE id_parent=? AND position >= 0 ORDER BY position");
        $stmt->execute([$parent]);
        $data = [];
        while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $childId = (int) $d['id_child'];
            $data[$d['attribute']][] = $resolve !== null ? $resolve($childId) : $childId;
        }
        return $data;
    }

    /**
     * Get all list parents referencing $child, grouped by attribute.
     *
     * @param ?callable $resolve Callable(int $id): mixed to resolve parent ids.
     * @return array<string, int[]|mixed[]>
     */
    public function to(int $child, ?callable $resolve = null): array
    {
        $table = $this->prefix . '_link';
        $stmt = $this->pdo->prepare("SELECT attribute, id_parent FROM {$table} WHERE id_child=? AND position >= 0 ORDER BY position");
        $stmt->execute([$child]);
        $data = [];
        while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parentId = (int) $d['id_parent'];
            $data[$d['attribute']][] = $resolve !== null ? $resolve($parentId) : $parentId;
        }
        return $data;
    }
}
