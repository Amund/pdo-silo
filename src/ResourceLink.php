<?php

declare(strict_types=1);

namespace Silo;

use PDO;

/**
 * Manages links between resources.
 *
 * This class is used internally by Silo and has no cache awareness.
 */
class ResourceLink
{
    private PDO $pdo;
    private string $prefix;
    private string $driver;

    /** @var array<string, \PDOStatement> */
    private array $stmt = [];
    private Store $store;

    /** @var array<string, string> */
    private static array $sql = [
        'replace-link'     => 'REPLACE INTO PREFIX_link ( id_parent, id_child, attribute, position ) VALUES ( ?, ?, ?, -1 )',
        'delete-link'      => 'DELETE FROM PREFIX_link WHERE id_parent=? AND id_child=?',
        'delete-link-from' => 'DELETE FROM PREFIX_link WHERE id_parent=?',
        'delete-link-to'   => 'DELETE FROM PREFIX_link WHERE id_child=?',

        'select-children'  => 'SELECT attribute, id_child FROM PREFIX_link WHERE id_parent=? AND position = -1',
        'select-parents'   => 'SELECT attribute, id_parent FROM PREFIX_link WHERE id_child=? AND position = -1',
    ];

    /** @var array<string, string> */
    private static array $pgsqlReplacements = [
        'replace-link' => 'INSERT INTO PREFIX_link ( id_parent, id_child, attribute, position ) VALUES ( ?, ?, ?, -1 ) ON CONFLICT ( id_parent, id_child, attribute, position ) DO UPDATE SET attribute = EXCLUDED.attribute',
    ];

    public function __construct(PDO $pdo, string $prefix, Store $store)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->store = $store;
    }

    /**
     * Create a link between two resources.
     *
     * @return bool True on success, false if either resource does not exist.
     */
    public function link(int $from, int $to, ?string $attribute = null): bool
    {
        $fromMeta = $this->store->getMeta($from);
        $toMeta = $this->store->getMeta($to);

        if ($fromMeta === null || $toMeta === null) {
            return false;
        }

        if ($attribute === null || $attribute === '') {
            $attribute = $toMeta['class'];
        }

        $stmt = $this->prepare('replace-link');
        $stmt->execute([$fromMeta['id'], $toMeta['id'], $attribute]);
        return true;
    }

    /**
     * Remove links between resources.
     */
    public function unlink(mixed $from = null, mixed $to = null): bool
    {
        $nbArgs = func_num_args();
        switch ($nbArgs) {
            case 1:
                [$id] = func_get_args();
                $this->unlink($id, null);
                $this->unlink(null, $id);
                return true;
            case 2:
                [$from, $to] = func_get_args();
                $emptyFrom = empty($from);
                $emptyTo = empty($to);

                if ($emptyFrom && $emptyTo) {
                    return true;
                }

                if ($emptyFrom) {
                    $stmt = $this->prepare('delete-link-to');
                    $stmt->execute([$to]);
                    return true;
                }

                if ($emptyTo) {
                    $stmt = $this->prepare('delete-link-from');
                    $stmt->execute([$from]);
                    return true;
                }

                $stmt = $this->prepare('delete-link');
                $stmt->execute([$from, $to]);
                return true;
            default:
                throw new \BadMethodCallException('Bad arguments count');
        }
    }

    /**
     * Get all links originating from $id (child links), grouped by attribute.
     *
     * @param ?callable $resolve Callable(int $id): mixed to resolve linked resource ids.
     * @return array<string, int[]|mixed[]> Attribute-keyed groups of ids (or resolved values).
     */
    public function from(int $id, ?callable $resolve = null): array
    {
        $stmt = $this->prepare('select-children');
        $stmt->execute([$id]);
        $data = [];
        while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $childId = (int) $d['id_child'];
            $data[$d['attribute']][] = $resolve !== null ? $resolve($childId) : $childId;
        }
        return $data;
    }

    /**
     * Get all links pointing to $id (parent links), grouped by attribute.
     *
     * @param ?callable $resolve Callable(int $id): mixed to resolve linked resource ids.
     * @return array<string, int[]|mixed[]> Attribute-keyed groups of ids (or resolved values).
     */
    public function to(int $id, ?callable $resolve = null): array
    {
        $stmt = $this->prepare('select-parents');
        $stmt->execute([$id]);
        $data = [];
        while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parentId = (int) $d['id_parent'];
            $data[$d['attribute']][] = $resolve !== null ? $resolve($parentId) : $parentId;
        }
        return $data;
    }

    private function prepare(string $stmtName): \PDOStatement
    {
        if (!isset($this->stmt[$stmtName])) {
            $sql = self::$sql[$stmtName];

            if ($this->driver === 'pgsql' && isset(self::$pgsqlReplacements[$stmtName])) {
                $sql = self::$pgsqlReplacements[$stmtName];
            }

            $sql = str_replace('PREFIX', $this->prefix, $sql);
            $this->stmt[$stmtName] = $this->pdo->prepare($sql);
        }
        return $this->stmt[$stmtName];
    }
}
